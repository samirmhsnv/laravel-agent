<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Messages;

final readonly class ToolResult
{
    public function __construct(
        public string $toolCallId,
        public string $name,
        public string $content,
        public bool $isError = false,
    ) {}

    public static function error(ToolCall $call, string $message): self
    {
        return new self($call->id, $call->name, $message, true);
    }

    /** @return array{tool_call_id: string, name: string, content: string, is_error: bool} */
    public function toArray(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'name' => $this->name,
            'content' => $this->content,
            'is_error' => $this->isError,
        ];
    }

    /** @param  array{tool_call_id: string, name: string, content: string, is_error?: bool}  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['tool_call_id'], $data['name'], $data['content'], $data['is_error'] ?? false);
    }
}
