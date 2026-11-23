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
use SamirMhsnv\LaravelAgent\Exceptions\InvalidToolDefinition;

/**
 * Builds JSON schemas from PHP signatures.
 *
 *   string -> string, int -> integer, float -> number, bool -> boolean,
 *   array -> array (item type from #[Param(items: ...)]), BackedEnum -> enum,
 *   a class with a constructor -> nested object (its promoted parameters).
 *
 * Parameters with a default value or a nullable type are optional.
 */
final class SchemaBuilder
{
    private const SCALARS = [
        'string' => 'string',
        'int' => 'integer',
        'float' => 'number',
        'bool' => 'boolean',
        'array' => 'array',
    ];

    /** @return array<string, mixed> */
    public static function forFunction(ReflectionFunctionAbstract $function): array
    {
        return self::objectSchema($function->getParameters(), $function->getName(), []);
    }

    /**
     * Schema for a DTO: the parameters of its constructor.
     *
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    public static function forClass(string $class): array
    {
        return self::classSchema($class, []);
    }

    /**
     * @param  class-string  $class
     * @param  list<class-string>  $seen
     * @return array<string, mixed>
     */
    private static function classSchema(string $class, array $seen): array
    {
        if (in_array($class, $seen, true)) {
            throw new InvalidToolDefinition("Recursive DTO [{$class}] cannot be turned into a JSON schema.");
        }

        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            throw new InvalidToolDefinition("[{$class}] needs a constructor to be used as a schema.");
        }

        return self::objectSchema($constructor->getParameters(), $class, [...$seen, $class]);
    }

    /**
     * @param  list<ReflectionParameter>  $parameters
     * @param  list<class-string>  $seen
     * @return array<string, mixed>
     */
    private static function objectSchema(array $parameters, string $context, array $seen): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic()) {
                throw new InvalidToolDefinition("Variadic parameter \${$parameter->getName()} in [{$context}] is not supported.");
            }

            $properties[$parameter->getName()] = self::forParameter($parameter, $context, $seen);

            if (! $parameter->isOptional() && ! $parameter->allowsNull()) {
                $required[] = $parameter->getName();
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $schema['additionalProperties'] = false;

        return $schema;
    }

    /**
     * @param  list<class-string>  $seen
     * @return array<string, mixed>
     */
    private static function forParameter(ReflectionParameter $parameter, string $context, array $seen): array
    {
        $type = $parameter->getType();
        $where = "\${$parameter->getName()} in [{$context}]";

        if (! $type instanceof ReflectionNamedType) {
            throw new InvalidToolDefinition("Parameter {$where} needs a single, non-union type.");
        }

        $param = self::paramAttribute($parameter);
        $schema = self::forType($type->getName(), $param, $where, $seen);

        if ($type->allowsNull() && $type->getName() !== 'mixed') {
            $schema['type'] = [$schema['type'], 'null'];
        }

        if ($param?->description !== null) {
            $schema = ['description' => $param->description, ...$schema];
        }

        return $schema;
    }

    /**
     * @param  list<class-string>  $seen
     * @return array<string, mixed>
     */
    private static function forType(string $name, ?Param $param, string $where, array $seen): array
    {
        if ($name === 'mixed') {
            throw new InvalidToolDefinition("Parameter {$where} is typed as mixed; give it a concrete type.");
        }

        if (isset(self::SCALARS[$name])) {
            $schema = ['type' => self::SCALARS[$name]];

            if ($name === 'array') {
                $schema['items'] = self::itemsSchema($param?->items ?? 'string', $where, $seen);
            }

            if ($param?->enum !== null) {
                $schema['enum'] = array_values($param->enum);
            }

            return $schema;
        }

        if (enum_exists($name) && is_subclass_of($name, BackedEnum::class)) {
            $backing = (string) (new ReflectionEnum($name))->getBackingType();

            return [
                'type' => $backing === 'int' ? 'integer' : 'string',
                'enum' => array_map(fn (BackedEnum $case) => $case->value, $name::cases()),
            ];
        }

        if (class_exists($name)) {
            return self::classSchema($name, $seen);
        }

        throw new InvalidToolDefinition("Parameter {$where} has unsupported type [{$name}].");
    }

    /**
     * @param  list<class-string>  $seen
     * @return array<string, mixed>
     */
    private static function itemsSchema(string $items, string $where, array $seen): array
    {
        $jsonTypes = ['string', 'integer', 'number', 'boolean', 'object'];

        if (in_array($items, $jsonTypes, true)) {
            return ['type' => $items];
        }

        return self::forType($items, null, $where.' (array items)', $seen);
    }

    private static function paramAttribute(ReflectionParameter $parameter): ?Param
    {
        $attributes = $parameter->getAttributes(Param::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance();
        }

        // Promoted constructor properties may carry the attribute on the property.
        if ($parameter->isPromoted()) {
            $property = $parameter->getDeclaringClass()?->getProperty($parameter->getName());
            $attributes = $property?->getAttributes(Param::class) ?? [];

            return $attributes === [] ? null : $attributes[0]->newInstance();
        }

        return null;
    }
}
