<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SamirMhsnv\LaravelAgent\Exceptions\StructuredOutputException;
use SamirMhsnv\LaravelAgent\Facades\Agent;
use SamirMhsnv\LaravelAgent\Testing\AnthropicResponse;
use SamirMhsnv\LaravelAgent\Testing\OpenAiResponse;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\Invoice;

$invoiceJson = '{"customer":"Acme Ltd","total":99.5,"items":[{"description":"Widget","quantity":2}]}';

it('hydrates the final answer into a DTO', function () use ($invoiceJson) {
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::text($invoiceJson))]);

    $run = Agent::make()->expect(Invoice::class)->run('Extract the invoice: ...');

    expect($run->output())->toBeInstanceOf(Invoice::class)
        ->and($run->output()->customer)->toBe('Acme Ltd')
        ->and($run->output()->items[0]->quantity)->toBe(2);

    Http::assertSent(fn (Request $r) => $r['output_config']['format']['type'] === 'json_schema'
        && $r['output_config']['format']['schema']['required'] === ['customer', 'total', 'items']
        && str_contains($r['system'], 'reply with only a JSON value'));
});

it('accepts a plain JSON schema array and tolerates code fences', function () {
    Http::fake(['api.openai.com/*' => Http::response(OpenAiResponse::text("Here you go:\n```json\n{\"sentiment\": \"positive\", \"score\": 0.9}\n```"))]);

    $run = Agent::make()->using('openai')->expect([
        'type' => 'object',
        'properties' => [
            'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
            'score' => ['type' => 'number'],
        ],
        'required' => ['sentiment', 'score'],
        'additionalProperties' => false,
    ])->run('Classify: "I love it"');

    expect($run->output())->toBe(['sentiment' => 'positive', 'score' => 0.9]);

    Http::assertSent(fn (Request $r) => $r['response_format']['type'] === 'json_schema');
});

it('asks the model to repair invalid output once', function () use ($invoiceJson) {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::text('{"customer":"Acme Ltd","total":"lots"}'))
        ->push(AnthropicResponse::text($invoiceJson)),
    ]);

    $run = Agent::make()->expect(Invoice::class)->run('Extract the invoice');

    $repair = Http::recorded()[1][0]['messages'][2];

    expect($run->output()->total)->toBe(99.5)
        ->and($repair['role'])->toBe('user')
        ->and($repair['content'])->toContain('$.total must be of type number')
        ->and($repair['content'])->toContain('$.items is required');
});

it('throws when the output is still invalid after repair', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::text('not json at all'))]);

    try {
        Agent::make()->expect(Invoice::class)->run('Extract');
        $this->fail('Expected an exception.');
    } catch (StructuredOutputException $e) {
        expect($e->errors[0])->toContain('not valid JSON')
            ->and($e->rawOutput)->toBe('not json at all');
    }

    Http::assertSentCount(2);
});

it('can skip native structured output and rely on validation only', function () use ($invoiceJson) {
    config()->set('agent.structured_output.native', false);
    Http::fake(['api.anthropic.com/*' => Http::response(AnthropicResponse::text($invoiceJson))]);

    Agent::make()->expect(Invoice::class)->run('Extract');

    Http::assertSent(fn (Request $r) => ! isset($r['output_config']));
});
