<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Exceptions;

use IPP\Core\ReturnCode;
use Throwable;

/**
 * Exception for when a receiver does not understand a message (method or attribute lookup fails) (Error 51).
 */
class DoNotUnderstandException extends InterpretRuntimeException
{
    public function __construct(string $receiverClass, string $selector, ?Throwable $previous = null)
    {
        parent::__construct(
            "Object of class '{$receiverClass}' does not understand message corresponding to selector '{$selector}'",
            ReturnCode::INTERPRET_DNU_ERROR, // Code 51
            $previous,
            false
        );
    }
}
