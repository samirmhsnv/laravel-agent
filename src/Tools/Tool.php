<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Tools;

use ReflectionClass;
use ReflectionMethod;
use SamirMhsnv\LaravelAgent\Attributes\AsTool;
use SamirMhsnv\LaravelAgent\Contracts\Tool as ToolContract;
use SamirMhsnv\LaravelAgent\Exceptions\InvalidToolDefinition;
use SamirMhsnv\LaravelAgent\Support\Hydrator;
use SamirMhsnv\LaravelAgent\Support\SchemaBuilder;

/**
 * Base class for tools defined with an attribute and a typed handle() method.
 *
 *     #[AsTool(name: 'get_weather', description: 'Current weather for a city.')]
 *     final class GetWeather extends Tool
 *     {
 *         public function handle(string $city, Unit $unit = Unit::Celsius): array { ... }
 *     }
 *
 * The JSON schema is generated from handle()'s parameters, and the model's
 * arguments are validated and cast before handle() is called.
 */
abstract class Tool implements ToolContract
{
    private ?AsTool $meta = null;

    /** @var array<string, mixed>|null */
    private ?array $schema = null;

    public function name(): string
    {
        return $this->meta()->name;
    }

    public function description(): string
    {
        return $this->meta()->description;
    }

    public function parameters(): array
    {
        return $this->schema ??= SchemaBuilder::forFunction($this->handleMethod());
    }

    public function execute(array $arguments): mixed
    {
        return $this->handle(...Hydrator::argumentsFor($this->handleMethod(), $arguments));
    }

    private function meta(): AsTool
    {
        if ($this->meta !== null) {
            return $this->meta;
        }

        $attribute = (new ReflectionClass($this))->getAttributes(AsTool::class)[0] ?? null;

        if ($attribute === null) {
            throw new InvalidToolDefinition(sprintf(
                'Tool [%s] needs an #[AsTool(name: ..., description: ...)] attribute, or override name() and description().',
                static::class,
            ));
        }

        return $this->meta = $attribute->newInstance();
    }

    private function handleMethod(): ReflectionMethod
    {
        if (! method_exists($this, 'handle')) {
            throw new InvalidToolDefinition(sprintf('Tool [%s] must define a public handle() method.', static::class));
        }

        return new ReflectionMethod($this, 'handle');
    }
}
