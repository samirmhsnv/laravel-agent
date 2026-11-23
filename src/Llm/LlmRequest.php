<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Llm;

use SamirMhsnv\LaravelAgent\Messages\Message;

final readonly class LlmRequest
{
    /**
     * @param  list<Message>  $messages
     * @param  list<ToolDefinition>  $tools
     * @param  array<string, mixed>|null  $responseSchema  Ask the provider for JSON matching this schema, if it supports it.
     */
    public function __construct(
        public string $model,
        public array $messages,
        public ?string $system = null,
        public array $tools = [],
        public ?int $maxTokens = null,
        public ?array $responseSchema = null,
    ) {}
}
