<?php

use Illuminate\Support\Facades\Http;
use SamirMhsnv\LaravelAgent\Testing\AnthropicResponse;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\GetWeather;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\SendEmail;

it('chats in the terminal and shows tool activity', function () {
    config()->set('agent.chat.tools', [GetWeather::class]);

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUse('get_weather', ['city' => 'Baku']))
        ->push(AnthropicResponse::text('21°C in Baku.')),
    ]);

    $this->artisan('agent:chat')
        ->expectsOutputToContain('Tools: get_weather')
        ->expectsQuestion('You', 'Weather in Baku?')
        ->expectsOutputToContain('[tool] get_weather')
        ->expectsOutputToContain('21°C in Baku.')
        ->expectsQuestion('You', 'exit')
        ->assertSuccessful();
});

it('asks before running tools that need approval', function () {
    SendEmail::$sent = [];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUse('send_email', ['to' => 'a@example.com', 'subject' => 'Hi', 'body' => 'Hello']))
        ->push(AnthropicResponse::text('Sent.')),
    ]);

    $this->artisan('agent:chat', ['--tool' => [SendEmail::class]])
        ->expectsQuestion('You', 'Email a@example.com')
        ->expectsConfirmation('Allow send_email({"to":"a@example.com","subject":"Hi","body":"Hello"})? (Emails reach real customers.)', 'yes')
        ->expectsOutputToContain('Sent.')
        ->expectsQuestion('You', 'exit')
        ->assertSuccessful();

    expect(SendEmail::$sent)->toHaveCount(1);
});
