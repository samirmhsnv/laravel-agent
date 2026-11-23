<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Support;

use JsonException;
use stdClass;
use Stringable;

final class Json
{
    /**
     * PHP arrays lose the difference between {} and []. Before a schema or a
     * tool input goes on the wire, turn empty "object" arrays back into objects.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function wireSchema(array $schema): array
    {
        if (array_key_exists('properties', $schema)) {
            $schema['properties'] = $schema['properties'] === []
                ? new stdClass
                : array_map(fn ($s) => is_array($s) ? self::wireSchema($s) : $s, $schema['properties']);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::wireSchema($schema['items']);
        }

        if (isset($schema['anyOf'])) {
            $schema['anyOf'] = array_map(self::wireSchema(...), $schema['anyOf']);
        }

        return $schema;
    }

    /** @param  array<string, mixed>  $value */
    public static function object(array $value): array|stdClass
    {
        return $value === [] ? new stdClass : $value;
    }

    /** Encode a tool return value for the model. */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value instanceof Stringable => (string) $value,
            $value === null => 'null',
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        };
    }

    /**
     * Pull a JSON value out of model text. Tolerates ```json fences and
     * a sentence of preamble, which models still produce now and then.
     */
    public static function extract(string $text): mixed
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $match) === 1) {
            $text = $match[1];
        }

        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Fall back to the first {...} or [...] span.
        }

        $start = strcspn($text, '{[');
        $end = max(strrpos($text, '}') ?: -1, strrpos($text, ']') ?: -1);

        if ($start >= strlen($text) || $end < $start) {
            throw new JsonException('No JSON value found in the response.');
        }

        return json_decode(substr($text, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
    }
}
