<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Exceptions;

use IPP\Core\Exception\IPPException;
use IPP\Core\ReturnCode;
use Throwable;

/**
 * Exception for semantic errors detected during static analysis or interpretation startup.
 */
class SemanticErrorException extends IPPException
{
    /**
     * @param string $message Description of the error
     * @param int $code The specific return code (e.g., PARSE_MAIN_ERROR)
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = "Semantic error",
        int $code = ReturnCode::PARSE_MAIN_ERROR,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous, false);
    }
}
