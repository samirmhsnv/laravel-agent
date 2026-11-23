<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Llm;

use SamirMhsnv\LaravelAgent\Messages\Message;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;

final readonly class LlmResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  array<mixed>  $rawContent  The provider's content blocks, kept for verbatim replay.
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public Usage $usage,
        public StopReason $stopReason,
        public string $model,
        public string $provider,
        public array $rawContent = [],
    ) {}

    public function toMessage(): Message
    {
        return Message::assistant(
            $this->text,
            $this->toolCalls,
            $this->rawContent === [] ? [] : ['provider' => $this->provider, 'content' => $this->rawContent],
        );
    }
}
