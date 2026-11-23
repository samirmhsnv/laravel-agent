<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Llm;

final readonly class ToolDefinition
{
    /** @param  array<string, mixed>  $parameters  JSON schema, type: object */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
