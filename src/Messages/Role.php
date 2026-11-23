<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Messages;

enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case ToolResults = 'tool_results';
}
