<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Exceptions;

/** SafeSqlTool refused to run a query. The message says why. */
class UnsafeQueryException extends ToolException
{
    public static function because(string $reason): self
    {
        return new self("Query rejected: {$reason}");
    }
}
