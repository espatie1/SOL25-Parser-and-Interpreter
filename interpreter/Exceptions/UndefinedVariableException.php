<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Exceptions;

use IPP\Core\ReturnCode;
use Throwable;

/**
 * Exception for attempting to read an undefined (uninitialized) variable (Error 32).
 * Using code 32 here, alternative could be 52.
 */
class UndefinedVariableException extends InterpretRuntimeException
{
    public function __construct(string $variableName, ?Throwable $previous = null)
    {
        parent::__construct(
            "Attempt to read undefined variable '{$variableName}'",
            ReturnCode::PARSE_UNDEF_ERROR, // Using code 32
            $previous,
            false
        );
    }
}
