<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Events;

use SamirMhsnv\LaravelAgent\AgentRun;
use SamirMhsnv\LaravelAgent\PendingAction;

/** Fired when a tool call is held for a human decision. Notify someone here. */
final readonly class ApprovalRequested
{
    public function __construct(
        public AgentRun $run,
        public PendingAction $action,
    ) {}
}
