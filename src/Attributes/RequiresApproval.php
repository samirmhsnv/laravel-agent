<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Attributes;

use Attribute;

/**
 * Marks a tool as one that must never run without a human saying yes.
 *
 * When the model calls it, the run pauses with a pending action that your
 * application approves or rejects.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RequiresApproval
{
    public function __construct(
        public ?string $reason = null,
    ) {}
}
