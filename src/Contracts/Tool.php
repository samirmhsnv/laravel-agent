<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Contracts;

interface Tool
{
    /** The name the model uses to call the tool. Letters, digits, underscores and dashes. */
    public function name(): string;

    /** What the tool does and when to use it. The model relies on this heavily. */
    public function description(): string;

    /**
     * JSON schema (type: object) describing the arguments.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Run the tool. The return value is sent back to the model: strings as-is,
     * everything else JSON-encoded.
     *
     * Throw a ToolException to report a problem the model should see and react to.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): mixed;
}
