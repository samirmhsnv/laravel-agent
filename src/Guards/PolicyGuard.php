<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Guards;

use Illuminate\Support\Str;
use SamirMhsnv\LaravelAgent\AgentRun;
use SamirMhsnv\LaravelAgent\Contracts\ActionGuard;
use SamirMhsnv\LaravelAgent\Contracts\Tool;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;

/**
 * Name-based policy from config/agent.php:
 *
 *     'policy' => [
 *         'deny' => ['delete_user'],
 *         'require_approval' => ['send_email', 'refund_*'],
 *     ],
 *
 * Patterns use Str::is() wildcards.
 */
final class PolicyGuard implements ActionGuard
{
    /**
     * @param  list<string>  $deny
     * @param  list<string>  $requireApproval
     */
    public function __construct(
        private readonly array $deny = [],
        private readonly array $requireApproval = [],
    ) {}

    public function check(ToolCall $call, Tool $tool, AgentRun $run): GuardDecision
    {
        if ($this->deny !== [] && Str::is($this->deny, $call->name)) {
            return GuardDecision::deny("The [{$call->name}] tool is disabled by policy.");
        }

        if ($this->requireApproval !== [] && Str::is($this->requireApproval, $call->name)) {
            return GuardDecision::requireApproval('Policy requires approval for this tool.');
        }

        return GuardDecision::allow();
    }
}
