<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Guards;

use Closure;
use SamirMhsnv\LaravelAgent\AgentRun;
use SamirMhsnv\LaravelAgent\Contracts\ActionGuard;
use SamirMhsnv\LaravelAgent\Contracts\Tool;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;

/**
 * Wraps a closure as a guard. The closure may return a GuardDecision,
 * true/null (allow) or false (deny).
 */
final class ClosureGuard implements ActionGuard
{
    public function __construct(private readonly Closure $callback) {}

    public function check(ToolCall $call, Tool $tool, AgentRun $run): GuardDecision
    {
        $result = ($this->callback)($call, $tool, $run);

        return match (true) {
            $result instanceof GuardDecision => $result,
            $result === false => GuardDecision::deny("The [{$call->name}] call was blocked by a guard."),
            default => GuardDecision::allow(),
        };
    }
}
