<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Facades;

use Illuminate\Support\Facades\Facade;
use SamirMhsnv\LaravelAgent\AgentManager;

/**
 * @method static \SamirMhsnv\LaravelAgent\Agent make()
 * @method static \SamirMhsnv\LaravelAgent\Contracts\LlmProvider provider(?string $name = null)
 * @method static AgentManager extend(string $driver, \Closure $factory)
 * @method static AgentManager setProvider(string $name, \SamirMhsnv\LaravelAgent\Contracts\LlmProvider $provider)
 * @method static string defaultProviderName()
 *
 * @see AgentManager
 */
final class Agent extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AgentManager::class;
    }
}
