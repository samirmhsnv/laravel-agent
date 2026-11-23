<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use SamirMhsnv\LaravelAgent\Contracts\LlmProvider;
use SamirMhsnv\LaravelAgent\Guards\PolicyGuard;
use SamirMhsnv\LaravelAgent\Llm\AnthropicProvider;
use SamirMhsnv\LaravelAgent\Llm\CostCalculator;
use SamirMhsnv\LaravelAgent\Llm\OpenAiProvider;

/**
 * Entry point behind the Agent facade: builds agents and resolves providers.
 */
class AgentManager
{
    /** @var array<string, LlmProvider> */
    protected array $providers = [];

    /** @var array<string, Closure(Container, array<string, mixed>, string): LlmProvider> */
    protected array $drivers = [];

    public function __construct(protected Container $container) {}

    /** Start configuring a new agent. */
    public function make(): Agent
    {
        $config = $this->config();

        $agent = new Agent(
            $this,
            $this->container,
            $this->container->make(Dispatcher::class),
            new CostCalculator($config['pricing'] ?? []),
            $config,
            $this->container->make('log')->channel($config['logging']['channel'] ?? null),
        );

        $policy = $config['policy'] ?? [];

        if (! empty($policy['deny']) || ! empty($policy['require_approval'])) {
            $agent->guard(new PolicyGuard($policy['deny'] ?? [], $policy['require_approval'] ?? []));
        }

        foreach ($config['guards'] ?? [] as $guard) {
            $agent->guard($guard);
        }

        return $agent;
    }

    /** Resolve a configured provider by name (default from config when null). */
    public function provider(?string $name = null): LlmProvider
    {
        $name ??= $this->defaultProviderName();

        return $this->providers[$name] ??= $this->createProvider($name);
    }

    /**
     * Register a custom driver:
     *
     *     Agent::extend('gemini', fn ($app, array $config, string $name) => new GeminiProvider(...));
     *
     * @param  Closure(Container, array<string, mixed>, string): LlmProvider  $factory
     */
    public function extend(string $driver, Closure $factory): static
    {
        $this->drivers[$driver] = $factory;

        return $this;
    }

    /** Use a ready-made provider instance under a name (handy in tests). */
    public function setProvider(string $name, LlmProvider $provider): static
    {
        $this->providers[$name] = $provider;

        return $this;
    }

    public function defaultProviderName(): string
    {
        return $this->config()['default'] ?? 'anthropic';
    }

    /** Forget resolved providers, e.g. after changing config at runtime. */
    public function forgetProviders(): static
    {
        $this->providers = [];

        return $this;
    }

    protected function createProvider(string $name): LlmProvider
    {
        $config = $this->config()['providers'][$name] ?? null;

        if (! is_array($config)) {
            throw new InvalidArgumentException("Agent provider [{$name}] is not configured in config/agent.php.");
        }

        $driver = $config['driver'] ?? $name;

        if (isset($this->drivers[$driver])) {
            return ($this->drivers[$driver])($this->container, $config, $name);
        }

        $http = $this->container->make(HttpFactory::class);

        return match ($driver) {
            'anthropic' => new AnthropicProvider($http, $config, $name),
            'openai' => new OpenAiProvider($http, $config, $name),
            default => throw new InvalidArgumentException("Agent driver [{$driver}] is not supported."),
        };
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        return $this->container->make('config')->get('agent', []);
    }
}
