<?php

namespace SamirMhsnv\LaravelAgent\Tests\Fixtures;

use SamirMhsnv\LaravelAgent\Attributes\AsTool;
use SamirMhsnv\LaravelAgent\Attributes\Param;
use SamirMhsnv\LaravelAgent\Exceptions\ToolException;
use SamirMhsnv\LaravelAgent\Tools\Tool;

#[AsTool(name: 'tag_items', description: 'Tag items.')]
final class TagPicker extends Tool
{
    public function handle(
        #[Param(items: 'integer')] array $ids,
        #[Param(enum: ['low', 'high'])] string $priority,
        float $weight = 1.0,
        bool $dryRun = false,
        #[Param(items: Unit::class)] array $units = [],
    ): array {
        if ($ids === []) {
            throw new ToolException('Give at least one id.');
        }

        return compact('ids', 'priority', 'weight', 'dryRun', 'units');
    }
}
