<?php

namespace SamirMhsnv\LaravelAgent\Tests\Fixtures;

final readonly class LineItem
{
    public function __construct(
        public string $description,
        public int $quantity,
    ) {}
}
