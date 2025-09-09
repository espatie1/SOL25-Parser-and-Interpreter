<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Objects;

use IPP\Student\Execution\Executor;
use IPP\Student\Exceptions\ValueErrorException;
use IPP\Student\Exceptions\InterpretRuntimeException;

/**
 * Represents the singleton 'true' object in SOL25.
 */
final class SolTrue extends AbstractSolObject
{
    private static ?SolTrue $instance = null;

    private function __construct()
    {
        parent::__construct("True");
    }

    /**
     * Gets the singleton instance of SolTrue.
     * @return SolTrue
     */
    public static function instance(): SolTrue
    {
        if (self::$instance === null) {
            self::$instance = new SolTrue();
        }
        return self::$instance;
    }

    /**
     * Returns the string representation "true".
     * @return SolString
     */
    public function solAsString(): SolString
    {
        return new SolString("true");
    }

    /**
     * Returns the negation (false).
     * @return SolFalse
     */
    public function solNot(): SolFalse
    {
        return SolFalse::instance();
    }

    /**
     * Logical AND. Since receiver is true, evaluates the argument by sending 'value'.
     * @param AbstractSolObject $arg Argument that must understand 'value' message (typically SolBlock arity 0).
     * @param Executor $executor The executor to run the argument's 'value' method.
     * @return AbstractSolObject The result of evaluating the argument.
     * @throws InterpretRuntimeException If evaluation of argument fails (e.g., argument does not understand 'value').
     */
    public function solAnd(AbstractSolObject $arg, Executor $executor): AbstractSolObject
    {
        return $executor->executeBlockArgument($arg);
    }

    /**
     * Logical OR. Since receiver is true, returns true immediately (short-circuit).
     * @param AbstractSolObject $arg (Ignored).
     * @param Executor $executor (Ignored).
     * @return SolTrue
     */
    public function solOr(AbstractSolObject $arg, Executor $executor): SolTrue
    {
        return $this;
    }

    /**
     * Conditional execution. Since receiver is true, executes the first argument.
     * @param AbstractSolObject $trueArg The argument to execute if true (must understand 'value').
     * @param AbstractSolObject $falseArg The argument to execute if false (ignored, type not checked here).
     * @param Executor $executor The executor to run the argument's 'value' method.
     * @return AbstractSolObject The result of executing the trueArg.
     * @throws InterpretRuntimeException If evaluation of trueArg fails.
     */
    public function solIfTrueIfFalse(
        AbstractSolObject $trueArg,
        AbstractSolObject $falseArg,
        Executor $executor
    ): AbstractSolObject {
        return $executor->executeBlockArgument($trueArg);
    }


    /**
     * Checks if the other object is also the true instance.
     * @param AbstractSolObject $other The object to compare with.
     * @return AbstractSolObject SolTrue if other is SolTrue, SolFalse otherwise.
     */
    public function solEqualTo(AbstractSolObject $other): AbstractSolObject
    {
        return $this->solIdenticalTo($other);
    }

    // Prevent cloning and serialization
    private function __clone()
    {
    }
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
