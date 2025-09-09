<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Exceptions;

use IPP\Core\ReturnCode;
use Throwable;

/**
 * Exception for runtime errors related to operand values (e.g., division by zero, wrong type for operation) (Error 53).
 */
class ValueErrorException extends InterpretRuntimeException
{
    public function __construct(string $message = "Runtime value error", ?Throwable $previous = null)
    {
        parent::__construct(
            $message,
            ReturnCode::INTERPRET_VALUE_ERROR, // Code 53
            $previous,
            false
        );
    }
}
