<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Objects;

use IPP\Student\Execution\Executor;
use IPP\Student\Exceptions\ValueErrorException;
use IPP\Student\Exceptions\InterpretRuntimeException; // For block execution errors
use InvalidArgumentException;

// Used in fromString

class SolInteger extends AbstractSolObject
{
    private int $value;

    /**
     * Constructor for SolInteger.
     * @param int $value The initial integer value. Defaults to 0.
     */
    public function __construct(int $value = 0)
    {
        parent::__construct("Integer");
        $this->value = $value;
    }

    /**
     * Creates a SolInteger from a string representation.
     * Intended for internal use during parsing or literal creation.
     * @param string $str The string to parse as an integer.
     * @return SolInteger The created SolInteger instance.
     * @throws InvalidArgumentException If the string is not a valid integer representation.
     * Consider using ValueErrorException for consistency if called from SOL context.
     */
    public static function fromString(string $str): SolInteger
    {
        $value = filter_var($str, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new InvalidArgumentException("Invalid integer literal string: '{$str}'");
        }
        return new self($value);
    }

    /**
     * Gets the raw PHP integer value.
     * @return int
     */
    public function getValue(): int
    {
        return $this->value;
    }

    /**
     * Type check for Number (Integer is a Number).
     * @return AbstractSolObject Returns SolTrue instance.
     */
    public function solIsNumber(): AbstractSolObject
    {
        return SolTrue::instance();
    }

    /**
     * Compares this integer with another SOL object for equality.
     * Integers are equal if the other object is also a SolInteger with the same value.
     * @param AbstractSolObject $other The object to compare with.
     * @return AbstractSolObject SolTrue if equal, SolFalse otherwise.
     */
    public function solEqualTo(AbstractSolObject $other): AbstractSolObject
    {
        if ($other instanceof SolInteger) {
            return ($this->value === $other->getValue()) ? SolTrue::instance() : SolFalse::instance();
        }
        return SolFalse::instance();
    }

    /**
     * Returns the string representation of this integer.
     * @return SolString A new SolString instance.
     */
    public function solAsString(): SolString
    {
        return new SolString(strval($this->value));
    }

    /**
     * Checks if this integer is greater than another SolInteger.
     * @param AbstractSolObject $other The SolInteger to compare against.
     * @return AbstractSolObject SolTrue if this value > other's value, SolFalse otherwise.
     * @throws ValueErrorException If $other is not a SolInteger.
     */
    public function solGreaterThan(AbstractSolObject $other): AbstractSolObject
    {
        $this->expectInteger($other, 'greaterThan:');
        /** @var SolInteger $other */
        return ($this->value > $other->getValue()) ? SolTrue::instance() : SolFalse::instance();
    }

    /**
     * Adds another SolInteger to this integer.
     * @param AbstractSolObject $other The SolInteger to add.
     * @return SolInteger A new SolInteger representing the sum.
     * @throws ValueErrorException If $other is not a SolInteger.
     */
    public function solPlus(AbstractSolObject $other): SolInteger
    {
        $this->expectInteger($other, 'plus:');
        /** @var SolInteger $other */
        $sum = $this->value + $other->getValue();
        return new SolInteger($sum);
    }

    /**
     * Subtracts another SolInteger from this integer.
     * @param AbstractSolObject $other The SolInteger to subtract.
     * @return SolInteger A new SolInteger representing the difference.
     * @throws ValueErrorException If $other is not a SolInteger.
     */
    public function solMinus(AbstractSolObject $other): SolInteger
    {
        $this->expectInteger($other, 'minus:');
        /** @var SolInteger $other */
        return new SolInteger($this->value - $other->getValue());
    }

    /**
     * Multiplies this integer by another SolInteger.
     * @param AbstractSolObject $other The SolInteger to multiply by.
     * @return SolInteger A new SolInteger representing the product.
     * @throws ValueErrorException If $other is not a SolInteger.
     */
    public function solMultiplyBy(AbstractSolObject $other): SolInteger
    {
        $this->expectInteger($other, 'multiplyBy:');
        /** @var SolInteger $other */
        return new SolInteger($this->value * $other->getValue());
    }

    /**
     * Performs integer division of this integer by another SolInteger.
     * @param AbstractSolObject $other The SolInteger divisor.
     * @return SolInteger A new SolInteger representing the integer result of the division.
     * @throws ValueErrorException If $other is not a SolInteger, or if $other is zero (division by zero).
     */
    public function solDivBy(AbstractSolObject $other): SolInteger
    {
        $this->expectInteger($other, 'divBy:');
        /** @var SolInteger $other */
        $divisor = $other->getValue();
        if ($divisor === 0) {
            // Corresponds to SOL Error 54
            throw new ValueErrorException("Zero division divBy:");
        }
        // Use intdiv for integer division consistent with many languages
        $result = intdiv($this->value, $divisor);
        return new SolInteger($result);
    }

    /**
     * Returns this integer object itself, as it's already an integer.
     * @return SolInteger This object.
     */
    public function solAsInteger(): SolInteger
    {
        return $this;
    }

    /**
     * Executes a block a specified number of times (this integer's value).
     * Passes the current iteration number (1-based) to the block via 'value:'.
     * Does nothing if the integer value is zero or negative.
     * Note: Assumes 'timesRepeat:' is part of SOL25 or an intended extension.
     *
     * @param AbstractSolObject $valueArg The block (must understand 'value:') to execute.
     * @param Executor $executor The executor to dispatch the 'value:' message.
     * @return SolInteger Returns self ($this).
     * @throws InterpretRuntimeException If the execution of the block fails (e.g., wrong type, error within block).
     * This includes potential type errors if $valueArg does not understand 'value:'.
     */
    public function solTimesRepeat(
        AbstractSolObject $valueArg,
        Executor $executor
    ): AbstractSolObject {
        if ($this->value > 0) {
            for ($i = 1; $i <= $this->value; $i++) {
                $iterationArg = new SolInteger($i); // Pass 1-based index
                // Dispatch 'value:' message to the block with the iteration number.
                // The executor handles potential errors during block execution.
                $executor->dispatchMessage(
                    $valueArg,
                    'value:',
                    [$iterationArg],
                    false // Not a super call
                );
                // Result of block execution is ignored in timesRepeat: loop.
            }
        }
        return $this; // Commonly returns self in similar control structures.
    }

    /**
     * Private helper to check if an object is a SolInteger.
     * Throws a ValueErrorException if the type does not match.
     * @param AbstractSolObject $obj The object to check.
     * @param string $methodSelector The selector of the calling method (for error message).
     * @return void
     * @throws ValueErrorException If $obj is not an instance of SolInteger (corresponds to SOL Error 53).
     */
    private function expectInteger(AbstractSolObject $obj, string $methodSelector): void
    {
        if (!$obj instanceof SolInteger) {
            throw new ValueErrorException(
                "Argument for {$methodSelector} must be Integer, got " . $obj->getSolClassName()
            );
        }
    }
}
