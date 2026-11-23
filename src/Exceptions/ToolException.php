<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Exceptions;

/**
 * A tool failed in a way the model should hear about.
 *
 * The agent loop catches these and returns the message to the model as an
 * error result, so it can correct itself. Any other exception bubbles up.
 */
class ToolException extends AgentException {}
