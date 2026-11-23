<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Attributes;

use Attribute;

/**
 * Extra schema information for a tool parameter or DTO property.
 *
 * @param  string|null  $description  Shown to the model next to the parameter.
 * @param  string|null  $items  For array parameters: a JSON type ("string", "integer", ...),
 *                              a backed enum class or a DTO class.
 * @param  list<string|int>|null  $enum  Restrict the value to a fixed set.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Param
{
    public function __construct(
        public ?string $description = null,
        public ?string $items = null,
        public ?array $enum = null,
    ) {}
}
