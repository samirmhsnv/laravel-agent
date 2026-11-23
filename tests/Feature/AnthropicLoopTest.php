<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use SamirMhsnv\LaravelAgent\Events\AgentStepCompleted;
use SamirMhsnv\LaravelAgent\Events\ToolCalled;
use SamirMhsnv\LaravelAgent\Exceptions\ProviderException;
use SamirMhsnv\LaravelAgent\Facades\Agent;
use SamirMhsnv\LaravelAgent\RunStatus;
use SamirMhsnv\LaravelAgent\Testing\AnthropicResponse;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\GetWeather;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\TagPicker;

beforeEach(fn () => GetWeather::$calls = []);

it('runs a tool and feeds the result back to the model', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUse('get_weather', ['city' => 'Baku'], id: 'toolu_1', text: 'Let me check.'))
        ->push(AnthropicResponse::text('It is 21°C in Baku.', inputTokens: 300, outputTokens: 15)),
    ]);

    $run = Agent::make()
        ->instructions('You are a weather assistant.')
        ->tools([GetWeather::class])
        ->run('What is the weather in Baku?');

    expect($run->status())->toBe(RunStatus::Completed)
        ->and($run->text())->toBe('It is 21°C in Baku.')
        ->and($run->steps())->toHaveCount(2)
        ->and($run->toolCalls())->toHaveCount(1)
        ->and($run->toolCalls()[0]->arguments)->toBe(['city' => 'Baku'])
        ->and($run->toolResults()[0]->content)->toBe('{"city":"Baku","temperature":21,"unit":"celsius"}')
        ->and($run->usage()->inputTokens)->toBe(400)
        ->and($run->usage()->outputTokens)->toBe(45)
        // claude-sonnet-5-5: $2 in / $10 out per million tokens
        ->and($run->cost())->toBe(round((400 * 2 + 45 * 10) / 1_000_000, 6));

    Http::assertSentCount(2);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-anthropic-key')
            && $request->hasHeader('anthropic-version', '2026-10-21')
            && $request['model'] === 'claude-sonnet-5-5'
            && $request['system'] === 'You are a weather assistant.'
            && $request['tools'][0]['name'] === 'get_weather'
            && $request['tools'][0]['input_schema']['required'] === ['city']
            && ! isset($request['temperature']);
    });

    $second = Http::recorded()[1][0];
    $messages = $second['messages'];

    expect($messages)->toHaveCount(3)
        ->and($messages[1]['role'])->toBe('assistant')
        ->and($messages[1]['content'][1])->toMatchArray(['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather'])
        ->and($messages[2])->toBe([
            'role' => 'user',
            'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => 'toolu_1',
                'content' => '{"city":"Baku","temperature":21,"unit":"celsius"}',
            ]],
        ]);
});

it('runs parallel tool calls and returns all results in one message', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUses([
            ['get_weather', ['city' => 'Baku'], 'toolu_a'],
            ['get_weather', ['city' => 'Paris', 'unit' => 'fahrenheit'], 'toolu_b'],
        ]))
        ->push(AnthropicResponse::text('Baku is warmer.')),
    ]);

    $run = Agent::make()->tools([GetWeather::class])->run('Compare Baku and Paris');

    $toolResults = Http::recorded()[1][0]['messages'][2]['content'];

    expect($run->text())->toBe('Baku is warmer.')
        ->and(GetWeather::$calls)->toHaveCount(2)
        ->and(array_column($toolResults, 'tool_use_id'))->toBe(['toolu_a', 'toolu_b']);
});

it('sends tool errors back to the model instead of throwing', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUses([
            ['tag_items', ['ids' => [], 'priority' => 'low'], 'toolu_1'],
            ['get_weather', [], 'toolu_2'],
            ['no_such_tool', [], 'toolu_3'],
        ]))
        ->push(AnthropicResponse::text('Sorry, something went wrong.')),
    ]);

    $run = Agent::make()->tools([TagPicker::class, GetWeather::class])->run('Go');

    $results = Http::recorded()[1][0]['messages'][2]['content'];

    expect($results[0])->toMatchArray(['is_error' => true, 'content' => 'Give at least one id.'])
        ->and($results[1])->toMatchArray(['is_error' => true, 'content' => 'Missing required argument [city].'])
        ->and($results[2])->toMatchArray(['is_error' => true, 'content' => 'Unknown tool [no_such_tool].'])
        ->and($run->completed())->toBeTrue();
});

it('replays assistant content blocks verbatim, including thinking blocks', function () {
    $first = AnthropicResponse::message([
        ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-abc'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'now', 'input' => new stdClass],
    ], 'tool_use', 10, 10);

    Http::fake(['api.anthropic.com/*' => Http::sequence()->push($first)->push(AnthropicResponse::text('Noon.'))]);

    $tool = new #[SamirMhsnv\LaravelAgent\Attributes\AsTool(name: 'now', description: 'Current time')] class extends SamirMhsnv\LaravelAgent\Tools\Tool
    {
        public function handle(): string
        {
            return '12:00';
        }
    };

    Agent::make()->tool($tool)->run('What time is it?');

    $body = json_decode(Http::recorded()[1][0]->body(), false);

    expect($body->messages[1]->content[0]->type)->toBe('thinking')
        ->and($body->messages[1]->content[0]->signature)->toBe('sig-abc')
        // an empty tool input must stay a JSON object, not become []
        ->and($body->messages[1]->content[1]->input)->toEqual(new stdClass)
        ->and($body->tools[0]->input_schema->properties)->toEqual(new stdClass);
});

it('dispatches step and tool events', function () {
    Event::fake([AgentStepCompleted::class, ToolCalled::class]);

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUse('get_weather', ['city' => 'Baku']))
        ->push(AnthropicResponse::text('Warm.')),
    ]);

    Agent::make()->tools([GetWeather::class])->run('Weather?');

    Event::assertDispatchedTimes(AgentStepCompleted::class, 2);
    Event::assertDispatched(ToolCalled::class, fn (ToolCalled $e) => $e->call->name === 'get_weather' && ! $e->result->isError);
});

it('uses the model and effort from the builder and config', function () {
    config()->set('agent.providers.anthropic.effort', 'low');
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::text('Hi', model: 'claude-opus-5-5'))]);

    $run = Agent::make()->model('claude-opus-5-5')->maxTokens(512)->run('Hello');

    Http::assertSent(fn (Request $r) => $r['model'] === 'claude-opus-5-5'
        && $r['max_tokens'] === 512
        && $r['output_config'] === ['effort' => 'low']);

    expect($run->cost())->toBe(round((100 * 4 + 20 * 20) / 1_000_000, 6));
});

it('returns a null cost for models without a price', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::text('Hi', model: 'some-new-model'))]);

    expect(Agent::make()->run('Hello')->cost())->toBeNull();
});

it('stops with status refused when the model declines', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::refusal())]);

    expect(Agent::make()->run('...')->status())->toBe(RunStatus::Refused);
});

it('throws a provider exception with the API error message', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'type' => 'error',
        'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens: too large'],
    ], 400)]);

    Agent::make()->run('Hello');
})->throws(ProviderException::class, 'Anthropic request failed with HTTP 400: max_tokens: too large');

it('complains clearly when no API key is configured', function () {
    config()->set('agent.providers.anthropic.api_key', null);
    Http::fake();

    Agent::make()->run('Hello');
})->throws(ProviderException::class, 'No API key configured for the [anthropic] provider');

it('logs usage and cost when a run finishes', function () {
    config()->set('agent.logging.enabled', true);
    Illuminate\Support\Facades\Log::shouldReceive('channel')->andReturnSelf();
    Illuminate\Support\Facades\Log::shouldReceive('info')->once()->withArgs(
        fn (string $message, array $context) => $message === 'Agent run finished'
            && $context['status'] === 'completed'
            && $context['usage']['input_tokens'] === 100
            && $context['cost_usd'] === 0.0004
    );

    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::text('Hi'))]);

    Agent::make()->run('Hello');
});
