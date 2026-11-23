<?php

namespace SamirMhsnv\LaravelAgent\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SamirMhsnv\LaravelAgent\AgentServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AgentServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Agent' => \SamirMhsnv\LaravelAgent\Facades\Agent::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('agent.providers.anthropic.api_key', 'test-anthropic-key');
        $app['config']->set('agent.providers.anthropic.retries', 0);
        $app['config']->set('agent.providers.openai.api_key', 'test-openai-key');
        $app['config']->set('agent.providers.openai.retries', 0);
        $app['config']->set('agent.logging.enabled', false);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
