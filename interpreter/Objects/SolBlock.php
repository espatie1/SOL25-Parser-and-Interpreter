<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Objects;

use DOMElement;
use IPP\Student\Execution\Executor;
use IPP\Student\Exceptions\ValueErrorException;

/**
 * Represents a SOL25 Block object (a piece of executable code).
 * Stores the code's AST node, its arity, and the 'self' context from where it was defined.
 */
class SolBlock extends AbstractSolObject
{
    private DOMElement $blockNode;
    private int $arity;
    private ?AbstractSolObject $definingSelf;

    /**
     * @param DOMElement $node The <block> DOMElement from the AST.
     * @param int $arity The number of parameters the block expects.
     * @param AbstractSolObject|null $definingSelf The 'self' object from the context where block literal was defined.
     */
    public function __construct(DOMElement $node, int $arity, ?AbstractSolObject $definingSelf)
    {
        parent::__construct("Block");
        $this->blockNode = $node;
        $this->arity = $arity;
        $this->definingSelf = $definingSelf;
    }

    /**
     * Gets the number of parameters this block expects.
     * @return int
     */
    public function getArity(): int
    {
        return $this->arity;
    }

    /**
     * Gets the DOMElement representing the block's code.
     * @return DOMElement
     */
    public function getBlockNode(): DOMElement
    {
        return $this->blockNode;
    }

    /**
     * Gets the 'self' object that was captured when this block was defined.
     * @return AbstractSolObject|null
     */
    public function getDefiningSelf(): ?AbstractSolObject
    {
        return $this->definingSelf;
    }


    /**
     * Returns true, as this object represents a block.
     * @return AbstractSolObject
     */
    public function solIsBlock(): AbstractSolObject
    {
        return SolTrue::instance();
    }

    /**
     * Compares block objects. By default, uses identity comparison.
     * Two different block literals result in different objects.
     * @param AbstractSolObject $other The object to compare with.
     * @return AbstractSolObject SolTrue if identical, SolFalse otherwise.
     */
    public function solEqualTo(AbstractSolObject $other): AbstractSolObject
    {
        return $this->solIdenticalTo($other);
    }

    /**
     * Returns a simple string representation for a block.
     * @return SolString
     */
    public function solAsString(): SolString
    {
        // We could potentially include arity or part of the code, but keep it simple.
        return new SolString("[a Block]");
    }
}
