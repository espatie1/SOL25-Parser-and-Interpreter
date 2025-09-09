<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Execution;

use IPP\Student\Objects\ClassDefinition;
use DOMDocument;
use DOMXPath;
use DOMElement;
use RuntimeException;

/**
 * Stores definitions of all known SOL25 classes (built-in and user-defined).
 * Provides methods for class lookup and method resolution respecting inheritance.
 */
class ClassRegistry
{
    /**
     * Storage for class definitions.
     * Key: Class name (string)
     * Value: ClassDefinition object
     * @var array<string, ClassDefinition>
     */
    private array $classes = [];

    public function __construct()
    {
        $this->registerBuiltInClasses();
    }

    /**
     * Registers the definitions of SOL25's built-in classes.
     * Their methods are implemented directly in the corresponding PHP Sol* classes,
     * so the 'methods' array here is empty.
     */
    private function registerBuiltInClasses(): void
    {
        $this->registerClass(new ClassDefinition("Object", null, [], true));
        $this->registerClass(new ClassDefinition("Nil", "Object", [], true));
        $this->registerClass(new ClassDefinition("True", "Object", [], true));
        $this->registerClass(new ClassDefinition("False", "Object", [], true));
        $this->registerClass(new ClassDefinition("Integer", "Object", [], true));
        $this->registerClass(new ClassDefinition("String", "Object", [], true));
        $this->registerClass(new ClassDefinition("Block", "Object", [], true));
    }

    /**
     * Adds a class definition to the registry.
     * @param ClassDefinition $classDef
     */
    public function registerClass(ClassDefinition $classDef): void
    {
        $this->classes[$classDef->name] = $classDef;
    }

    /**
     * Loads user-defined class definitions from the XML DOM.
     * @param DOMDocument $dom The DOM document representing the SOL25 program AST.
     * @throws RuntimeException If required attributes are missing in the XML (should be caught by parser).
     */
    public function loadClassesFromDOM(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $classList = $xpath->query('/program/class'); // Find all <class> elements under <program>

        if ($classList === false) {
             throw new RuntimeException("Failed to execute XPath query for classes.");
        }

        foreach ($classList as $classNode) {
            if (!$classNode instanceof DOMElement) {
                continue;
            }

            $name = $classNode->getAttribute('name');
            $parent = $classNode->getAttribute('parent');

            if (empty($name) || empty($parent)) {
                // This indicates an invalid XML structure, should have been caught by parser/spec
                throw new RuntimeException("Class node is missing 'name' or 'parent' attribute.");
            }

            $methods = [];
            // Find method nodes directly under the current class node
            $methodNodes = $xpath->query('./method', $classNode);
            if ($methodNodes === false) {
                throw new RuntimeException("Failed to execute XPath query for methods in class {$name}.");
            }

            /** @var DOMElement $methodNode */
            foreach ($methodNodes as $methodNode) {
                $selector = $methodNode->getAttribute('selector');
                if (empty($selector)) {
                    throw new RuntimeException("Method node in class {$name} is missing 'selector' attribute.");
                }
                // Find the <block> element directly under the <method> element
                $blockNodeList = $xpath->query('./block[1]', $methodNode);
                if ($blockNodeList === false) {
                    throw new RuntimeException("Failed to query <block> inside method '{$selector}' in class {$name}.");
                }
                $blockNode = $blockNodeList->item(0);
                if (!$blockNode instanceof DOMElement) {
                    throw new RuntimeException(
                        "Method '{$selector}' in class {$name} does not contain a valid <block> element."
                    );
                }
                if (isset($methods[$selector])) {
                    throw new RuntimeException("Duplicate method selector '{$selector}' found in class {$name}.");
                }
                $methods[$selector] = $blockNode;
            }

            $classDef = new ClassDefinition($name, $parent, $methods, false);
            $this->registerClass($classDef);
        }
    }

    /**
     * Finds the definition of a class by its name.
     * @param string $className
     * @return ClassDefinition|null Null if the class is not defined.
     */
    public function findClass(string $className): ?ClassDefinition
    {
        return $this->classes[$className] ?? null;
    }

    /**
     * Checks if a class with the given name is defined.
     * @param string $className
     * @return bool
     */
    public function isClassDefined(string $className): bool
    {
        return isset($this->classes[$className]);
    }

    /**
     * Finds the DOMElement (<block>) for a method's body, searching up the inheritance chain.
     * @param string $className The name of the class where the search starts.
     * @param string $selector The selector of the method to find (e.g., "run", "plus:").
     * @return DOMElement|null The <block> DOMElement if found, otherwise null.
     */
    public function findMethodBlock(string $className, string $selector): ?DOMElement
    {

        $currentClassName = $className;

        while ($currentClassName !== null) {
            $classDef = $this->findClass($currentClassName);
            if ($classDef === null) {
                return null;
            }

            // Check methods defined directly in this class
            if (isset($classDef->methods[$selector])) {
                return $classDef->methods[$selector];
            }

            // Move to the parent class
            $currentClassName = $classDef->parentName;
            // Stop if we reach the top (Object has null parent)
        }
        return null;
    }
}
