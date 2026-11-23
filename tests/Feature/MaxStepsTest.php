<?php

use Illuminate\Support\Facades\Http;
use SamirMhsnv\LaravelAgent\Facades\Agent;
use SamirMhsnv\LaravelAgent\RunStatus;
use SamirMhsnv\LaravelAgent\Testing\AnthropicResponse;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\GetWeather;

it('stops after maxSteps model calls', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::toolUse('get_weather', ['city' => 'Baku'], text: 'Checking again...'))]);

    $run = Agent::make()->tools([GetWeather::class])->maxSteps(3)->run('Loop forever');

    expect($run->status())->toBe(RunStatus::MaxStepsReached)
        ->and($run->steps())->toHaveCount(3)
        ->and($run->text())->toBe('Checking again...');

    Http::assertSentCount(3);
});

it('defaults to the configured max_steps', function () {
    config()->set('agent.max_steps', 2);
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::toolUse('get_weather', ['city' => 'Baku']))]);

    Agent::make()->tools([GetWeather::class])->run('Loop');

    Http::assertSentCount(2);
});

it('counts steps per prompt when a conversation continues', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::text('Hello!'))
        ->push(AnthropicResponse::text('Baku is the capital of Azerbaijan.')),
    ]);

    $run = Agent::make()->maxSteps(1)->run('Hi');
    $run->continue('What is the capital of Azerbaijan?');

    expect($run->completed())->toBeTrue()
        ->and($run->text())->toBe('Baku is the capital of Azerbaijan.')
        ->and($run->steps())->toHaveCount(2);

    $messages = Http::recorded()[1][0]['messages'];
    expect(array_column($messages, 'role'))->toBe(['user', 'assistant', 'user']);
});

it('rejects a maxSteps below one', function () {
    Agent::make()->maxSteps(0);
})->throws(InvalidArgumentException::class);
