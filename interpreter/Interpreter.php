<?php

declare(strict_types=1);

// Author - Pavel Stepanov
// Login - xstepa77
namespace IPP\Student;

// Core framework classes
use IPP\Core\AbstractInterpreter;
use IPP\Core\ReturnCode;
use IPP\Core\Settings;
// Exceptions
use IPP\Core\Exception\IPPException;
use IPP\Core\Exception\XMLException;
use IPP\Core\Exception\InputFileException;
use IPP\Core\Exception\ParameterException;
use IPP\Core\Exception\OutputFileException;
use IPP\Core\Exception\InternalErrorException;
use IPP\Student\Execution\Executor;
use IPP\Student\Execution\ClassRegistry;
use IPP\Student\Runtime\FrameStack;
use IPP\Student\Exceptions\InterpretRuntimeException; // Base for 51, 52, 53
use RuntimeException;

/**
 * Main interpreter class for the SOL25 language.
 * Organizes the setup and execution process.
 */
class Interpreter extends AbstractInterpreter
{
    /**
     * Executes the SOL25 program represented by the source XML.
     * @return int Return code (0 for success). Runtime error codes (51, 52, 53, etc.) are handled via exceptions.
     * @throws IPPException Exceptions related to file I/O, XML format (41), parameters (10),
     * or SOL25 runtime errors (e.g., 31, 51, 52, 53) will be thrown. These should be caught by
     * the IPP\Core\Engine to produce the correct final exit code for the script.
     */
    public function execute(): int
    {
        try {
            // 1. Get the DOM representation from the source reader.
            //    Throws InputFileException(11) or XMLException(41) on failure (caught by Engine).
            $dom = $this->source->getDOMDocument();

            // 2. Initialize runtime components.
            $frameStack = new FrameStack();
            $classRegistry = new ClassRegistry(); // Registers built-ins automatically.

            // 3. Create the Executor, passing dependencies (stack, registry, IO from parent).
            //    Input/Output streams ($this->input, $this->stdout) are provided by AbstractInterpreter.
            $executor = new Executor(
                $frameStack,
                $classRegistry,
                $this->input,
                $this->stdout
            );

            // 4. Load user-defined classes from DOM into the registry.
            //    May throw RuntimeException on structure issues (-> Internal Error 99 via Engine).
            $classRegistry->loadClassesFromDOM($dom);

            // 5. Execute the program using the Executor.
            //    Executor::runProgram handles finding Main::run and executing the code.
            //    Throws InterpretRuntimeException (e.g., 51, 52, 53) or SemanticErrorException (e.g., 31)
            //    on runtime/semantic errors (caught by Engine).
            $executor->runProgram($dom);

            // 6. If execution completes without exceptions, return OK.
            return ReturnCode::OK;
        } catch (IPPException $e) {
            // Re-throw IPP specific exceptions (InputFile-, XML-, Parameter-, OutputFile-,
            // InternalError-, InterpretRuntime-, SemanticError-, etc.)
            // for the Core Engine to handle and map to the correct exit code.
            throw $e;
        } catch (\Throwable $t) {
            // Catch any other unexpected error and wrap it as an Internal Error (99).
            throw new InternalErrorException(
                "An unexpected error occurred during interpretation: " . $t->getMessage(),
                $t
            );
        }
    }
}
