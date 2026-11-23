<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Events;

use SamirMhsnv\LaravelAgent\AgentRun;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;
use SamirMhsnv\LaravelAgent\Messages\ToolResult;

/**
 * Fired for every tool call the model makes, including ones that were
 * blocked, rejected or failed ($result->isError tells you).
 */
final readonly class ToolCalled
{
    public function __construct(
        public AgentRun $run,
        public ToolCall $call,
        public ToolResult $result,
        public float $durationMs,
    ) {}
}
