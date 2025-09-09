<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Objects;

/**
 * Represents the singleton 'nil' object in SOL25.
 */
final class SolNil extends AbstractSolObject
{
    private static ?SolNil $instance = null;

    private function __construct()
    {
        parent::__construct("Nil");
    }

    /**
     * Gets the singleton instance of SolNil.
     * @return SolNil
     */
    public static function instance(): SolNil
    {
        if (self::$instance === null) {
            self::$instance = new SolNil();
        }
        return self::$instance;
    }

    /**
     * Returns the string representation "nil".
     * @return SolString
     */
    public function solAsString(): SolString
    {
        return new SolString("nil");
    }

    /**
     * Checks if the other object is also the nil instance.
     * @param AbstractSolObject $other The object to compare with.
     * @return AbstractSolObject SolTrue if other is SolNil, SolFalse otherwise.
     */
    public function solEqualTo(AbstractSolObject $other): AbstractSolObject
    {
        // Since it's a singleton, identity check is sufficient for equality.
        return $this->solIdenticalTo($other);
    }

    /**
     * Returns true, as this object is Nil.
     * @return AbstractSolObject
     */
    public function solIsNil(): AbstractSolObject
    {
        return SolTrue::instance();
    }

    // Prevent cloning and serialization for singletons
    private function __clone()
    {
    }
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
