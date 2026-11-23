<?php

namespace SamirMhsnv\LaravelAgent\Tests\Fixtures;

use SamirMhsnv\LaravelAgent\Attributes\AsTool;
use SamirMhsnv\LaravelAgent\Attributes\Param;
use SamirMhsnv\LaravelAgent\Tools\Tool;

#[AsTool(name: 'get_weather', description: 'Get the current weather for a city.')]
final class GetWeather extends Tool
{
    /** @var list<array<string, mixed>> */
    public static array $calls = [];

    public function handle(
        #[Param(description: 'City name, e.g. "Baku"')] string $city,
        Unit $unit = Unit::Celsius,
        ?int $days = null,
    ): array {
        self::$calls[] = compact('city', 'unit', 'days');

        return ['city' => $city, 'temperature' => $unit === Unit::Celsius ? 21 : 70, 'unit' => $unit->value];
    }
}
