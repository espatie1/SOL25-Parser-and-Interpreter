<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Exceptions;

use IPP\Core\Exception\InternalErrorException;
use Throwable;

/**
 * Exception for attempting an operation (pop, peek) on an empty FrameStack.
 * This typically indicates an internal logic error in the interpreter.
 */
class EmptyStackException extends InternalErrorException
{
    public function __construct(
        string $message = "Attempted operation on an empty frame stack",
        ?Throwable $previous = null
    ) {
        // Using the Internal Error code 99
        parent::__construct($message, $previous);
    }
}
