<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Testing;

/**
 * Builds Anthropic Messages API response bodies for Http::fake().
 *
 *     Http::fake(['api.anthropic.com/*' => Http::sequence()
 *         ->push(AnthropicResponse::toolUse('get_weather', ['city' => 'Baku']))
 *         ->push(AnthropicResponse::text('It is 21°C in Baku.')),
 *     ]);
 */
final class AnthropicResponse
{
    private static int $counter = 0;

    /** @return array<string, mixed> */
    public static function text(string $text, int $inputTokens = 100, int $outputTokens = 20, string $model = 'claude-sonnet-5-5', string $stopReason = 'end_turn'): array
    {
        return self::message([['type' => 'text', 'text' => $text]], $stopReason, $inputTokens, $outputTokens, $model);
    }

    /**
     * One tool call. For several calls in one turn, use toolUses().
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function toolUse(string $name, array $input = [], ?string $id = null, ?string $text = null, int $inputTokens = 100, int $outputTokens = 30): array
    {
        return self::toolUses([[$name, $input, $id]], $text, $inputTokens, $outputTokens);
    }

    /**
     * @param  list<array{0: string, 1?: array<string, mixed>, 2?: string|null}>  $calls  [name, input, id]
     * @return array<string, mixed>
     */
    public static function toolUses(array $calls, ?string $text = null, int $inputTokens = 100, int $outputTokens = 30): array
    {
        $content = $text === null ? [] : [['type' => 'text', 'text' => $text]];

        foreach ($calls as $call) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $call[2] ?? 'toolu_'.(++self::$counter),
                'name' => $call[0],
                'input' => (object) ($call[1] ?? []),
            ];
        }

        return self::message($content, 'tool_use', $inputTokens, $outputTokens);
    }

    /** @return array<string, mixed> */
    public static function refusal(): array
    {
        return self::message([], 'refusal', 50, 0);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    public static function message(array $content, string $stopReason, int $inputTokens, int $outputTokens, string $model = 'claude-sonnet-5-5'): array
    {
        return [
            'id' => 'msg_'.bin2hex(random_bytes(6)),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }
}
