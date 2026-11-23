<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Llm;

/**
 * Token counts for one or more model calls.
 *
 * inputTokens never includes cached tokens; those are counted separately so
 * cost can be estimated correctly.
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
        );
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }

    /** @return array{input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int} */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ];
    }

    /** @param  array<string, int>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['input_tokens'] ?? 0,
            $data['output_tokens'] ?? 0,
            $data['cache_read_tokens'] ?? 0,
            $data['cache_write_tokens'] ?? 0,
        );
    }
}
