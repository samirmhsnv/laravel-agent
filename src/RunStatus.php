<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent;

enum RunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case AwaitingApproval = 'awaiting_approval';
    case MaxStepsReached = 'max_steps_reached';
    case Refused = 'refused';
}
