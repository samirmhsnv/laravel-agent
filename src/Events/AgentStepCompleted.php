<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Events;

use SamirMhsnv\LaravelAgent\AgentRun;
use SamirMhsnv\LaravelAgent\Step;

/** Fired after every model call, once its tool calls have run (or been held for approval). */
final readonly class AgentStepCompleted
{
    public function __construct(
        public AgentRun $run,
        public Step $step,
    ) {}
}
