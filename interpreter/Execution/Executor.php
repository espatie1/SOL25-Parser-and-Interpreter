<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Execution;

// Core dependencies
use IPP\Core\Interface\InputReader;
use IPP\Core\Interface\OutputWriter;
// Frame components
use IPP\Student\Runtime\FrameStack;
use IPP\Student\Runtime\Frame;
// Objects
use IPP\Student\Objects\ClassDefinition;
use IPP\Student\Objects\UserDefinedObject;
use IPP\Student\Objects\AbstractSolObject;
use IPP\Student\Objects\SolInteger;
use IPP\Student\Objects\SolString;
use IPP\Student\Objects\SolNil;
use IPP\Student\Objects\SolTrue;
use IPP\Student\Objects\SolFalse;
use IPP\Student\Objects\SolBlock;
// Exceptions
use IPP\Student\Exceptions\UndefinedVariableException; // Error 32 (runtime check)
use IPP\Student\Exceptions\ValueErrorException; // Error 53
use IPP\Student\Exceptions\DoNotUnderstandException; // Error 51
use IPP\Core\Exception\NotImplementedException; // For parts not yet done
use IPP\Student\Exceptions\EmptyStackException; // For runtime state errors
use IPP\Student\Exceptions\SemanticErrorException;
use IPP\Student\Exceptions\InterpretRuntimeException;
use RuntimeException; // For internal logic errors/unexpected XML

// DOM
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use DOMNode;

/**
 * Executor — core runtime engine for SOL25.
 *
 * Responsibilities
 * - keeps the call stack (FrameStack / Frame);
 * - walks the XML AST and evaluates expressions;
 * - dispatches messages, honouring self / super semantics;
 * - invokes built-in sol* routines and user-defined blocks;
 * - proxies I/O through IPP\Core\InputReader / OutputWriter.
 *
 * High-level call flow
 *   runProgram()
 *     --> executeUserMethod( Main.run )
 *         --> executeBlockWithArgs()
 *             --> executeBlock() --> executeAssign()
 *                 --> evaluateExpr()
 *                     --> executeSend() --> dispatchMessage()
 *
 * Every public entry returns a SOL25 object.
 * Language errors are surfaced via DoNotUnderstand, ValueError, etc.
 */
class Executor
{
    private FrameStack $frameStack;
    private ClassRegistry $classRegistry;
    private InputReader $input;
    private OutputWriter $stdout;
    private ?DOMXPath $xpath = null;

    /**
     * Constructor to inject dependencies.
     * @param FrameStack $frameStack Manages execution frames.
     * @param ClassRegistry $classRegistry Stores class definitions.
     * @param InputReader $input Reads program input.
     * @param OutputWriter $stdout Writes program output.
     */
    public function __construct(
        FrameStack $frameStack,
        ClassRegistry $classRegistry,
        InputReader $input,
        OutputWriter $stdout
    ) {
        $this->frameStack = $frameStack;
        $this->classRegistry = $classRegistry;
        $this->input = $input;
        $this->stdout = $stdout;
    }

    /**
     * Initializes the XPath helper. Should be called before extensive DOM traversal.
     * Typically called once by the method that starts the execution (e.g., runProgram or Interpreter::execute).
     * @param DOMDocument $dom The main DOM document of the program.
     */
    private function initXPath(DOMDocument $dom): void
    {
        if ($this->xpath === null) {
            $this->xpath = new DOMXPath($dom);
        }
    }

    //======================================================================
    // PART 1 – Expression evaluation
    //----------------------------------------------------------------------
    // <expr>  → literal | var | block | send
    // evaluateExpr() — entry point, delegates to:
    //   • evaluateLiteral()       — Integer / String / nil / true / …
    //   • evaluateVariable()      — variables + keywords self / super
    //   • evaluateBlockLiteral()  — creates SolBlock with lexical self
    //   • executeSend()           — forwards to dispatchMessage()
    //   • evaluateArguments()     — orders <arg> by @order and evaluates
    //======================================================================

    /**
     * Evaluates an <expr> node and returns the resulting SOL object.
     * This is the main entry point for evaluating any expression part of the AST.
     * @param DOMElement $exprNode The <expr> DOMElement.
     * @return AbstractSolObject|string The resulting SOL object, or the string name of a class
     * if the expression is a class literal (used for class messages).
     * @throws RuntimeException If the <expr> node does not contain a valid child expression node.
     * @throws NotImplementedException If evaluation for a specific expression type (e.g., <send>) is not implemented.
     * @throws \IPP\Student\Exceptions\InterpretRuntimeException For runtime errors during evaluation.
     */
    public function evaluateExpr(DOMElement $exprNode): AbstractSolObject|string
    {
        // Find the first element child within <expr> which represents the actual expression
        $expressionChild = null;
        /** @var \DOMNode $node */
        foreach ($exprNode->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $expressionChild = $node;
                break;
            }
        }

        // If no element child is found, the XML structure is invalid
        if ($expressionChild === null) {
            throw new RuntimeException("Invalid <expr> node: missing child element representing the expression.");
        }

        // Evaluate based on the child node type
        return match ($expressionChild->nodeName) {
            'literal' => $this->evaluateLiteral($expressionChild),
            'var' => $this->evaluateVariable($expressionChild),
            'block' => $this->evaluateBlockLiteral($expressionChild),
            'send' => $this->executeSend($expressionChild),
            default => throw new RuntimeException(
                "Unexpected node type '{$expressionChild->nodeName}' found inside <expr>."
            )
        };
    }

    /**
     * Evaluates a <literal> node and returns the corresponding SOL object instance.
     * Handles integer, string, nil, true, false, and class literals.
     * @param DOMElement $literalNode The <literal> DOMElement.
     * @return AbstractSolObject|string The Sol* object representing the literal value.
     * @throws RuntimeException If the 'class' attribute is missing or invalid, or 'value' is missing where expected.
     */
    private function evaluateLiteral(DOMElement $literalNode): AbstractSolObject|string
    {
        if (!$literalNode->hasAttribute('class')) {
            throw new RuntimeException("Literal node is missing 'class' attribute.");
        }
        $literalClass = $literalNode->getAttribute('class');

        if ($literalClass === 'Nil') {
            return SolNil::instance();
        }
        if ($literalClass === 'True') {
            return SolTrue::instance();
        }
        if ($literalClass === 'False') {
            return SolFalse::instance();
        }
        if ($literalClass === 'class') {
            if (!$literalNode->hasAttribute('value')) {
                throw new RuntimeException("Literal node for class='class' is missing 'value' attribute.");
            }
            return $literalNode->getAttribute('value');
        }
        if (!$literalNode->hasAttribute('value')) {
            throw new RuntimeException("Literal node for class '{$literalClass}' is missing 'value' attribute.");
        }
        $literalValueAttr = $literalNode->getAttribute('value');
        return match ($literalClass) {
            'Integer' => SolInteger::fromString($literalValueAttr),
            'String' => new SolString($literalValueAttr),
            default => throw new RuntimeException("Invalid or unexpected literal class type: '{$literalClass}'.")
        };
    }

     /**
     * Evaluates a <var> node by looking up the variable/parameter/special name
     * ('nil', 'true', 'false', 'self', 'super') in the current execution context (frame).
     * @param DOMElement $varNode The <var> DOMElement.
     * @return AbstractSolObject The Sol* object referenced by the variable name.
     * @throws UndefinedVariableException If the variable/parameter name is not defined (Error 32 runtime check).
     * @throws RuntimeException If trying to access 'self' or 'super' outside a valid context (e.g., empty stack).
     */
    private function evaluateVariable(DOMElement $varNode): AbstractSolObject
    {
        if (!$varNode->hasAttribute('name')) {
             throw new RuntimeException("Variable node is missing 'name' attribute.");
        }
        $varName = $varNode->getAttribute('name');
        switch ($varName) {
            case 'nil':
                return SolNil::instance();
            case 'true':
                return SolTrue::instance();
            case 'false':
                return SolFalse::instance();
        }
        try {
            $currentFrame = $this->frameStack->getCurrentFrame();
        } catch (EmptyStackException $e) {
            throw new RuntimeException("Cannot evaluate variable '{$varName}': Execution stack is empty.", 0, $e);
        }
        if ($varName === 'self' || $varName === 'super') {
            $selfObj = $currentFrame->getSelf();
            if ($selfObj === null) {
                 throw new RuntimeException(
                     "Cannot evaluate '{$varName}': 'self' is not defined in the current context."
                 );
            }
            return $selfObj;
        }
        return $currentFrame->getVariable($varName);
    }

    /**
     * Evaluates a <block> node when it appears as a literal within an <expr>.
     * Creates and returns a SolBlock object, capturing the current 'self' from the defining context.
     * @param DOMElement $blockNode The <block> DOMElement.
     * @return SolBlock The created SolBlock object representing the unevaluated block.
     * @throws RuntimeException If the 'arity' attribute is missing or invalid, or if 'self' cannot be determined.
     */
    private function evaluateBlockLiteral(DOMElement $blockNode): SolBlock
    {
        if (!$blockNode->hasAttribute('arity')) {
             throw new RuntimeException("Block literal node is missing 'arity' attribute.");
        }
        $arityAttr = $blockNode->getAttribute('arity');
        // filter_var handles potential non-numeric values gracefully
        $arity = filter_var($arityAttr, FILTER_VALIDATE_INT);
        // Arity must be a non-negative integer
        if ($arity === false || $arity < 0) {
             throw new RuntimeException(
                 "Invalid non-negative integer 'arity' attribute value '{$arityAttr}' for block literal."
             );
        }
        // Capture the 'self' object reference from the defining context's frame
        try {
            $currentFrame = $this->frameStack->getCurrentFrame();
            $definingSelf = $currentFrame->getSelf();
        } catch (EmptyStackException $e) {
             throw new RuntimeException("Cannot evaluate block literal: Execution stack is empty.", 0, $e);
        }
        // Create the SolBlock object, storing the node, arity, and the captured 'self'
        return new SolBlock($blockNode, $arity, $definingSelf);
    }

    /**
     * Evaluates all <arg> elements provided as an array.
     * @param array<int, DOMElement> $argElements
     * @return list<AbstractSolObject> Array of evaluated argument objects.
     * @throws RuntimeException For XML structure errors or invalid 'order'.
     * @throws \IPP\Student\Exceptions\InterpretRuntimeException For errors during argument expression evaluation.
     */
    private function evaluateArguments(array $argElements): array
    {
        if ($this->xpath === null) {
            throw new RuntimeException("XPath helper not initialized before calling " . __METHOD__);
        }

        $argsWithOrder = [];

        foreach ($argElements as $node) {
            if ($node->hasAttribute('order')) {
                $order = filter_var($node->getAttribute('order'), FILTER_VALIDATE_INT);
                if ($order !== false && $order > 0) {
                    if (isset($argsWithOrder[$order])) {
                        throw new RuntimeException("Duplicate order '{$order}' found for argument nodes.");
                    }
                    $argsWithOrder[$order] = $node;
                } else {
                    throw new RuntimeException("Invalid or missing positive integer 'order' attribute on arg node.");
                }
            } else {
                throw new RuntimeException("Argument node missing 'order' attribute.");
            }
        }
        ksort($argsWithOrder, SORT_NUMERIC);

        $evaluatedArgs = [];
        foreach ($argsWithOrder as $order => $argNode) {
            $exprNodeList = $this->xpath->query('./expr[1]', $argNode);
            if ($exprNodeList === false) {
                throw new RuntimeException("Failed to query <expr> inside <arg> node (order {$order}).");
            }
            $exprNode = $exprNodeList->item(0);
            if (!$exprNode instanceof DOMElement) {
                throw new RuntimeException("Invalid <arg> node structure: missing <expr> child for order {$order}.");
            }
            $result = $this->evaluateExpr($exprNode);
            if (!$result instanceof AbstractSolObject) {
                throw new RuntimeException(
                    "Expected expression to evaluate to AbstractSolObject, got " . gettype($result)
                );
            }
            $evaluatedArgs[] = $result;
        }

        return $evaluatedArgs;
    }

    //======================================================================
    // PART 2 – Block and assignment execution
    //----------------------------------------------------------------------
    // executeBlockWithArgs()  — push Frame(self+args) -> executeBlock() -> pop
    // executeBlock()          — order <assign> -> executeAssign()
    // executeAssign()         — evaluateExpr() -> Frame::setVariable()
    //======================================================================

    /**
     * Executes the sequence of assignment statements within a given <block> node.
     * This is called AFTER a frame for the block has been pushed onto the stack.
     * It assumes the current frame in $this->frameStack corresponds to this block execution.
     * @param DOMElement $blockNode The <block> DOMElement containing <assign> children.
     * @return AbstractSolObject Result of the block (value of the last executed expression, or nil if no assignments).
     * @throws \IPP\Student\Exceptions\InterpretRuntimeException For runtime errors during assignment execution.
     * @throws RuntimeException For internal errors or invalid XML structure.
     */
    public function executeBlock(DOMElement $blockNode): AbstractSolObject
    {
        if ($this->xpath === null) {
             throw new RuntimeException("XPath helper not initialized before calling " . __METHOD__);
        }

        // Find and sort assignment nodes by 'order'
        $assignments = [];
        $assignNodes = $this->xpath->query('./assign', $blockNode); // Find <assign> children
        if ($assignNodes === false) {
             throw new RuntimeException("Failed to query assignment nodes within block.");
        }
        /** @var \DOMNode $node */
        foreach ($assignNodes as $node) {
            if ($node instanceof DOMElement && $node->hasAttribute('order')) {
                $order = filter_var($node->getAttribute('order'), FILTER_VALIDATE_INT);
                if ($order !== false && $order > 0) {
                    if (isset($assignments[$order])) {
                        throw new RuntimeException(
                            "Duplicate order '{$order}' found for assignment statements in block."
                        );
                    }
                    $assignments[$order] = $node;
                } else {
                     throw new RuntimeException(
                         "Invalid or missing positive integer 'order' attribute on assign node."
                     );
                }
            }
        }
        ksort($assignments, SORT_NUMERIC); // Sort assignments by order number

        // Execute assignments in order
        $lastResult = SolNil::instance(); // Default result if no assignments
        foreach ($assignments as $assignNode) {
            $lastResult = $this->executeAssign($assignNode); // Execute and update last result
        }

        // Return the result of the last executed assignment (or nil)
        return $lastResult;
    }

    /**
     * Executes a single <assign> statement.
     * Evaluates the expression and assigns the result to the variable in the current frame.
     * @param DOMElement $assignNode The <assign> DOMElement.
     * @return AbstractSolObject The result of the evaluated expression (the value assigned).
     * @throws RuntimeException For invalid XML structure within <assign>.
     * @throws \IPP\Student\Exceptions\InterpretRuntimeException For runtime errors.
     */
    private function executeAssign(DOMElement $assignNode): AbstractSolObject
    {
        if ($this->xpath === null) {
             throw new RuntimeException("XPath helper not initialized before calling " . __METHOD__);
        }

        // Find child <var> and <expr> nodes
        $varNodeList = $this->xpath->query('./var[1]', $assignNode);
        $exprNodeList = $this->xpath->query('./expr[1]', $assignNode);

        if ($varNodeList === false || $exprNodeList === false) {
            throw new RuntimeException("Failed to query <var> or <expr> inside <assign>.");
        }

        $varNode = $varNodeList->item(0);
        $exprNode = $exprNodeList->item(0);


        if (!$varNode instanceof DOMElement || !$exprNode instanceof DOMElement) {
            throw new RuntimeException("Invalid <assign> node structure: missing <var> or <expr> child.");
        }
        if (!$varNode->hasAttribute('name')) {
            throw new RuntimeException("<var> node inside <assign> is missing 'name' attribute.");
        }
        $varName = $varNode->getAttribute('name');
        // Evaluate the expression
        $resultValue = $this->evaluateExpr($exprNode);
        if (!$resultValue instanceof AbstractSolObject) {
            throw new RuntimeException(
                "Cannot assign value of type " . gettype($resultValue) . " to variable '{$varName}'"
            );
        }

        // Get current frame and set the variable
        $currentFrame = $this->frameStack->getCurrentFrame();
        $currentFrame->setVariable($varName, $resultValue);

        return $resultValue;
    }

    /**
     * Executes a block specified by its DOM node, captured 'self', and arguments.
     * This is the core primitive for running any block's code (method bodies, block literals).
     * Handles frame setup, argument binding, execution, and frame teardown.
     * @param DOMElement $blockNode The <block> node to execute.
     * @param AbstractSolObject|null $definingSelf The 'self' captured when the block was defined.
     * @param array<AbstractSolObject> $args Arguments to pass to the block parameters.
     * @return AbstractSolObject The result of the block's execution.
     * @throws DoNotUnderstandException If the number of arguments provided does not match the block's declared arity.
     * @throws \IPP\Student\Exceptions\InterpretRuntimeException For other runtime errors during execution.
     * @throws RuntimeException For internal errors or invalid XML structure.
     */
    public function executeBlockWithArgs(
        DOMElement $blockNode,
        ?AbstractSolObject $definingSelf,
        array $args
    ): AbstractSolObject {
        if ($this->xpath === null) {
            throw new RuntimeException("XPath helper not initialized before calling " . __METHOD__);
        }

        // Get parameters defined in the block AND SORT THEM BY 'order' attribute
        $paramNodes = $this->xpath->query('./parameter', $blockNode);
        if ($paramNodes === false) {
            throw new RuntimeException("Failed to query parameter nodes within block.");
        }
        $paramsWithOrder = [];
        /** @var \DOMNode $node */
        foreach ($paramNodes as $node) {
            if ($node instanceof DOMElement && $node->hasAttribute('name') && $node->hasAttribute('order')) {
                $name = $node->getAttribute('name');
                $order = filter_var($node->getAttribute('order'), FILTER_VALIDATE_INT);
                if ($order !== false && $order > 0) {
                    if (isset($paramsWithOrder[$order])) {
                        throw new RuntimeException("Duplicate order '{$order}' found for parameter nodes in block.");
                    }
                    // Store name keyed by order
                    $paramsWithOrder[$order] = $name;
                } else {
                    throw new RuntimeException(
                        "Invalid or missing positive integer 'order' attribute on parameter node '{$name}'."
                    );
                }
            } else {
                throw new RuntimeException("Invalid parameter node: missing 'name' or 'order' attribute.");
            }
        }
        // Sort parameters by order key
        ksort($paramsWithOrder, SORT_NUMERIC);
        $paramNames = array_values($paramsWithOrder);

        // Check arity
        $declaredArity = count($paramNames);
        $providedArity = count($args);
        if ($declaredArity !== $providedArity) {
            throw new DoNotUnderstandException(
                "Block",
                "value:" . str_repeat(":", $providedArity - 1)
            );
        }

        // Create and push new frame
        $newFrame = new Frame($definingSelf, $paramNames, $args);
        $this->frameStack->push($newFrame);

        // Execute block content within try/finally to ensure frame pop
        try {
            // Call executeBlock to run the sequence of assignments inside
            $blockResult = $this->executeBlock($blockNode);
        } finally {
            // Pop frame
            $this->frameStack->pop();
        }
        // Return result
        return $blockResult;
    }

    /**
     * Executes an argument that is expected to understand a 'value*' message
     * (typically a SolBlock, but could be other objects responding to 'value*').
     * Sends the appropriate 'value', 'value:', 'value:value:', etc., message
     * to the $valueArg object based on the number of $args provided.
     * @param AbstractSolObject $valueArg The object to send the 'value*' message to.
     * @param array<AbstractSolObject> $args The arguments for the 'value*' message.
     * @return AbstractSolObject The result of the 'value*' message execution.
     * @throws InterpretRuntimeException If dispatching 'value*' fails (e.g., DNU).
     */
    public function executeBlockArgument(AbstractSolObject $valueArg, array $args = []): AbstractSolObject
    {

        // 1. Determine the correct selector based on the number of arguments
        $numArgs = count($args);
        $selector = 'value'; // Base selector for 0 arguments
        if ($numArgs > 0) {
            $selector .= str_repeat(':', $numArgs); // Add colons for arguments (value:, value:value:, etc.)
        }
        return $this->dispatchMessage(
            $valueArg,  // Receiver is the argument itself
            $selector,  // Calculated selector ('value', 'value:', etc.)
            $args,      // Arguments to pass to the 'value*' message
            false      // isSuperSend is always false here
        );
    }

    //======================================================================
    // PART 3 – Message dispatch
    //----------------------------------------------------------------------
    // dispatchMessage() priority:
    //   1. class message (string receiver)       -> handleClassMessage()
    //   2. whileTrue: loop                       -> built-in shortcut
    //   3. SolBlock value*                       -> executeBlockWithArgs()
    //   4. user method   (supports super)        -> executeUserMethod()
    //   5. built-in sol* method                  -> executeBuiltinMethod()
    //   6. attribute get / set (name / name:)    -> handleAttributeAccess()
    //   7. otherwise                             -> DoNotUnderstandException
    //======================================================================

    /**
     * Evaluates a <send> node (message send). Finds receiver, evaluates arguments, and dispatches the message.
     * @param DOMElement $sendNode The <send> node.
     * @return AbstractSolObject The result returned by the message handler (method/attribute).
     * @throws RuntimeException For XML structure errors.
     * @throws \IPP\Student\Exceptions\InterpretRuntimeException For SOL25 runtime errors (51, 53, etc.).
     */
    public function executeSend(DOMElement $sendNode): AbstractSolObject
    {
        if ($this->xpath === null) {
            throw new RuntimeException("XPath helper not initialized before calling " . __METHOD__);
        }

        // Get Selector
        $selector = $sendNode->getAttribute('selector');
        if (empty($selector)) {
            throw new RuntimeException("<send> node missing 'selector' attribute.");
        }

        // Find and Evaluate Receiver Expression
        $receiverExprNodeList = $this->xpath->query('./expr[1]', $sendNode);
        if ($receiverExprNodeList === false) {
            throw new RuntimeException("Failed to query receiver <expr> in <send>.");
        }
        $receiverExprNode = $receiverExprNodeList->item(0);
        if (!$receiverExprNode instanceof DOMElement) {
            throw new RuntimeException("<send> node missing receiver <expr> child.");
        }

        $receiverResult = $this->evaluateExpr($receiverExprNode);

        // Determine if 'super' was used for the receiver
        $isSuperSend = false;
        if (
            $this->xpath->evaluate('count(./*)', $receiverExprNode) === 1.0 &&
            $this->xpath->evaluate('boolean(./var[@name="super"])', $receiverExprNode)
        ) {
            $isSuperSend = true;
        }
        // Find and Evaluate Arguments
        $argNodes = $this->xpath->query('./arg', $sendNode);
        if ($argNodes === false) {
            throw new RuntimeException("Failed to query argument nodes within <send>.");
        }

        // Convert DOMNodeList to an array of DOMElements
        $argElements = [];
        /** @var \DOMNode $node */
        foreach ($argNodes as $node) {
            if ($node instanceof DOMElement) {
                $argElements[] = $node;
            }
        }
        // Pass the array of elements
        $arguments = $this->evaluateArguments($argElements);
        // Dispatch the message
        $result = $this->dispatchMessage($receiverResult, $selector, $arguments, $isSuperSend);
        // Return result
        return $result;
    }

    /**
     * Central message dispatcher. Determines message type (class, attribute, built-in, user)
     * and calls the appropriate handler following the correct precedence (methods first, then attributes).
     * @param AbstractSolObject|string $receiver Receiver object or class name string.
     * @param string $selector Message selector.
     * @param array<AbstractSolObject> $arguments Evaluated arguments.
     * @param bool $isSuperSend True if the receiver was specified as 'super'.
     * @return AbstractSolObject Result of the message execution.
     * @throws DoNotUnderstandException If the receiver cannot handle the message (Error 51).
     * @throws ValueErrorException For argument type/value errors (Error 53).
     * @throws \IPP\Student\Exceptions\InterpretRuntimeException For other runtime errors.
     */
    public function dispatchMessage(
        $receiver,
        string $selector,
        array $arguments,
        bool $isSuperSend = false
    ): AbstractSolObject {
        // Class Message
        if (is_string($receiver)) {
            $className = $receiver;
            if ($isSuperSend) {
                throw new RuntimeException("'super' cannot be used to send class messages.");
            }
            return $this->handleClassMessage($className, $selector, $arguments);
        }

        if ($selector === 'whileTrue:') {
            if (count($arguments) !== 1) {
                 throw new DoNotUnderstandException(
                     $receiver->getSolClassName(),
                     "whileTrue: expects exactly 1 argument, got " . count($arguments)
                 );
            }

            $conditionObject = $receiver;
            $bodyObject = $arguments[0];

            while (true) {
                 $conditionResult = $this->executeBlockArgument($conditionObject);

                if ($conditionResult === SolTrue::instance()) {
                    $this->executeBlockArgument($bodyObject);
                } else {
                    break;
                }
            }
            return SolNil::instance();
        }

        if ($receiver instanceof SolBlock) {
            // Check if selector starts with 'value'
            if (str_starts_with($selector, 'value')) {
                // Calculate expected arity based on selector ('value' -> 0, 'value:' -> 1, 'value:value:' -> 2, etc.)
                $expectedArity = substr_count($selector, ':');
                $actualArity = $receiver->getArity();
                $numArgs = count($arguments);

                // Verify selector matches block arity AND provided arguments match arity
                if ($expectedArity === $actualArity && $actualArity === $numArgs) {
                    // Execute the block associated with the SolBlock instance
                    return $this->executeBlockWithArgs(
                        $receiver->getBlockNode(),
                        $receiver->getDefiningSelf(),
                        $arguments
                    );
                } else {
                    throw new DoNotUnderstandException(
                        $receiver->getSolClassName(),
                        $selector
                    );
                }
            }
            // If it's a SolBlock but selector is not 'value*', proceed to normal dispatch below
        }

        $receiverClass = $receiver->getSolClassName();
        $numArgs = count($arguments);

        // Try User-defined Method
        $startClassName = $receiverClass;
        if ($isSuperSend) {
            $classDef = $this->classRegistry->findClass($receiverClass);
            if ($classDef === null || $classDef->parentName === null) {
                throw new RuntimeException(
                    "'super' used in class '{$receiverClass}' which has no parent (or is Object)."
                );
            }
            $startClassName = $classDef->parentName;
        }

        // Search for the user-defined method block in the hierarchy
        $methodBlockNode = $this->classRegistry->findMethodBlock($startClassName, $selector);
        if ($methodBlockNode instanceof DOMElement) {
            return $this->executeUserMethod($receiver, $methodBlockNode, $arguments);
        }

        // Try Built-in Method (Only if User method was NOT found)
        $phpMethodName = $this->selectorToPhpMethod($selector);
        $foundBuiltin = false;

        // First, check the direct PHP class of the receiver
        if (method_exists($receiver, $phpMethodName)) {
            $foundBuiltin = true;
        } else {
            // If not on direct PHP class (e.g., receiver is UserDefinedObject),
            // check if the SOL25 class inherits from a built-in that has the method.
            $currentSolClassName = $receiverClass; // Start with the object's actual SOL25 class
            while ($currentSolClassName !== null) {
                $classDef = $this->classRegistry->findClass($currentSolClassName);
                if ($classDef === null) {
                    break; // Should not happen
                }

                // Map SOL25 built-in name to PHP class name
                $phpBuiltinClassCandidate = match ($classDef->name) {
                    "Integer" => \IPP\Student\Objects\SolInteger::class,
                    "String"  => \IPP\Student\Objects\SolString::class,
                    "Block"   => \IPP\Student\Objects\SolBlock::class,
                    // Add True, False, Nil, Object if needed? Usually not needed for their specific methods.
                    default => null,
                };

                if ($phpBuiltinClassCandidate !== null && method_exists($phpBuiltinClassCandidate, $phpMethodName)) {
                    // Found method in a built-in ancestor's PHP implementation
                    $foundBuiltin = true;
                    break; // Found the first one up the hierarchy
                }

                // Stop if we check Object, no need to check Object's parent (null)
                if ($classDef->name === "Object") {
                    break;
                }

                $currentSolClassName = $classDef->parentName;
            }
        }

        // If a built-in method was found either directly or via inheritance
        if ($foundBuiltin) {
            // Execute using the original receiver, even if method implementation is from an ancestor PHP class
            return $this->executeBuiltinMethod($receiver, $phpMethodName, $arguments);
        }


        // Check for Attributes (Only if NO method was found)
        $attributeName = null;
        $isAttributeRead = false;
        $isAttributeWrite = false;

        // Check attribute read syntax
        if ($numArgs === 0 && !str_contains($selector, ':') && $this->isValidIdentifier($selector)) {
            $isAttributeRead = true;
            $attributeName = $selector;
        } elseif ($numArgs === 1 && str_ends_with($selector, ':')) {
            $potentialAttrName = rtrim($selector, ':');
            if ($this->isValidIdentifier($potentialAttrName)) {
                $isAttributeWrite = true;
                $attributeName = $potentialAttrName;
            }
        }

        // Handle attribute access if syntax matches and no method was found
        if ($isAttributeRead) {
            try {
                assert($attributeName !== null);
                return $this->handleAttributeAccess($receiver, $attributeName, $arguments);
            } catch (DoNotUnderstandException $e) {
                // Let the final DNU exception handle this.
            }
        } elseif ($isAttributeWrite) {
            assert($attributeName !== null);
            return $this->handleAttributeAccess($receiver, $attributeName, $arguments);
        }
        throw new DoNotUnderstandException($receiverClass, $selector);
    }


   /**
    * Handles reading or writing an instance attribute.
    * @param AbstractSolObject $receiver The target object.
    * @param string $attributeName The name of the attribute.
    * @param array<AbstractSolObject> $arguments Empty for read, one element for write.
    * @return AbstractSolObject The attribute value (on read) or the receiver object (on write).
    * @throws DoNotUnderstandException If reading an undefined attribute (Error 51).
    * @throws RuntimeException If argument count is invalid (internal error).
    */
    private function handleAttributeAccess(
        AbstractSolObject $receiver,
        string $attributeName,
        array $arguments
    ): AbstractSolObject {
        $numArgs = count($arguments);
        if ($numArgs === 0) {
            return $receiver->getAttribute($attributeName);
        } elseif ($numArgs === 1) {
            $receiver->setAttribute($attributeName, $arguments[0]);
            return $receiver;
        } else {
            throw new RuntimeException("Invalid argument count ({$numArgs}) for attribute access '{$attributeName}'.");
        }
    }

   /**
    * Executes a built-in method (sol*) on a receiver object.
    * Passes necessary context (Executor, OutputWriter) to the method if needed.
    * @param AbstractSolObject $receiver The target Sol* object.
    * @param string $phpMethodName The name of the PHP method to call (e.g., "solPlus").
    * @param array<AbstractSolObject> $arguments The arguments for the method.
    * @return AbstractSolObject The result of the built-in method call.
    * @throws ValueErrorException | DoNotUnderstandException etc. (propagated from the sol* method).
    */
    private function executeBuiltinMethod(
        AbstractSolObject $receiver,
        string $phpMethodName,
        array $arguments
    ): AbstractSolObject {
        // Identify methods requiring extra context
        $contextArgs = [];
        if (in_array($phpMethodName, ['solAnd', 'solOr', 'solIfTrueIfFalse', 'solTimesRepeat', 'solWhileTrue'])) {
            $contextArgs[] = $this;
        }
        if ($phpMethodName === 'solPrint') {
             $contextArgs[] = $this->stdout;
        }

        // Combine SOL arguments and context arguments
        $allArgs = array_merge($arguments, $contextArgs);

        // Call the method dynamically
        $result = $receiver->{$phpMethodName}(...$allArgs);

        if (!$result instanceof AbstractSolObject) {
            throw new \RuntimeException(
                "Built-in method {$phpMethodName} returned invalid result type: " . get_debug_type($result)
            );
        }
        return $result;
    }

   /**
    * Executes a user-defined method found in the class hierarchy.
    * @param AbstractSolObject $receiver The instance receiving the message ('self' for the execution).
    * @param DOMElement $methodBlockNode The <block> DOMElement representing the method's body.
    * @param array<AbstractSolObject> $arguments The arguments passed to the method.
    * @return AbstractSolObject The result returned by the method's block.
    * @throws \IPP\Student\Exceptions\InterpretRuntimeException For errors during block execution.
    */
    private function executeUserMethod(
        AbstractSolObject $receiver,
        DOMElement $methodBlockNode,
        array $arguments
    ): AbstractSolObject {
        // Delegate directly to executeBlockWithArgs, passing the receiver as the 'definingSelf'
        // context for the method's frame, and the provided arguments.
        return $this->executeBlockWithArgs($methodBlockNode, $receiver, $arguments);
    }

   /**
    * Handles class messages like 'new', 'from:', 'String::read'.
    * @param string $className The name of the class receiving the message.
    * @param string $selector The selector ('new', 'from:', 'read').
    * @param array<AbstractSolObject> $arguments The arguments provided.
    * @return AbstractSolObject The result (usually a new object instance or SolNil).
    * @throws DoNotUnderstandException If the class or selector is invalid (Error 51/32).
    * @throws ValueErrorException For invalid arguments (Error 53).
    * @throws RuntimeException For internal errors.
    */
    private function handleClassMessage(string $className, string $selector, array $arguments): AbstractSolObject
    {
        $classDef = $this->classRegistry->findClass($className);
        if ($classDef === null) {
            throw new DoNotUnderstandException($className, $selector . ' (class message)');
        }

        $numArgs = count($arguments);

        switch ($selector) {
            case 'new':
                if ($numArgs !== 0) {
                    throw new DoNotUnderstandException($className, "'new' (got {$numArgs} arguments)");
                }
                $targetClassName = $className;
                $builtinAncestor = $this->getBuiltinValueAncestor($targetClassName);


                if ($targetClassName === 'Nil') {
                    $newInstance = SolNil::instance();
                } elseif ($targetClassName === 'True') {
                    $newInstance = SolTrue::instance();
                } elseif ($targetClassName === 'False') {
                    $newInstance = SolFalse::instance();
                } elseif ($targetClassName === 'Block') {
                    throw new RuntimeException("Cannot create default 'Block' instance using 'new'.");
                } elseif ($builtinAncestor === 'Integer') {
                    $newInstance = new SolInteger(0);
                } elseif ($builtinAncestor === 'String') {
                    $newInstance = new SolString('');
                } else {
                    $newInstance = new \IPP\Student\Objects\UserDefinedObject($targetClassName);
                }
                if (
                    !($newInstance instanceof \IPP\Student\Objects\UserDefinedObject)
                    && $newInstance->getSolClassName() !== $targetClassName
                ) {
                    $newInstance->setSolClassName($targetClassName);
                }

                return $newInstance;

            case 'from:':
                if ($numArgs !== 1) {
                    throw new DoNotUnderstandException($className, "'from:' (got {$numArgs} arguments)");
                }
                $sourceObj = $arguments[0];
                $sourceClass = $sourceObj->getSolClassName();
                $targetClassName = $className;

                $targetClassDef = $this->classRegistry->findClass($targetClassName);
                $sourceClassDef = $this->classRegistry->findClass($sourceClass);
                if (!$targetClassDef || !$sourceClassDef) {
                    throw new RuntimeException(
                        "Class definition not found during 'from:' execution for {$targetClassName} or {$sourceClass}."
                    );
                }

                $compatible = $this->checkClassCompatibility($targetClassDef, $sourceClassDef);
                if (!$compatible) {
                    throw new ValueErrorException(
                        "Incompatible argument type '{$sourceClass}' for '{$targetClassName} from:'."
                    );
                }

                if ($targetClassName === 'Nil') {
                    return SolNil::instance();
                }
                if ($targetClassName === 'True') {
                    return SolTrue::instance();
                }
                if ($targetClassName === 'False') {
                    return SolFalse::instance();
                }

                $targetBuiltinAncestor = $this->getBuiltinValueAncestor($targetClassName);

                if ($targetBuiltinAncestor === 'Integer') {
                    $newInstance = new SolInteger(0);
                } elseif ($targetBuiltinAncestor === 'String') {
                    $newInstance = new SolString('');
                } elseif ($targetBuiltinAncestor === 'Block') {
                    if (!$sourceObj instanceof SolBlock) {
                        throw new ValueErrorException("Source for Block from: must be a Block.");
                    }
                    $newInstance = new SolBlock(
                        $sourceObj->getBlockNode(),
                        $sourceObj->getArity(),
                        $sourceObj->getDefiningSelf()
                    );
                } else {
                    $newInstance = new \IPP\Student\Objects\UserDefinedObject($targetClassName);
                }

                $newInstance->setSolClassName($targetClassName);

                if ($newInstance instanceof SolInteger && $sourceObj instanceof SolInteger) {
                    $newInstance = new SolInteger($sourceObj->getValue());
                    $newInstance->setSolClassName($targetClassName);
                } elseif ($newInstance instanceof SolString && $sourceObj instanceof SolString) {
                    $newInstance = new SolString($sourceObj->getValue());
                    $newInstance->setSolClassName($targetClassName);
                }

                foreach ($sourceObj->getAllAttributes() as $name => $value) {
                    $newInstance->setAttribute($name, $value);
                }

                return $newInstance;

            case 'read':
                if ($className !== 'String') {
                    throw new DoNotUnderstandException($className, 'read (class message)');
                }
                if ($numArgs !== 0) {
                    throw new DoNotUnderstandException($className, "'read' (got {$numArgs} arguments)");
                }

                $line = $this->input->readString();
                if ($line === null) {
                    return SolNil::instance();
                } else {
                    return new SolString($line);
                }

            default:
                throw new DoNotUnderstandException($className, $selector . ' (class message)');
        }
    }


    //======================================================================
    // PART 4 – Class-level helpers
    //----------------------------------------------------------------------
    // checkClassCompatibility() — validates ‘from:’ conversion
    // getBuiltinValueAncestor() — finds nearest Integer / String / Block
    // selectorToPhpMethod()     — "plus:" -> "solPlus"
    // isValidIdentifier()       — validates a SOL25 identifier
    //======================================================================

    /**
     * Checks if source class is compatible with target class for 'from:' message.
     * Compatible means: same class, source is subclass of target, or target is subclass of source.
     * @param ClassDefinition $target Target class definition.
     * @param ClassDefinition $source Source class definition.
     * @return bool True if compatible, false otherwise.
     */
    private function checkClassCompatibility(ClassDefinition $target, ClassDefinition $source): bool
    {
        $targetName = $target->name;
        $sourceName = $source->name;

        if ($targetName === $sourceName) {
            return true;
        }

        $currentSourceName = $source->parentName;
        while ($currentSourceName !== null) {
            if ($currentSourceName === $targetName) {
                return true;
            }
            $parentDef = $this->classRegistry->findClass($currentSourceName);
            if ($parentDef === null) {
                break;
            }
            $currentSourceName = $parentDef->parentName;
        }
        $currentTargetName = $target->parentName;
        while ($currentTargetName !== null) {
            if ($currentTargetName === $sourceName) {
                return true;
            }
             $parentDef = $this->classRegistry->findClass($currentTargetName);
            if ($parentDef === null) {
                break;
            }
            $currentTargetName = $parentDef->parentName;
        }
        return false;
    }

    /**
     * Checks if a string is a valid SOL25 identifier (starts with _ or lowercase, contains _, letters, digits).
     * @param string $name The potential identifier name.
     * @return bool
     */
    private function isValidIdentifier(string $name): bool
    {
        // Keywords are not valid identifiers for variables/attributes/methods
        $keywords = ['class', 'self', 'super', 'nil', 'true', 'false'];
        if (in_array($name, $keywords, true)) {
            return false;
        }

        // Regex based on spec: non-empty sequence of letters, digits, _
        // starting with a lowercase letter or _
        // (?i) makes letter check case-insensitive, but we need start to be specific
        // ^([a-z_]) ensures start with lowercase or underscore
        // [a-zA-Z0-9_]* allows any letter, digit, or underscore zero or more times after start
        return preg_match('/^([a-z_])[a-zA-Z0-9_]*$/', $name) === 1;
    }

    /**
     * Finds the nearest built-in ancestor class (Integer, String, Block) for a given SOL25 class name.
     * @param string $className The name of the class to check.
     * @return ?string Name of the built-in ancestor ("Integer", "String", "Block") or null if none found
     * (inherits Object directly or indirectly).
     */
    private function getBuiltinValueAncestor(string $className): ?string
    {
        $builtInValueTypes = ["Integer", "String", "Block"];
        $currentClassName = $className;

        while (true) {
            if (in_array($currentClassName, $builtInValueTypes)) {
                return $currentClassName; // Found a direct built-in value type
            }
            $classDef = $this->classRegistry->findClass($currentClassName);
            // If class not found or it's Object, stop searching up
            if ($classDef === null || $classDef->parentName === null) {
                break;
            }
            $currentClassName = $classDef->parentName;
        }
        // If we reached here, Object is the ancestor, or it doesn't inherit a value type
        return null;
    }

    /**
     * Converts a SOL25 selector string to a corresponding PHP method name (convention: sol + SelectorInPascalCase).
     * E.g., "plus:" -> "solPlus", "ifTrue:ifFalse:" -> "solIfTrueIfFalse", "run" -> "solRun", "print" -> "solPrint"
     * @param string $selector
     * @return string
     */
    private function selectorToPhpMethod(string $selector): string
    {
        // Remove trailing colon, if present
        $baseSelector = rtrim($selector, ':');

        // Split into parts by colons
        $parts = explode(':', $baseSelector);

        // Capitalize the first letter of EACH part
        $pascalCaseParts = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $pascalCaseParts[] = ucfirst($part); // ucfirst makes the first letter uppercase
        }

        // Join parts and add 'sol' prefix
        $methodName = 'sol' . implode('', $pascalCaseParts);

        return $methodName;
    }

    //======================================================================
    // PART 5 – Program entry
    //----------------------------------------------------------------------
    /**
     * Runs the SOL25 program represented by the loaded DOM.
     * Finds the Main class, instantiates it, finds the 'run' method,
     * and starts execution by calling the 'run' method's block.
     * This is the main entry point for execution logic within the Executor.
     * @param DOMDocument $dom The DOM document of the program's AST.
     * @return AbstractSolObject The final result returned by the Main::run method.
     * @throws RuntimeException If the Main class or its parameterless 'run' method is not found
     * (indicates an issue that should have been caught by the parser - Error 31).
     * @throws \IPP\Student\Exceptions\InterpretRuntimeException For any runtime errors during program execution.
     */
    public function runProgram(DOMDocument $dom): AbstractSolObject
    {
        $this->initXPath($dom);
        $xpath = $this->xpath;

        if ($xpath === null) {
            throw new \RuntimeException("XPath helper not initialized before calling " . __METHOD__);
        }

        // Find Main class definition
        $mainClassDef = $this->classRegistry->findClass("Main");
        if ($mainClassDef === null) {
            throw new SemanticErrorException(
                "Mandatory class 'Main' not found in class registry.",
                \IPP\Core\ReturnCode::PARSE_MAIN_ERROR
            );
        }

        // Find the 'run' method block within Main
        $runMethodBlockNode = $this->classRegistry->findMethodBlock("Main", "run");
        if (!$runMethodBlockNode instanceof DOMElement) {
             throw new SemanticErrorException(
                 "Mandatory method 'run' not found in class 'Main' or its ancestors.",
                 \IPP\Core\ReturnCode::PARSE_MAIN_ERROR
             );
        }

        // Check if 'run' method is parameterless
        $paramCheck = $xpath->query('./parameter', $runMethodBlockNode);
        if ($paramCheck === false) {
             throw new RuntimeException("Failed to query parameters for Main::run method block.");
        }
        if ($paramCheck->length > 0) {
              throw new SemanticErrorException(
                  "Mandatory method 'run' in class 'Main' must have zero parameters.",
                  \IPP\Core\ReturnCode::PARSE_MAIN_ERROR
              );
        }

        // Create an instance of the Main class
        $mainInstance = new \IPP\Student\Objects\UserDefinedObject("Main");

        // Execute the 'run' method
        $result = $this->executeUserMethod($mainInstance, $runMethodBlockNode, []);

        // Return the result
        return $result;
    }
}
