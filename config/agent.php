<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | The provider used when you don't call ->using(...). Must be a key of
    | the "providers" array below.
    |
    */

    'default' => env('AGENT_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each entry has a "driver" (anthropic or openai, or one you register with
    | Agent::extend()). You can define several entries with the same driver,
    | e.g. an OpenAI-compatible local server under its own name.
    |
    */

    'providers' => [

        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'version' => env('ANTHROPIC_VERSION', '2026-10-21'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
            // low | medium | high | xhigh | max. Null leaves the model's default.
            'effort' => env('ANTHROPIC_EFFORT'),
            'timeout' => 120,
            'retries' => 2,
        ],

        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-5'),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 16000),
            'timeout' => 120,
            'retries' => 2,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Loop
    |--------------------------------------------------------------------------
    |
    | max_steps is the number of model calls allowed per prompt. When it is
    | reached the run stops with status "max_steps_reached".
    |
    */

    'max_steps' => 8,

    'structured_output' => [
        // Also ask the provider for JSON-schema constrained output when expect() is used.
        // Your own schemas then need "additionalProperties": false on every object.
        'native' => true,
        // How many times the model may retry after returning invalid JSON.
        'repair_attempts' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Guardrails
    |--------------------------------------------------------------------------
    |
    | "policy" applies to every agent, by tool name (wildcards allowed).
    | "guards" lists ActionGuard classes resolved from the container and
    | applied to every agent made with Agent::make().
    |
    */

    'policy' => [
        'deny' => [],
        'require_approval' => [],
    ],

    'guards' => [],

    /*
    |--------------------------------------------------------------------------
    | SafeSqlTool
    |--------------------------------------------------------------------------
    |
    | Only the tables listed here can be queried. Point "connection" at a
    | database user that has SELECT permission and nothing else.
    |
    */

    'sql' => [
        'connection' => env('AGENT_SQL_CONNECTION'),
        'tables' => [],
        'max_rows' => 100,
        'max_query_length' => 4000,
        // Include column names and types in the tool description.
        'describe_schema' => true,
        // Run each query in a transaction that is always rolled back.
        'transaction' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | USD per million tokens, used for $run->cost(). Prices change: check
    | your provider's pricing page. Models not listed get a null cost.
    | cache_read defaults to 10% of input, cache_write to 125%.
    |
    */

    'pricing' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | One info line per finished run with steps, tokens and estimated cost.
    |
    */

    'logging' => [
        'enabled' => env('AGENT_LOG_USAGE', true),
        'channel' => env('AGENT_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | agent:chat
    |--------------------------------------------------------------------------
    |
    | Tools available in the interactive REPL (php artisan agent:chat).
    |
    */

    'chat' => [
        'instructions' => 'You are a helpful assistant inside a Laravel application. Use the tools when they help, and say so when you cannot answer.',
        'tools' => [
            // \SamirMhsnv\LaravelAgent\Sql\SafeSqlTool::class,
        ],
    ],

];
