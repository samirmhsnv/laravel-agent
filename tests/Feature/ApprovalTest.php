<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use SamirMhsnv\LaravelAgent\AgentRun;
use SamirMhsnv\LaravelAgent\Contracts\ActionGuard;
use SamirMhsnv\LaravelAgent\Contracts\Tool;
use SamirMhsnv\LaravelAgent\Events\ApprovalRequested;
use SamirMhsnv\LaravelAgent\Exceptions\ApprovalException;
use SamirMhsnv\LaravelAgent\Facades\Agent;
use SamirMhsnv\LaravelAgent\Guards\GuardDecision;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;
use SamirMhsnv\LaravelAgent\RunStatus;
use SamirMhsnv\LaravelAgent\Testing\AnthropicResponse;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\GetWeather;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\SendEmail;

beforeEach(function () {
    SendEmail::$sent = [];
    GetWeather::$calls = [];
});

function emailCall(string $id = 'toolu_mail'): array
{
    return AnthropicResponse::toolUse('send_email', ['to' => 'ann@example.com', 'subject' => 'Refund', 'body' => 'Done.'], id: $id);
}

it('pauses instead of running a tool that requires approval', function () {
    Event::fake([ApprovalRequested::class]);
    Http::fake(['api.anthropic.com/*' => Http::response(emailCall())]);

    $run = Agent::make()->tools([SendEmail::class])->run('Tell Ann her refund is done');

    expect($run->status())->toBe(RunStatus::AwaitingApproval)
        ->and($run->awaitingApproval())->toBeTrue()
        ->and($run->pending())->toHaveCount(1)
        ->and($run->pending()[0]->id())->toBe('toolu_mail')
        ->and($run->pending()[0]->tool())->toBe('send_email')
        ->and($run->pending()[0]->arguments())->toMatchArray(['to' => 'ann@example.com'])
        ->and($run->pending()[0]->reason)->toBe('Emails reach real customers.')
        ->and(SendEmail::$sent)->toBe([]);

    Http::assertSentCount(1);
    Event::assertDispatched(ApprovalRequested::class, fn ($e) => $e->action->id() === 'toolu_mail');
});

it('runs the tool and resumes the loop after approval', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(emailCall())
        ->push(AnthropicResponse::text('I emailed Ann.')),
    ]);

    $run = Agent::make()->tools([SendEmail::class])->run('Tell Ann her refund is done');
    $run->approve('toolu_mail');

    expect($run->status())->toBe(RunStatus::Completed)
        ->and($run->text())->toBe('I emailed Ann.')
        ->and(SendEmail::$sent)->toHaveCount(1)
        ->and($run->pending())->toBe([]);

    $result = Http::recorded()[1][0]['messages'][2]['content'][0];
    expect($result)->toMatchArray(['tool_use_id' => 'toolu_mail', 'content' => 'Email sent to ann@example.com.']);
});

it('tells the model when an action was rejected', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(emailCall())
        ->push(AnthropicResponse::text('Okay, I did not send it.')),
    ]);

    $run = Agent::make()->tools([SendEmail::class])->run('Email Ann');
    $run->reject('toolu_mail', 'Wrong customer');

    $result = Http::recorded()[1][0]['messages'][2]['content'][0];

    expect($run->completed())->toBeTrue()
        ->and(SendEmail::$sent)->toBe([])
        ->and($result)->toMatchArray([
            'is_error' => true,
            'content' => 'The user rejected this action. Reason: Wrong customer',
        ]);
});

it('runs safe tools immediately and holds only the risky one', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUses([
            ['send_email', ['to' => 'a@example.com', 'subject' => 's', 'body' => 'b'], 'toolu_mail'],
            ['get_weather', ['city' => 'Baku'], 'toolu_weather'],
        ]))
        ->push(AnthropicResponse::text('Done.')),
    ]);

    $run = Agent::make()->tools([SendEmail::class, GetWeather::class])->run('Go');

    expect(GetWeather::$calls)->toHaveCount(1)
        ->and(SendEmail::$sent)->toBe([])
        ->and($run->pending())->toHaveCount(1);

    $run->approve('toolu_mail');

    // Both results go back in one message, in the original call order.
    $results = Http::recorded()[1][0]['messages'][2]['content'];
    expect(array_column($results, 'tool_use_id'))->toBe(['toolu_mail', 'toolu_weather'])
        ->and($run->completed())->toBeTrue();
});

it('waits until every pending action has a decision', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUses([
            ['send_email', ['to' => 'a@example.com', 'subject' => 's', 'body' => 'b'], 'toolu_1'],
            ['send_email', ['to' => 'b@example.com', 'subject' => 's', 'body' => 'b'], 'toolu_2'],
        ]))
        ->push(AnthropicResponse::text('Sent one of two.')),
    ]);

    $run = Agent::make()->tools([SendEmail::class])->run('Email both');
    $run->approve('toolu_1');

    expect($run->awaitingApproval())->toBeTrue()
        ->and($run->pending())->toHaveCount(1);
    Http::assertSentCount(1);

    $run->reject('toolu_2');

    expect($run->completed())->toBeTrue()->and(SendEmail::$sent)->toHaveCount(1);
});

it('can be stored between requests and resumed later', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(emailCall())
        ->push(AnthropicResponse::text('Sent.')),
    ]);

    $run = Agent::make()->tools([SendEmail::class])->run('Email Ann');

    // e.g. Cache::put("agent-run:{$id}", $run->toArray()) ... and later, in another request:
    $stored = json_decode(json_encode($run->toArray()), true);

    $resumed = Agent::make()->tools([SendEmail::class])->resume($stored);

    expect($resumed->pending()[0]->id())->toBe('toolu_mail');

    $resumed->approve('toolu_mail');

    expect($resumed->text())->toBe('Sent.')
        ->and($resumed->steps())->toHaveCount(2)
        ->and($resumed->usage()->inputTokens)->toBe(200)
        ->and(SendEmail::$sent)->toHaveCount(1);
});

it('refuses unknown or already decided action ids', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::toolUses([
        ['send_email', ['to' => 'a@example.com', 'subject' => 's', 'body' => 'b'], 'toolu_1'],
        ['send_email', ['to' => 'b@example.com', 'subject' => 's', 'body' => 'b'], 'toolu_2'],
    ]))]);

    $run = Agent::make()->tools([SendEmail::class])->run('Email both');

    expect(fn () => $run->approve('nope'))->toThrow(ApprovalException::class, 'no pending action');

    $run->approve('toolu_1');

    expect(fn () => $run->approve('toolu_1'))->toThrow(ApprovalException::class, 'already approved')
        ->and(fn () => $run->continue('Something else'))->toThrow(ApprovalException::class, 'before continuing');
});

it('lets a guard veto a tool call by policy', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUse('get_weather', ['city' => 'Pyongyang'], id: 'toolu_1'))
        ->push(AnthropicResponse::text('I cannot look that up.')),
    ]);

    $guard = new class implements ActionGuard
    {
        public function check(ToolCall $call, Tool $tool, AgentRun $run): GuardDecision
        {
            return $call->arguments['city'] === 'Pyongyang'
                ? GuardDecision::deny('Lookups for this city are not allowed.')
                : GuardDecision::allow();
        }
    };

    $run = Agent::make()->tools([GetWeather::class])->guard($guard)->run('Weather?');

    expect(GetWeather::$calls)->toBe([])
        ->and($run->toolResults()[0]->content)->toBe('Blocked: Lookups for this city are not allowed.')
        ->and($run->toolResults()[0]->isError)->toBeTrue();
});

it('lets a closure guard require approval for specific arguments', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::toolUse('get_weather', ['city' => 'Baku', 'days' => 30]))]);

    $run = Agent::make()
        ->tools([GetWeather::class])
        ->guard(fn (ToolCall $call) => ($call->arguments['days'] ?? 0) > 7 ? GuardDecision::requireApproval('Long forecasts are expensive.') : null)
        ->run('30 day forecast');

    expect($run->awaitingApproval())->toBeTrue()
        ->and($run->pending()[0]->reason)->toBe('Long forecasts are expensive.');
});

it('applies the deny and require_approval policy from config', function () {
    config()->set('agent.policy.deny', ['get_*']);

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUse('get_weather', ['city' => 'Baku']))
        ->push(AnthropicResponse::text('Blocked.')),
    ]);

    $run = Agent::make()->tools([GetWeather::class])->run('Weather?');

    expect(GetWeather::$calls)->toBe([])
        ->and($run->toolResults()[0]->content)->toContain('disabled by policy');
});

it('can require approval for any tool through config', function () {
    config()->set('agent.policy.require_approval', ['get_weather']);
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::toolUse('get_weather', ['city' => 'Baku']))]);

    expect(Agent::make()->tools([GetWeather::class])->run('Weather?')->awaitingApproval())->toBeTrue();
});
