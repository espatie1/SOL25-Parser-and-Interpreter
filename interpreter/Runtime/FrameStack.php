<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student\Runtime;

use IPP\Student\Exceptions\EmptyStackException;
use Countable;

// Interface to allow using count() on the object

/**
 * Manages the stack of execution frames (Frame objects).
 * Follows LIFO (Last-In, First-Out) principle.
 */
class FrameStack implements Countable
{
    /**
     * The stack storage. The end of the array is the top of the stack.
     * @var array<int, Frame>
     */
    private array $stack = [];

    /**
     * Pushes a new frame onto the top of the stack.
     * @param Frame $frame The frame to push.
     */
    public function push(Frame $frame): void
    {
        $this->stack[] = $frame; // Add to the end of the array
    }

    /**
     * Removes and returns the frame from the top of the stack.
     * @return Frame The frame that was on top.
     * @throws EmptyStackException If the stack is empty.
     */
    public function pop(): Frame
    {
        if ($this->isEmpty()) {
            throw new EmptyStackException("Cannot pop from an empty frame stack.");
        }
        $frame = array_pop($this->stack);
        assert($frame instanceof Frame);
        return $frame;
    }

    /**
     * Returns the frame currently at the top of the stack without removing it.
     * @return Frame The frame currently on top.
     * @throws EmptyStackException If the stack is empty.
     */
    public function getCurrentFrame(): Frame
    {
        if ($this->isEmpty()) {
            throw new EmptyStackException("Cannot get current frame from an empty stack.");
        }
        // Get the last element
        return $this->stack[count($this->stack) - 1];
    }

    /**
     * Checks if the stack is currently empty.
     * @return bool True if the stack is empty, false otherwise.
     */
    public function isEmpty(): bool
    {
        return empty($this->stack);
    }

    /**
     * Returns the current number of frames on the stack.
     * Implements the Countable interface.
     * @return int The current depth of the stack.
     */
    public function count(): int
    {
        return count($this->stack);
    }

    /**
     * Alias for count() providing more semantic clarity for stack depth.
     * @return int The current depth of the stack.
     */
    public function depth(): int
    {
        return $this->count();
    }
}
