<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Guards;

final readonly class GuardDecision
{
    private function __construct(
        public string $verdict,
        public ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self('allow');
    }

    /** Block the call. The reason is sent to the model so it can explain or try something else. */
    public static function deny(string $reason): self
    {
        return new self('deny', $reason);
    }

    /** Pause the run until a human approves or rejects this call. */
    public static function requireApproval(?string $reason = null): self
    {
        return new self('approval', $reason);
    }

    public function allowed(): bool
    {
        return $this->verdict === 'allow';
    }

    public function denied(): bool
    {
        return $this->verdict === 'deny';
    }

    public function needsApproval(): bool
    {
        return $this->verdict === 'approval';
    }
}
