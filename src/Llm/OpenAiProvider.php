<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Llm;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use JsonException;
use SamirMhsnv\LaravelAgent\Contracts\LlmProvider;
use SamirMhsnv\LaravelAgent\Exceptions\ProviderException;
use SamirMhsnv\LaravelAgent\Messages\Message;
use SamirMhsnv\LaravelAgent\Messages\Role;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;
use SamirMhsnv\LaravelAgent\Messages\ToolResult;
use SamirMhsnv\LaravelAgent\Support\Json;

/**
 * OpenAI Chat Completions (POST /v1/chat/completions) with function tools.
 *
 * Also works with OpenAI-compatible servers (vLLM, Ollama, OpenRouter, ...)
 * through the base_url option, as long as they implement tool calls.
 */
final class OpenAiProvider implements LlmProvider
{
    /** @param  array<string, mixed>  $config */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
        private readonly string $name = 'openai',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function defaultModel(): string
    {
        return $this->config['model'] ?? 'gpt-5';
    }

    public function chat(LlmRequest $request): LlmResponse
    {
        $response = $this->client()->post('/chat/completions', $this->payload($request));

        if ($response->failed()) {
            throw ProviderException::fromResponse('OpenAI', $response);
        }

        return $this->parse($response->json() ?? []);
    }

    /** @return array<string, mixed> */
    public function payload(LlmRequest $request): array
    {
        $messages = [];

        if ($request->system !== null && $request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }

        foreach ($request->messages as $message) {
            array_push($messages, ...$this->toWire($message));
        }

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'max_completion_tokens' => $request->maxTokens ?? $this->config['max_tokens'] ?? 16000,
        ];

        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn (ToolDefinition $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => Json::wireSchema($tool->parameters),
                ],
            ], $request->tools);
        }

        if ($request->responseSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'response',
                    'schema' => Json::wireSchema($request->responseSchema),
                    // strict mode would require every property to be listed as required.
                    'strict' => false,
                ],
            ];
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function toWire(Message $message): array
    {
        return match ($message->role) {
            Role::User => [['role' => 'user', 'content' => $message->content]],
            Role::Assistant => [array_filter([
                'role' => 'assistant',
                'content' => $message->content === '' ? null : $message->content,
                'tool_calls' => array_map(fn (ToolCall $call) => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        'arguments' => json_encode(Json::object($call->arguments), JSON_UNESCAPED_UNICODE),
                    ],
                ], $message->toolCalls) ?: null,
            ], fn ($value) => $value !== null) + ['content' => null]],
            Role::ToolResults => array_map(fn (ToolResult $result) => [
                'role' => 'tool',
                'tool_call_id' => $result->toolCallId,
                'content' => $result->isError ? "Error: {$result->content}" : $result->content,
            ], $message->toolResults),
        };
    }

    /** @param  array<string, mixed>  $data */
    private function parse(array $data): LlmResponse
    {
        $choice = $data['choices'][0] ?? null;

        if (! is_array($choice) || ! isset($choice['message'])) {
            throw new ProviderException('OpenAI returned a response without choices.');
        }

        $message = $choice['message'];
        $calls = [];

        foreach ($message['tool_calls'] ?? [] as $call) {
            try {
                $arguments = json_decode($call['function']['arguments'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // Leave it to the tool to complain; the model gets an error result and can retry.
                $arguments = ['__invalid_json' => $call['function']['arguments']];
            }

            $calls[] = new ToolCall($call['id'], $call['function']['name'], (array) $arguments);
        }

        $usage = $data['usage'] ?? [];
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

        return new LlmResponse(
            text: trim((string) ($message['content'] ?? '')),
            toolCalls: $calls,
            usage: new Usage(
                max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cached),
                (int) ($usage['completion_tokens'] ?? 0),
                $cached,
            ),
            stopReason: match (true) {
                $calls !== [] => StopReason::ToolUse,
                ($message['refusal'] ?? null) !== null => StopReason::Refusal,
                ($choice['finish_reason'] ?? null) === 'length' => StopReason::MaxTokens,
                ($choice['finish_reason'] ?? null) === 'stop' => StopReason::EndTurn,
                default => StopReason::Other,
            },
            model: $data['model'] ?? $this->defaultModel(),
            provider: $this->name,
        );
    }

    private function client(): PendingRequest
    {
        $key = $this->config['api_key'] ?? null;

        if (empty($key)) {
            throw ProviderException::missingApiKey($this->name);
        }

        $request = $this->http
            ->baseUrl(rtrim($this->config['base_url'] ?? 'https://api.openai.com/v1', '/'))
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 120))
            ->retry(
                (int) ($this->config['retries'] ?? 2) + 1,
                fn (int $attempt) => $attempt * 1000,
                fn ($exception) => Retry::shouldRetry($exception),
                throw: false,
            );

        if (! empty($this->config['organization'])) {
            $request->withHeaders(['OpenAI-Organization' => $this->config['organization']]);
        }

        return $request;
    }
}
