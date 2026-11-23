<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Attributes;

use Attribute;

/**
 * Gives a tool class the name and description the model sees.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsTool
{
    public function __construct(
        public string $name,
        public string $description,
    ) {}
}
