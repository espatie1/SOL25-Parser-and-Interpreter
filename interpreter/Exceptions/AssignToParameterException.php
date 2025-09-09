<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Exceptions;

use IPP\Core\ReturnCode;
use Throwable;

/**
 * Exception for attempting to assign a value to a block/method parameter (Error 34).
 */
class AssignToParameterException extends InterpretRuntimeException
{
    public function __construct(string $parameterName, ?Throwable $previous = null)
    {
        parent::__construct(
            "Attempt to assign to parameter '{$parameterName}'",
            ReturnCode::PARSE_COLLISION_ERROR, // Using code 34
            $previous,
            false
        );
    }
}
