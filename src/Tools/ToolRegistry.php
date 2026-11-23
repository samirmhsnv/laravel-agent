<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Tools;

use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use SamirMhsnv\LaravelAgent\Attributes\RequiresApproval;
use SamirMhsnv\LaravelAgent\Contracts\Tool;
use SamirMhsnv\LaravelAgent\Exceptions\InvalidToolDefinition;
use SamirMhsnv\LaravelAgent\Llm\ToolDefinition;

/**
 * The set of tools one agent may call, keyed by name.
 */
final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct(private readonly ?Container $container = null) {}

    /** @param  Tool|class-string<Tool>  $tool */
    public function add(Tool|string $tool): self
    {
        if (is_string($tool)) {
            $tool = $this->container?->make($tool) ?? new $tool;
        }

        if (! $tool instanceof Tool) {
            throw new InvalidToolDefinition(sprintf('[%s] does not implement %s.', get_debug_type($tool), Tool::class));
        }

        $name = $tool->name();

        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name) !== 1) {
            throw new InvalidToolDefinition("Tool name [{$name}] must be 1-64 letters, digits, underscores or dashes.");
        }

        if (isset($this->tools[$name]) && $tool::class !== $this->tools[$name]::class) {
            throw new InvalidToolDefinition("Two different tools are registered as [{$name}].");
        }

        $this->tools[$name] = $tool;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->tools;
    }

    /** @return list<ToolDefinition> */
    public function definitions(): array
    {
        return array_values(array_map(
            fn (Tool $tool) => new ToolDefinition($tool->name(), $tool->description(), $tool->parameters()),
            $this->tools,
        ));
    }

    /** The #[RequiresApproval] attribute of a tool, if it has one. */
    public static function approvalRequirement(Tool $tool): ?RequiresApproval
    {
        $attribute = (new ReflectionClass($tool))->getAttributes(RequiresApproval::class)[0] ?? null;

        return $attribute?->newInstance();
    }
}
