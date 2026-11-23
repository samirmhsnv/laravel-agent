<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Support;

/**
 * A small JSON schema validator covering the subset this package generates
 * and the subset providers accept for structured output:
 * type (incl. type lists), properties, required, additionalProperties,
 * items, enum, const, anyOf, minItems/maxItems, minLength/maxLength,
 * minimum/maximum.
 *
 * It is not a full JSON Schema implementation ($ref, formats and pattern are ignored).
 */
final class JsonSchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> Human-readable errors; empty when the value is valid.
     */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                if (self::validate($value, $option, $path) === []) {
                    return [];
                }
            }

            return ["{$path} does not match any of the allowed shapes."];
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return ["{$path} must equal ".json_encode($schema['const']).'.'];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$path} must be one of ".json_encode($schema['enum']).'.'];
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $matched = array_values(array_filter($types, fn (string $type) => self::isType($value, $type)));

            if ($matched === []) {
                return ["{$path} must be of type ".implode('|', $types).', got '.self::typeOf($value).'.'];
            }

            return match ($matched[0]) {
                'object' => self::validateObject($value, $schema, $path),
                'array' => self::validateArray($value, $schema, $path),
                'string' => self::validateString($value, $schema, $path),
                'integer', 'number' => self::validateNumber($value, $schema, $path),
                default => [],
            };
        }

        return [];
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            is_array($value) => array_is_list($value) && $value !== [] ? 'array' : 'object',
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function validateObject(array $value, array $schema, string $path): array
    {
        $errors = [];
        $properties = $schema['properties'] ?? [];

        foreach ($schema['required'] ?? [] as $name) {
            if (! array_key_exists($name, $value)) {
                $errors[] = "{$path}.{$name} is required.";
            }
        }

        foreach ($value as $name => $item) {
            if (isset($properties[$name])) {
                array_push($errors, ...self::validate($item, $properties[$name], "{$path}.{$name}"));
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = "{$path}.{$name} is not an allowed property.";
            }
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $value
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function validateArray(array $value, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            $errors[] = "{$path} must have at least {$schema['minItems']} items.";
        }

        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            $errors[] = "{$path} must have at most {$schema['maxItems']} items.";
        }

        if (isset($schema['items'])) {
            foreach ($value as $index => $item) {
                array_push($errors, ...self::validate($item, $schema['items'], "{$path}[{$index}]"));
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function validateString(string $value, array $schema, string $path): array
    {
        $length = mb_strlen($value);

        return array_values(array_filter([
            isset($schema['minLength']) && $length < $schema['minLength'] ? "{$path} must be at least {$schema['minLength']} characters." : null,
            isset($schema['maxLength']) && $length > $schema['maxLength'] ? "{$path} must be at most {$schema['maxLength']} characters." : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function validateNumber(int|float $value, array $schema, string $path): array
    {
        return array_values(array_filter([
            isset($schema['minimum']) && $value < $schema['minimum'] ? "{$path} must be >= {$schema['minimum']}." : null,
            isset($schema['maximum']) && $value > $schema['maximum'] ? "{$path} must be <= {$schema['maximum']}." : null,
        ]));
    }
}
