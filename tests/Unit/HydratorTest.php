<?php

use SamirMhsnv\LaravelAgent\Exceptions\InvalidToolArguments;
use SamirMhsnv\LaravelAgent\Support\Hydrator;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\GetWeather;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\Invoice;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\LineItem;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\TagPicker;
use SamirMhsnv\LaravelAgent\Tests\Fixtures\Unit;

beforeEach(fn () => GetWeather::$calls = []);

it('casts model arguments to the declared types', function () {
    $result = (new TagPicker)->execute([
        'ids' => ['1', 2],
        'priority' => 'high',
        'weight' => '2.5',
        'dryRun' => 'true',
        'units' => ['fahrenheit'],
    ]);

    expect($result)->toBe([
        'ids' => [1, 2],
        'priority' => 'high',
        'weight' => 2.5,
        'dryRun' => true,
        'units' => [Unit::Fahrenheit],
    ]);
});

it('fills defaults and nulls for missing optional arguments', function () {
    (new GetWeather)->execute(['city' => 'Baku']);

    expect(GetWeather::$calls[0])->toBe(['city' => 'Baku', 'unit' => Unit::Celsius, 'days' => null]);
});

it('reports bad arguments clearly', function (array $arguments, string $message) {
    expect(fn () => (new GetWeather)->execute($arguments))
        ->toThrow(InvalidToolArguments::class, $message);
})->with([
    'missing' => [[], 'Missing required argument [city]'],
    'bad enum' => [['city' => 'Baku', 'unit' => 'kelvin'], 'must be one of: "celsius", "fahrenheit"'],
    'bad int' => [['city' => 'Baku', 'days' => 'three'], 'must be an integer'],
    'array for string' => [['city' => ['Baku']], 'must be a string'],
]);

it('hydrates nested DTOs', function () {
    $invoice = Hydrator::hydrate(Invoice::class, [
        'customer' => 'Acme',
        'total' => 12,
        'items' => [['description' => 'Widget', 'quantity' => 3]],
    ]);

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice->total)->toBe(12.0)
        ->and($invoice->currency)->toBeNull()
        ->and($invoice->items[0])->toEqual(new LineItem('Widget', 3));
});
