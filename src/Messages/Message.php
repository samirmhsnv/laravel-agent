<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Messages;

/**
 * A provider-neutral conversation turn.
 *
 * Assistant messages may carry the provider's raw content blocks in
 * $providerContent. Providers that need to replay them verbatim (Anthropic
 * requires thinking blocks to be sent back unchanged) use them; others ignore them.
 */
final readonly class Message
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolResult>  $toolResults
     * @param  array{provider?: string, content?: array<mixed>}  $providerContent
     */
    public function __construct(
        public Role $role,
        public string $content = '',
        public array $toolCalls = [],
        public array $toolResults = [],
        public array $providerContent = [],
    ) {}

    public static function user(string $content): self
    {
        return new self(Role::User, $content);
    }

    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  array{provider?: string, content?: array<mixed>}  $providerContent
     */
    public static function assistant(string $content, array $toolCalls = [], array $providerContent = []): self
    {
        return new self(Role::Assistant, $content, $toolCalls, providerContent: $providerContent);
    }

    /** @param  list<ToolResult>  $results */
    public static function toolResults(array $results): self
    {
        return new self(Role::ToolResults, toolResults: $results);
    }

    /** Raw provider blocks, but only if they were produced by the given provider. */
    public function rawContentFor(string $provider): ?array
    {
        if (($this->providerContent['provider'] ?? null) !== $provider) {
            return null;
        }

        return $this->providerContent['content'] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'role' => $this->role->value,
            'content' => $this->content,
            'tool_calls' => array_map(fn (ToolCall $c) => $c->toArray(), $this->toolCalls),
            'tool_results' => array_map(fn (ToolResult $r) => $r->toArray(), $this->toolResults),
            'provider_content' => $this->providerContent,
        ], fn ($value) => $value !== [] && $value !== '');
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Role::from($data['role']),
            $data['content'] ?? '',
            array_map(ToolCall::fromArray(...), $data['tool_calls'] ?? []),
            array_map(ToolResult::fromArray(...), $data['tool_results'] ?? []),
            $data['provider_content'] ?? [],
        );
    }
}
