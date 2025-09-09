<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Runtime;

use IPP\Student\Objects\AbstractSolObject;
use IPP\Student\Exceptions\AssignToParameterException;
use IPP\Student\Exceptions\UndefinedVariableException;
use InvalidArgumentException;

/**
 * Represents a single execution frame (block or method context).
 * Stores local variables, parameters (which are immutable after creation), and the 'self' reference.
 */
class Frame
{
    /**
     * @var array<string, AbstractSolObject> Local variables defined within this frame.
     */
    private array $variables = [];

    /**
     * @var array<string, AbstractSolObject> Parameters passed to this frame during invocation.
     */
    private array $parameters = [];

    /**
     * @var array<string, bool> Helps to quickly check if a name belongs to a parameter. Keys are parameter names.
     */
    private array $parameterNames = [];

    /**
     * The 'self' object context for this frame. Null for top-level or non-method blocks if applicable.
     */
    private ?AbstractSolObject $self;

    /**
     * Creates a new execution frame.
     * @param AbstractSolObject|null $self The 'self' object for this context.
     * @param array<string> $paramNames List of parameter names declared by the block/method.
     * @param array<AbstractSolObject> $argValues List of argument values passed during invocation.
     * @throws InvalidArgumentException If the count of parameter names and argument values does not match.
     */
    public function __construct(?AbstractSolObject $self, array $paramNames, array $argValues)
    {
        if (count($paramNames) !== count($argValues)) {
            throw new InvalidArgumentException(
                "Mismatch between parameter names count and "
                . "argument values count during Frame creation."
            );
        }

        $this->self = $self;

        foreach ($paramNames as $index => $name) {
            if (isset($this->parameterNames[$name])) {
                throw new InvalidArgumentException("Duplicate param name '{$name}' detected during Frame creation.");
            }
            $this->parameters[$name] = $argValues[$index];
            $this->parameterNames[$name] = true; // Mark name as a parameter
        }
    }

    /**
     * Gets the 'self' object associated with this frame.
     * @return AbstractSolObject|null
     */
    public function getSelf(): ?AbstractSolObject
    {
        return $this->self;
    }

    /**
     * Checks if a name corresponds to a parameter in this frame.
     * @param string $name
     * @return bool
     */
    public function isParameter(string $name): bool
    {
        return isset($this->parameterNames[$name]);
    }

    /**
     * Sets or updates a local variable in the frame.
     * Cannot be used to modify parameters.
     * @param string $name The name of the variable.
     * @param AbstractSolObject $value The SOL object to assign.
     * @throws AssignToParameterException If trying to assign to an existing parameter name (Error 34).
     */
    public function setVariable(string $name, AbstractSolObject $value): void
    {
        if ($this->isParameter($name)) {
            throw new AssignToParameterException($name);
        }
        $this->variables[$name] = $value;
    }

    /**
     * Gets the value of a variable or parameter from the frame.
     * Searches parameters first, then local variables.
     * @param string $name The name of the variable or parameter.
     * @return AbstractSolObject The SOL object associated with the name.
     * @throws UndefinedVariableException If the name is not found as a parameter or local variable (Error 32).
     */
    public function getVariable(string $name): AbstractSolObject
    {
        // Check parameters first
        if (array_key_exists($name, $this->parameters)) {
            return $this->parameters[$name];
        }

        // Check local variables
        if (array_key_exists($name, $this->variables)) {
            return $this->variables[$name];
        }

        // Not found
        throw new UndefinedVariableException($name);
    }

    /**
     * Checks if a variable or parameter with the given name exists in this frame.
     *
     * @param string $name The name to check.
     * @return bool True if the name exists as a parameter or local variable, false otherwise.
     */
    public function hasVariable(string $name): bool
    {
        return isset($this->parameterNames[$name]) || array_key_exists($name, $this->variables);
    }
}
