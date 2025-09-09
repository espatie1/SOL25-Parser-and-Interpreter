<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Objects;

use DOMElement;

/**
 * Data Transfer Object (DTO) to hold information about a defined SOL25 class.
 */
class ClassDefinition
{
    public string $name;
    public ?string $parentName;

    /**
     * Methods defined directly in this class.
     * Key: selector string (e.g., "run", "plus:", "ifTrue:ifFalse:")
     * Value: The <block> DOMElement representing the method's body.
     * For built-in classes, this might be empty or not used directly for execution.
     * @var array<string, DOMElement>
     */
    public array $methods;

    public bool $isBuiltIn;

    /**
     * @param string $name
     * @param string|null $parentName
     * @param array<string, DOMElement> $methods
     * @param bool $isBuiltIn
     */
    public function __construct(string $name, ?string $parentName, array $methods, bool $isBuiltIn)
    {
        $this->name = $name;
        $this->parentName = $parentName;
        $this->methods = $methods;
        $this->isBuiltIn = $isBuiltIn;
    }
}
