<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Support;

use BackedEnum;
use ReflectionClass;
use ReflectionEnum;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use SamirMhsnv\LaravelAgent\Attributes\Param;
use SamirMhsnv\LaravelAgent\Exceptions\InvalidToolArguments;

/**
 * Turns model-provided JSON arguments into PHP values for a typed signature.
 *
 * Coercion is deliberately conservative: "3" becomes 3 for an int parameter,
 * but "three" is an error the model gets to see and fix.
 */
final class Hydrator
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> Named arguments, ready for a call with the spread operator.
     */
    public static function argumentsFor(ReflectionFunctionAbstract $function, array $arguments, string $path = ''): array
    {
        $resolved = [];

        foreach ($function->getParameters() as $parameter) {
            $name = $parameter->getName();
            $label = ltrim($path.'.'.$name, '.');

            if (array_key_exists($name, $arguments)) {
                $resolved[$name] = self::coerce($arguments[$name], $parameter, $label);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $resolved[$name] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $resolved[$name] = null;
            } else {
                throw new InvalidToolArguments("Missing required argument [{$label}].");
            }
        }

        return $resolved;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $data
     * @return T
     */
    public static function hydrate(string $class, array $data, string $path = ''): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        return $reflection->newInstanceArgs(self::argumentsFor($constructor, $data, $path));
    }

    private static function coerce(mixed $value, ReflectionParameter $parameter, string $label): mixed
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }

            throw new InvalidToolArguments("Argument [{$label}] cannot be null.");
        }

        $items = ($parameter->getAttributes(Param::class)[0] ?? null)?->newInstance()->items;

        return self::coerceTo($type->getName(), $value, $label, $items);
    }

    private static function coerceTo(string $type, mixed $value, string $label, ?string $items = null): mixed
    {
        $fail = fn (string $expected) => new InvalidToolArguments(
            sprintf('Argument [%s] must be %s, got %s.', $label, $expected, json_encode($value) ?: get_debug_type($value))
        );

        return match (true) {
            $type === 'mixed' => $value,
            $type === 'string' => is_scalar($value) && ! is_bool($value) ? (string) $value : throw $fail('a string'),
            $type === 'int' => match (true) {
                is_int($value) => $value,
                is_float($value) && floor($value) === $value => (int) $value,
                is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
                default => throw $fail('an integer'),
            },
            $type === 'float' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                ? (float) $value
                : throw $fail('a number'),
            $type === 'bool' => match (true) {
                is_bool($value) => $value,
                in_array($value, ['true', 1, '1'], true) => true,
                in_array($value, ['false', 0, '0'], true) => false,
                default => throw $fail('a boolean'),
            },
            $type === 'array' => is_array($value) ? self::coerceItems($value, $label, $items) : throw $fail('an array'),
            enum_exists($type) && is_subclass_of($type, BackedEnum::class) => self::coerceEnum($type, $value, $label),
            class_exists($type) => is_array($value) ? self::hydrate($type, $value, $label) : throw $fail('an object'),
            default => $value,
        };
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function coerceItems(array $values, string $label, ?string $items): array
    {
        $map = ['integer' => 'int', 'number' => 'float', 'boolean' => 'bool', 'string' => 'string', 'object' => 'array'];
        $type = $items === null ? null : ($map[$items] ?? $items);

        if ($type === null) {
            return $values;
        }

        foreach ($values as $key => $value) {
            $values[$key] = self::coerceTo($type, $value, "{$label}.{$key}");
        }

        return $values;
    }

    /** @param  class-string<BackedEnum>  $enum */
    private static function coerceEnum(string $enum, mixed $value, string $label): BackedEnum
    {
        $intBacked = (string) (new ReflectionEnum($enum))->getBackingType() === 'int';

        if ($intBacked && is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (($intBacked && is_int($value)) || (! $intBacked && is_string($value))) {
            $case = $enum::tryFrom($value);

            if ($case !== null) {
                return $case;
            }
        }

        $allowed = implode(', ', array_map(fn (BackedEnum $c) => json_encode($c->value), $enum::cases()));

        throw new InvalidToolArguments("Argument [{$label}] must be one of: {$allowed}.");
    }
}
