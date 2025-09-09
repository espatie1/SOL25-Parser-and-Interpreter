<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Objects;

use IPP\Core\Interface\OutputWriter;
use IPP\Student\Exceptions\ValueErrorException; // Potentially needed for escape sequence error
use IPP\Core\Exception\InternalErrorException;
use IPP\Student\Exceptions\InterpretRuntimeException;

// Potentially needed

class SolString extends AbstractSolObject
{
    private string $value;

    /**
     * Constructor for SolString.
     * @param string $value The initial string value. Defaults to empty string.
     */
    public function __construct(string $value = '')
    {
        parent::__construct("String");
        $this->value = $value;
    }

    /**
     * Gets the raw PHP string value.
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Type check for String.
     * @return AbstractSolObject Returns SolTrue instance.
     */
    public function solIsString(): AbstractSolObject
    {
        return SolTrue::instance();
    }

    /**
     * Compares this string with another SOL object for equality.
     * Strings are equal if the other object is also a SolString with the same value.
     * @param AbstractSolObject $other The object to compare with.
     * @return AbstractSolObject SolTrue if equal, SolFalse otherwise.
     */
    public function solEqualTo(AbstractSolObject $other): AbstractSolObject
    {
        if ($other instanceof SolString) {
            // Value comparison
            return ($this->value === $other->getValue()) ? SolTrue::instance() : SolFalse::instance();
        }
        // Type mismatch
        return SolFalse::instance();
    }

    /**
     * Returns the string representation of this object (which is itself).
     * @return SolString This object.
     */
    public function solAsString(): SolString
    {
        return $this;
    }

    /**
     * Implements the 'print' message. Writes the string's value to the output stream,
     * processing SOL25 escape sequences first.
     *
     * NOTE: The handling of escape sequences in this implementation includes \ddd (numeric),
     * which is NOT part of the base SOL25 specification provided. The spec only defines
     * \', \n, \\ and mandates error 21 for any other sequence starting with \.
     * This implementation processes \ddd and leaves other invalid sequences unchanged.
     * Consider aligning with the spec (throwing error 21) if strict adherence is required.
     *
     * @param OutputWriter $stdout The output writer object.
     * @return SolString Returns self ($this).
     * @throws InterpretRuntimeException Potentially for invalid escape sequences if spec adherence is implemented.
     */
    public function solPrint(OutputWriter $stdout): SolString
    {
        // Process standard escape sequences: \\, \', \n
        // Important: Replace \\ first to avoid interfering with others.
        $processedValue = str_replace('\\\\', '\\', $this->value); // Replace \\ with \
        $processedValue = str_replace('\\\'', '\'', $processedValue); // Replace \' with '
        $processedValue = str_replace('\\n', "\n", $processedValue); // Replace \n with newline char

        // Process numeric escape sequences \ddd (000-255) - **NON-STANDARD EXTENSION**
        // Use preg_replace_callback to find \ddd and replace with chr(ddd)
        $processedValue = preg_replace_callback(
            '/\\\\(\d{3})/', // Match \ followed by exactly three digits
            function ($matches) {
                $charCode = intval($matches[1]); // Get the numeric value
                // chr() works with 0-255
                if ($charCode >= 0 && $charCode <= 255) {
                    return chr($charCode); // Return the character with this code
                } else {
                    // Current behavior for out-of-range codes: leave sequence unchanged.
                    // Spec is unclear for this non-standard case. Could throw error instead.
                    return $matches[0]; // Return original sequence \ddd
                }
            },
            $processedValue // Process the string after standard escapes are done
        );
        // Write the processed string to output
        $stdout->writeString((string) $processedValue);

        return $this; // print method returns self
    }

    /**
     * Converts the string to an integer, if possible.
     * Returns SolNil if the string does not represent a valid integer.
     * @return AbstractSolObject SolInteger on success, SolNil on failure.
     */
    public function solAsInteger(): AbstractSolObject
    {
        // filter_var is locale-independent and robust for integer validation
        $intValue = filter_var($this->value, FILTER_VALIDATE_INT);

        if ($intValue === false) {
            // Conversion failed
            return SolNil::instance();
        } else {
            // Conversion successful
            return new SolInteger($intValue);
        }
    }

    /**
     * Concatenates this string with another SolString.
     * Returns SolNil if the other object is not a SolString.
     * @param AbstractSolObject $other The SolString to concatenate with.
     * @return AbstractSolObject A new SolString with the concatenated value, or SolNil on type mismatch.
     */
    public function solConcatenateWith(AbstractSolObject $other): AbstractSolObject
    {
        if (!$other instanceof SolString) {
            // Argument type must be String
            return SolNil::instance();
        }
        // Concatenate values and create a new SolString
        $newValue = $this->value . $other->getValue();
        return new SolString($newValue);
    }

    /**
     * Extracts a substring based on 1-based start and end indices.
     * The character at the 'end' index is *not* included.
     * Handles multi-byte UTF-8 characters correctly. Requires the mbstring PHP extension.
     * Returns SolNil if arguments are not SolInteger or if indices are not positive.
     * Returns an empty SolString if start >= end.
     *
     * Example: ('abcde' startsWith: 2 endsBefore: 4) results in 'bc'.
     *
     * @param AbstractSolObject $startArg The 1-based starting position (SolInteger).
     * @param AbstractSolObject $endArg The 1-based ending position (exclusive, SolInteger).
     * @return AbstractSolObject A new SolString containing the substring, an empty SolString, or SolNil.
     * @throws InternalErrorException If the required 'mbstring' PHP extension is not available.
     */
    public function solStartsWithEndsBefore(AbstractSolObject $startArg, AbstractSolObject $endArg): AbstractSolObject
    {
        // Validate argument types
        if (!$startArg instanceof SolInteger || !$endArg instanceof SolInteger) {
            return SolNil::instance(); // Type mismatch
        }

        $start = $startArg->getValue(); // 1-based start index
        $end = $endArg->getValue();     // 1-based end index (exclusive)

        // Validate indices are positive (SOL uses 1-based indexing)
        if ($start <= 0 || $end <= 0) {
            return SolNil::instance(); // Indices must be positive
        }

        // Calculate length. If end <= start, length is 0 or negative.
        $length = $end - $start;

        if ($length <= 0) {
            // Start index is at or after end index, return empty string
            return new SolString('');
        }

        // Calculate the 0-based offset for mb_substr
        $offset = $start - 1;

        // Ensure mbstring extension is available for correct UTF-8 handling
        if (!function_exists('mb_substr')) {
             throw new InternalErrorException(
                 "The 'mbstring' PHP extension is required for multi-byte string operations but is not available."
             );
        }

        // Extract the substring using UTF-8 encoding
        $substring = mb_substr($this->value, $offset, $length, 'UTF-8');
        return new SolString($substring);
    }
}
