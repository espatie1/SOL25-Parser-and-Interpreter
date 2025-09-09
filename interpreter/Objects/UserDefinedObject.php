<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Objects;

/**
 * Represents an instance of a user-defined SOL25 class.
 * This class primarily relies on the base AbstractSolObject for attribute storage
 * and the Executor/ClassRegistry system for method lookup and execution based
 * on the $solClassName property inherited from AbstractSolObject.
 */
class UserDefinedObject extends AbstractSolObject
{
    /**
     * Creates an instance of a user-defined class.
     *
     * @param string $className The name of the user-defined SOL25 class this object instantiates.
     */
    public function __construct(string $className)
    {
        // Call the parent constructor to store the class name.
        parent::__construct($className);

        // No additional properties are needed here; attributes are handled by the parent.
        // Methods are resolved externally via ClassRegistry based on getSolClassName().
    }

    // No specific 'sol*' methods need to be defined here.
    // Behavior is determined by messages sent to this object, which are resolved
    // by the Executor using the ClassRegistry and the object's class name.
    // Attribute access uses the inherited setAttribute/getAttribute/hasAttribute methods.
    // Methods like 'equalTo:', 'asString:', etc., if not overridden in the user's SOL25 class,
    // will eventually resolve to the default implementations in AbstractSolObject
    // through the inheritance lookup performed by ClassRegistry/Executor.
}
