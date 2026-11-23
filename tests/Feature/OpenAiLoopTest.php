<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SamirMhsnv\LaravelAgent\Facades\Agent;
use SamirMhsnv\LaravelAgent\Testing\OpenAiResponse;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\GetWeather;

beforeEach(fn () => GetWeather::$calls = []);

it('runs the same loop against OpenAI chat completions', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(OpenAiResponse::toolCall('get_weather', ['city' => 'Baku', 'unit' => 'fahrenheit'], 'call_1'))
        ->push(OpenAiResponse::text('It is 70°F in Baku.')),
    ]);

    $run = Agent::make()
        ->using('openai')
        ->instructions('Be brief.')
        ->tools([GetWeather::class])
        ->run('Weather in Baku, in Fahrenheit?');

    expect($run->text())->toBe('It is 70°F in Baku.')
        ->and($run->steps())->toHaveCount(2)
        ->and($run->usage()->inputTokens)->toBe(200)
        ->and(GetWeather::$calls[0]['unit']->value)->toBe('fahrenheit');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/chat/completions'
        && $r->hasHeader('Authorization', 'Bearer test-openai-key')
        && $r['model'] === 'gpt-5'
        && $r['messages'][0] === ['role' => 'system', 'content' => 'Be brief.']
        && $r['tools'][0]['type'] === 'function'
        && $r['tools'][0]['function']['name'] === 'get_weather');

    $messages = Http::recorded()[1][0]['messages'];

    expect($messages[2])->toMatchArray([
        'role' => 'assistant',
        'content' => null,
        'tool_calls' => [[
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Baku","unit":"fahrenheit"}'],
        ]],
    ])->and($messages[3])->toBe([
        'role' => 'tool',
        'tool_call_id' => 'call_1',
        'content' => '{"city":"Baku","temperature":70,"unit":"fahrenheit"}',
    ]);
});

it('counts cached prompt tokens separately', function () {
    $response = OpenAiResponse::text('Hi', promptTokens: 1000);
    $response['usage']['prompt_tokens_details'] = ['cached_tokens' => 800];

    Http::fake(['api.openai.com/*' => Http::response($response)]);

    $usage = Agent::make()->using('openai')->run('Hello')->usage();

    expect($usage->inputTokens)->toBe(200)->and($usage->cacheReadTokens)->toBe(800);
});

it('supports OpenAI-compatible servers as extra providers', function () {
    config()->set('agent.providers.local', [
        'driver' => 'openai',
        'api_key' => 'none',
        'base_url' => 'http://localhost:11434/v1',
        'model' => 'llama-local',
    ]);

    Http::fake(['localhost:11434/*' => Http::response(OpenAiResponse::text('Hello from local'))]);

    $run = Agent::make()->using('local')->run('Hi');

    expect($run->text())->toBe('Hello from local');
    Http::assertSent(fn (Request $r) => $r['model'] === 'llama-local');
});

it('lets the model recover from malformed tool arguments', function () {
    $bad = OpenAiResponse::toolCall('get_weather', [], 'call_1');
    $bad['choices'][0]['message']['tool_calls'][0]['function']['arguments'] = '{"city": "Ba';

    Http::fake(['api.openai.com/*' => Http::sequence()->push($bad)->push(OpenAiResponse::text('Retrying failed.'))]);

    $run = Agent::make()->using('openai')->tools([GetWeather::class])->run('Weather?');

    expect($run->toolResults()[0]->isError)->toBeTrue()
        ->and(GetWeather::$calls)->toBe([]);
});
