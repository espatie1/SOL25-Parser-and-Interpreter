<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Objects;

use IPP\Student\Execution\Executor;
use IPP\Student\Exceptions\ValueErrorException;
use IPP\Student\Exceptions\InterpretRuntimeException;

/**
 * Represents the singleton 'false' object in SOL25.
 */
final class SolFalse extends AbstractSolObject
{
    private static ?SolFalse $instance = null;

    private function __construct()
    {
        parent::__construct("False");
    }

    /**
     * Gets the singleton instance of SolFalse.
     * @return SolFalse
     */
    public static function instance(): SolFalse
    {
        if (self::$instance === null) {
            self::$instance = new SolFalse();
        }
        return self::$instance;
    }

    /**
     * Returns the string representation "false".
     * @return SolString
     */
    public function solAsString(): SolString
    {
        return new SolString("false");
    }

    /**
     * Returns the negation (true).
     * @return SolTrue
     */
    public function solNot(): SolTrue
    {
        return SolTrue::instance();
    }

    /**
     * Logical AND. Since receiver is false, returns false immediately (short-circuit).
     * @param AbstractSolObject $arg (Ignored).
     * @param Executor $executor (Ignored).
     * @return SolFalse
     */
    public function solAnd(AbstractSolObject $arg, Executor $executor): SolFalse
    {
        return $this;
    }

    /**
     * Logical OR. Since receiver is false, evaluates the argument by sending 'value'.
     * @param AbstractSolObject $arg Argument that must understand 'value' message (typically SolBlock arity 0).
     * @param Executor $executor The executor to run the argument's 'value' method.
     * @return AbstractSolObject The result of evaluating the argument.
     * @throws InterpretRuntimeException If evaluation of argument fails.
     */
    public function solOr(AbstractSolObject $arg, Executor $executor): AbstractSolObject
    {
        return $executor->executeBlockArgument($arg);
    }

    /**
     * Conditional execution. Since receiver is false, executes the second argument.
     * @param AbstractSolObject $trueArg The argument to execute if true (ignored, type not checked here).
     * @param AbstractSolObject $falseArg The argument to execute if false (must understand 'value').
     * @param Executor $executor The executor to run the argument's 'value' method.
     * @return AbstractSolObject The result of executing the falseArg.
     * @throws InterpretRuntimeException If evaluation of falseArg fails.
     */
    public function solIfTrueIfFalse(
        AbstractSolObject $trueArg,
        AbstractSolObject $falseArg,
        Executor $executor
    ): AbstractSolObject {
        return $executor->executeBlockArgument($falseArg);
    }
    /**
     * Checks if the other object is also the false instance.
     * @param AbstractSolObject $other The object to compare with.
     * @return AbstractSolObject SolTrue if other is SolFalse, SolFalse otherwise.
     */
    public function solEqualTo(AbstractSolObject $other): AbstractSolObject
    {
        return $this->solIdenticalTo($other);
    }
    // Prevent cloning and serialization for singleton
    private function __clone()
    {
    }
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
