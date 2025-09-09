<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Objects;

use IPP\Student\Objects\SolTrue;
use IPP\Student\Objects\SolFalse;
use IPP\Student\Objects\SolString;
use IPP\Student\Exceptions\DoNotUnderstandException;

/**
 * Abstract base class for all runtime objects representing SOL25 values.
 * Provides common structure for storing the SOL25 class name and instance attributes.
 * Implements default behavior corresponding to the built-in SOL25 'Object' class methods.
 */
abstract class AbstractSolObject
{
    /**
     * The name of the SOL25 class this object represents (e.g., "Integer", "String", "MyClass").
     */
    protected string $solClassName;

    /**
     * Storage for dynamically created instance attributes.
     * @var array<string, AbstractSolObject> [attributeName => valueObject]
     */
    protected array $attributes = [];

    /**
     * @param string $solClassName The name of the corresponding SOL25 class.
     */
    public function __construct(string $solClassName)
    {
        $this->solClassName = $solClassName;
    }

    /**
     * Gets the SOL25 class name of this object.
     * @return string
     */
    public function getSolClassName(): string
    {
        return $this->solClassName;
    }

    /**
     * Allows the Executor to set the correct SOL25 class name after creation (e.g., for 'from:').
     * @param string $name The SOL25 class name to set.
     */
    public function setSolClassName(string $name): void
    {
        $this->solClassName = $name;
    }

    /**
     * Returns all instance attributes. Needed for copying in 'from:'.
     * @return array<string, AbstractSolObject>
     */
    public function getAllAttributes(): array
    {
        return $this->attributes;
    }
    /**
     * Sets an instance attribute on this object.
     * @param string $name The name of the attribute.
     * @param AbstractSolObject $value The SOL object to set as the value.
     */
    public function setAttribute(string $name, AbstractSolObject $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Gets the value of an instance attribute.
     * @param string $name The name of the attribute.
     * @return AbstractSolObject The value object associated with the attribute.
     * @throws DoNotUnderstandException If the attribute does not exist (Error 51).
     */
    public function getAttribute(string $name): AbstractSolObject
    {
        if (!$this->hasAttribute($name)) {
            throw new DoNotUnderstandException($this->solClassName, $name);
        }
        return $this->attributes[$name];
    }

    /**
     * Checks if an instance attribute with the given name exists.
     * @param string $name The name of the attribute.
     * @return bool True if the attribute exists, false otherwise.
     */
    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }
    /**
     * Default implementation for the 'identicalTo:' message.
     * Checks if the other object is the exact same instance in memory.
     * @param AbstractSolObject $other The object to compare with.
     * @return AbstractSolObject SolTrue if identical, SolFalse otherwise.
     */
    public function solIdenticalTo(AbstractSolObject $other): AbstractSolObject
    {
        return ($this === $other) ? SolTrue::instance() : SolFalse::instance();
    }

    /**
     * Default implementation for the 'equalTo:' message.
     * By default, compares identity, same as identicalTo:.
     * Subclasses (Integer, String, etc.) should override this for value comparison.
     * @param AbstractSolObject $other The object to compare with.
     * @return AbstractSolObject SolTrue if equal (by default, identical), SolFalse otherwise.
     */
    public function solEqualTo(AbstractSolObject $other): AbstractSolObject
    {
        return $this->solIdenticalTo($other);
    }

    /**
     * Default implementation for the 'asString' message.
     * Returns an empty string object by default.
     * Subclasses should override to provide a meaningful string representation.
     * @return SolString An empty SolString object.
     */
    public function solAsString(): SolString
    {
        return new SolString('');
    }

    /**
     * Default implementation for the 'isNumber' message.
     * @return AbstractSolObject Always returns false unless overridden by SolInteger.
     */
    public function solIsNumber(): AbstractSolObject
    {
        return SolFalse::instance();
    }

    /**
     * Default implementation for the 'isString' message.
     * @return AbstractSolObject Always returns false unless overridden by SolString.
     */
    public function solIsString(): AbstractSolObject
    {
        return SolFalse::instance();
    }

    /**
     * Default implementation for the 'isBlock' message.
     * @return AbstractSolObject Always returns false unless overridden by SolBlock.
     */
    public function solIsBlock(): AbstractSolObject
    {
        return SolFalse::instance();
    }

    /**
     * Default implementation for the 'isNil' message.
     * @return AbstractSolObject Always returns false unless overridden by SolNil.
     */
    public function solIsNil(): AbstractSolObject
    {
        return SolFalse::instance();
    }

    /**
     * Provides a basic string representation for debugging or logging.
     * Includes error handling to prevent crashes within __toString.
     * @return string A string like "[ClassName object]".
     */
    public function __toString(): string
    {
        try {
             return "[{$this->solClassName} object]";
        } catch (\Throwable $e) {
            return "[{$this->solClassName} object (error in __toString: " . $e->getMessage() . ")]";
        }
    }
}
