<?php

namespace SamirMhsnv\LaravelAgent\Tests\Fixtures;

use SamirMhsnv\LaravelAgent\Attributes\Param;

final readonly class Invoice
{
    /** @param  list<LineItem>  $items */
    public function __construct(
        #[Param(description: 'Customer name as written on the invoice')]
        public string $customer,
        public float $total,
        #[Param(items: LineItem::class)]
        public array $items,
        public ?string $currency = null,
    ) {}
}
