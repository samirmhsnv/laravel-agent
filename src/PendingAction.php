<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent;

use SamirMhsnv\LaravelAgent\Messages\ToolCall;

/**
 * A tool call waiting for a human decision.
 *
 * The id is the provider's tool call id; pass it to AgentRun::approve() or reject().
 */
final class PendingAction
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly ToolCall $call,
        public readonly ?string $reason = null,
        private string $status = self::PENDING,
        private ?string $rejectionReason = null,
    ) {}

    public function id(): string
    {
        return $this->call->id;
    }

    public function tool(): string
    {
        return $this->call->name;
    }

    /** @return array<string, mixed> */
    public function arguments(): array
    {
        return $this->call->arguments;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function rejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    /** @internal */
    public function resolve(bool $approved, ?string $rejectionReason = null): void
    {
        $this->status = $approved ? self::APPROVED : self::REJECTED;
        $this->rejectionReason = $rejectionReason;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'call' => $this->call->toArray(),
            'reason' => $this->reason,
            'status' => $this->status,
            'rejection_reason' => $this->rejectionReason,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ToolCall::fromArray($data['call']),
            $data['reason'] ?? null,
            $data['status'] ?? self::PENDING,
            $data['rejection_reason'] ?? null,
        );
    }
}
