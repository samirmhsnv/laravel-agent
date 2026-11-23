<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Exceptions;

/** The model's final answer did not match the expected schema, even after a repair attempt. */
class StructuredOutputException extends AgentException
{
    /** @param  list<string>  $errors */
    public function __construct(string $message, public readonly array $errors = [], public readonly string $rawOutput = '')
    {
        parent::__construct($message);
    }
}
