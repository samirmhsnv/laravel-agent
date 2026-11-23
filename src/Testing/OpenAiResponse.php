<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Testing;

/**
 * Builds OpenAI Chat Completions response bodies for Http::fake().
 */
final class OpenAiResponse
{
    private static int $counter = 0;

    /** @return array<string, mixed> */
    public static function text(string $text, int $promptTokens = 100, int $completionTokens = 20, string $model = 'gpt-5'): array
    {
        return self::completion(['role' => 'assistant', 'content' => $text], 'stop', $promptTokens, $completionTokens, $model);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function toolCall(string $name, array $arguments = [], ?string $id = null, int $promptTokens = 100, int $completionTokens = 30): array
    {
        return self::toolCalls([[$name, $arguments, $id]], $promptTokens, $completionTokens);
    }

    /**
     * @param  list<array{0: string, 1?: array<string, mixed>, 2?: string|null}>  $calls  [name, arguments, id]
     * @return array<string, mixed>
     */
    public static function toolCalls(array $calls, int $promptTokens = 100, int $completionTokens = 30): array
    {
        $toolCalls = array_map(fn (array $call) => [
            'id' => $call[2] ?? 'call_'.(++self::$counter),
            'type' => 'function',
            'function' => ['name' => $call[0], 'arguments' => json_encode((object) ($call[1] ?? []))],
        ], $calls);

        return self::completion(['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls], 'tool_calls', $promptTokens, $completionTokens);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function completion(array $message, string $finishReason, int $promptTokens, int $completionTokens, string $model = 'gpt-5'): array
    {
        return [
            'id' => 'chatcmpl-'.bin2hex(random_bytes(6)),
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finishReason]],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ];
    }
}
