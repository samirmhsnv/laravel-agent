<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Llm;

/**
 * Estimates the cost of a call from the price table in config/agent.php.
 *
 * Prices are USD per million tokens. Unknown models return null rather than
 * a made-up number. Cache prices default to 10% (read) and 125% (write) of
 * the input price when not listed.
 */
final class CostCalculator
{
    /** @param  array<string, array{input: float, output: float, cache_read?: float, cache_write?: float}>  $prices */
    public function __construct(private readonly array $prices = []) {}

    public function estimate(string $model, Usage $usage): ?float
    {
        $price = $this->priceFor($model);

        if ($price === null) {
            return null;
        }

        $input = (float) $price['input'];
        $cost = $usage->inputTokens * $input
            + $usage->outputTokens * (float) $price['output']
            + $usage->cacheReadTokens * (float) ($price['cache_read'] ?? $input * 0.1)
            + $usage->cacheWriteTokens * (float) ($price['cache_write'] ?? $input * 1.25);

        return round($cost / 1_000_000, 6);
    }

    /** @return array<string, float>|null */
    private function priceFor(string $model): ?array
    {
        if (isset($this->prices[$model])) {
            return $this->prices[$model];
        }

        // Providers sometimes return a dated or suffixed id (e.g. "gpt-x-2026-01-01").
        foreach ($this->prices as $name => $price) {
            if (str_starts_with($model, $name.'-')) {
                return $price;
            }
        }

        return null;
    }
}
