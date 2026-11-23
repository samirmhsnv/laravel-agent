<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Llm;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use SamirMhsnv\LaravelAgent\Contracts\LlmProvider;
use SamirMhsnv\LaravelAgent\Exceptions\ProviderException;
use SamirMhsnv\LaravelAgent\Messages\Message;
use SamirMhsnv\LaravelAgent\Messages\Role;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;
use SamirMhsnv\LaravelAgent\Messages\ToolResult;
use SamirMhsnv\LaravelAgent\Support\Json;

/**
 * Anthropic Messages API (POST /v1/messages) with client-side tools.
 *
 * Assistant turns are replayed with their original content blocks, so
 * thinking blocks go back to the API unchanged, as it requires.
 */
final class AnthropicProvider implements LlmProvider
{
    /** @param  array<string, mixed>  $config */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
        private readonly string $name = 'anthropic',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function defaultModel(): string
    {
        return $this->config['model'] ?? 'claude-sonnet-5-5';
    }

    public function chat(LlmRequest $request): LlmResponse
    {
        $response = $this->client()->post('/messages', $this->payload($request));

        if ($response->failed()) {
            throw ProviderException::fromResponse('Anthropic', $response);
        }

        return $this->parse($response->json() ?? []);
    }

    /** @return array<string, mixed> */
    public function payload(LlmRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens ?? $this->config['max_tokens'] ?? 16000,
            'messages' => $this->messages($request->messages),
        ];

        if ($request->system !== null && $request->system !== '') {
            $payload['system'] = $request->system;
        }

        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn (ToolDefinition $tool) => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => Json::wireSchema($tool->parameters),
            ], $request->tools);
        }

        $outputConfig = [];

        if (! empty($this->config['effort'])) {
            $outputConfig['effort'] = $this->config['effort'];
        }

        if ($request->responseSchema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => Json::wireSchema($request->responseSchema)];
        }

        if ($outputConfig !== []) {
            $payload['output_config'] = $outputConfig;
        }

        return $payload;
    }

    /**
     * @param  list<Message>  $messages
     * @return list<array<string, mixed>>
     */
    private function messages(array $messages): array
    {
        $wire = [];

        foreach ($messages as $message) {
            $wire[] = match ($message->role) {
                Role::User => ['role' => 'user', 'content' => $message->content],
                Role::Assistant => ['role' => 'assistant', 'content' => $this->assistantContent($message)],
                Role::ToolResults => ['role' => 'user', 'content' => array_map(fn (ToolResult $result) => array_filter([
                    'type' => 'tool_result',
                    'tool_use_id' => $result->toolCallId,
                    'content' => $result->content,
                    'is_error' => $result->isError ?: null,
                ], fn ($v) => $v !== null), $message->toolResults)],
            };
        }

        return $wire;
    }

    /** @return list<array<string, mixed>> */
    private function assistantContent(Message $message): array
    {
        $raw = $message->rawContentFor($this->name);

        if ($raw !== null) {
            // JSON round trips turn an empty tool input {} into []; restore it.
            return array_map(function (array $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $block['input'] = Json::object($block['input'] ?? []);
                }

                return $block;
            }, $raw);
        }

        $blocks = [];

        if ($message->content !== '') {
            $blocks[] = ['type' => 'text', 'text' => $message->content];
        }

        foreach ($message->toolCalls as $call) {
            $blocks[] = ['type' => 'tool_use', 'id' => $call->id, 'name' => $call->name, 'input' => Json::object($call->arguments)];
        }

        return $blocks;
    }

    /** @param  array<string, mixed>  $data */
    private function parse(array $data): LlmResponse
    {
        if (! isset($data['content']) || ! is_array($data['content'])) {
            throw new ProviderException('Anthropic returned a response without content.');
        }

        $text = [];
        $calls = [];

        foreach ($data['content'] as $block) {
            match ($block['type'] ?? null) {
                'text' => $text[] = $block['text'],
                'tool_use' => $calls[] = new ToolCall($block['id'], $block['name'], (array) ($block['input'] ?? [])),
                default => null, // thinking and other blocks are kept in rawContent only
            };
        }

        $usage = $data['usage'] ?? [];

        return new LlmResponse(
            text: trim(implode("\n", $text)),
            toolCalls: $calls,
            usage: new Usage(
                (int) ($usage['input_tokens'] ?? 0),
                (int) ($usage['output_tokens'] ?? 0),
                (int) ($usage['cache_read_input_tokens'] ?? 0),
                (int) ($usage['cache_creation_input_tokens'] ?? 0),
            ),
            stopReason: match ($data['stop_reason'] ?? null) {
                'end_turn', 'stop_sequence' => StopReason::EndTurn,
                'tool_use' => StopReason::ToolUse,
                'max_tokens' => StopReason::MaxTokens,
                'refusal' => StopReason::Refusal,
                default => StopReason::Other,
            },
            model: $data['model'] ?? $this->defaultModel(),
            provider: $this->name,
            rawContent: $data['content'],
        );
    }

    private function client(): PendingRequest
    {
        $key = $this->config['api_key'] ?? null;

        if (empty($key)) {
            throw ProviderException::missingApiKey($this->name);
        }

        return $this->http
            ->baseUrl(rtrim($this->config['base_url'] ?? 'https://api.anthropic.com/v1', '/'))
            ->withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => $this->config['version'] ?? '2026-10-21',
            ])
            ->acceptJson()
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 120))
            ->retry(
                (int) ($this->config['retries'] ?? 2) + 1,
                fn (int $attempt) => $attempt * 1000,
                fn ($exception) => Retry::shouldRetry($exception),
                throw: false,
            );
    }
}
