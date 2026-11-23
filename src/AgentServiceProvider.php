<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SamirMhsnv\LaravelAgent\Console\ChatCommand;
use SamirMhsnv\LaravelAgent\Sql\SafeSqlTool;

class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent.php', 'agent');

        $this->app->singleton(AgentManager::class, fn (Application $app) => new AgentManager($app));

        $this->app->bind(Agent::class, fn (Application $app) => $app->make(AgentManager::class)->make());

        $this->app->bind(SafeSqlTool::class, fn (Application $app) => SafeSqlTool::fromConfig(
            $app->make('db'),
            $app->make('config')->get('agent.sql', []),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/agent.php' => $this->app->configPath('agent.php'),
            ], 'agent-config');

            $this->commands([ChatCommand::class]);
        }
    }
}
