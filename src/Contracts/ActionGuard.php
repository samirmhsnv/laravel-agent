<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Contracts;

use SamirMhsnv\LaravelAgent\AgentRun;
use SamirMhsnv\LaravelAgent\Guards\GuardDecision;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;

/**
 * Inspects every tool call before it runs and decides: allow, deny, or ask a human.
 */
interface ActionGuard
{
    public function check(ToolCall $call, Tool $tool, AgentRun $run): GuardDecision;
}
