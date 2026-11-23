<?php

use SamirMhsnv\LaravelAgent\Attributes\AsTool;
use SamirMhsnv\LaravelAgent\Exceptions\InvalidToolDefinition;
use SamirMhsnv\LaravelAgent\Support\SchemaBuilder;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\GetWeather;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\Invoice;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\TagPicker;
use SamirMhsnv\LaravelAgent\Tools\Tool;

it('reads name and description from the AsTool attribute', function () {
    $tool = new GetWeather;

    expect($tool->name())->toBe('get_weather')
        ->and($tool->description())->toBe('Get the current weather for a city.');
});

it('builds a JSON schema from typed handle() parameters', function () {
    expect((new GetWeather)->parameters())->toBe([
        'type' => 'object',
        'properties' => [
            'city' => ['description' => 'City name, e.g. "Baku"', 'type' => 'string'],
            'unit' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']],
            'days' => ['type' => ['integer', 'null']],
        ],
        'required' => ['city'],
        'additionalProperties' => false,
    ]);
});

it('maps arrays, enums, floats and booleans', function () {
    $schema = (new TagPicker)->parameters();

    expect($schema['properties'])->toBe([
        'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        'priority' => ['type' => 'string', 'enum' => ['low', 'high']],
        'weight' => ['type' => 'number'],
        'dryRun' => ['type' => 'boolean'],
        'units' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']]],
    ])->and($schema['required'])->toBe(['ids', 'priority']);
});

it('builds nested object schemas for DTOs', function () {
    $schema = SchemaBuilder::forClass(Invoice::class);

    expect($schema['required'])->toBe(['customer', 'total', 'items'])
        ->and($schema['properties']['customer']['description'])->toBe('Customer name as written on the invoice')
        ->and($schema['properties']['currency'])->toBe(['type' => ['string', 'null']])
        ->and($schema['properties']['items'])->toBe([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'description' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer'],
                ],
                'required' => ['description', 'quantity'],
                'additionalProperties' => false,
            ],
        ]);
});

it('produces an empty object schema for a tool without parameters', function () {
    $tool = new #[AsTool(name: 'now', description: 'Current time.')] class extends Tool
    {
        public function handle(): string
        {
            return '12:00';
        }
    };

    expect($tool->parameters())->toBe(['type' => 'object', 'properties' => [], 'additionalProperties' => false])
        ->and($tool->execute([]))->toBe('12:00');
});

it('rejects parameter types it cannot describe', function (Tool $tool) {
    $tool->parameters();
})->with([
    'union type' => fn () => new #[AsTool(name: 'u', description: 'u')] class extends Tool
    {
        public function handle(int|string $id): void {}
    },
    'mixed' => fn () => new #[AsTool(name: 'm', description: 'm')] class extends Tool
    {
        public function handle(mixed $value): void {}
    },
    'variadic' => fn () => new #[AsTool(name: 'v', description: 'v')] class extends Tool
    {
        public function handle(string ...$names): void {}
    },
])->throws(InvalidToolDefinition::class);

it('explains a missing AsTool attribute', function () {
    (new class extends Tool
    {
        public function handle(): void {}
    })->name();
})->throws(InvalidToolDefinition::class, 'needs an #[AsTool');
