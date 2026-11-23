<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Llm;

enum StopReason: string
{
    case EndTurn = 'end_turn';
    case ToolUse = 'tool_use';
    case MaxTokens = 'max_tokens';
    case Refusal = 'refusal';
    case Other = 'other';
}
