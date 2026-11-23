<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent;

use SamirMhsnv\LaravelAgent\Exceptions\ApprovalException;
use SamirMhsnv\LaravelAgent\Llm\Usage;
use SamirMhsnv\LaravelAgent\Messages\Message;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;
use SamirMhsnv\LaravelAgent\Messages\ToolResult;

/**
 * The result (and state) of an agent run.
 *
 * A run either finishes (completed, refused, max steps reached) or pauses
 * waiting for approval. A paused run can be resumed in the same request with
 * approve()/reject(), or stored with toArray() and resumed later with
 * Agent::resume().
 */
final class AgentRun
{
    /** @var list<Message> */
    private array $messages = [];

    /** @var list<Step> */
    private array $steps = [];

    /** @var list<PendingAction> */
    private array $pending = [];

    /** @var list<ToolResult> Results already produced in the step that is waiting for approval. */
    private array $heldResults = [];

    private RunStatus $status = RunStatus::Running;

    private Usage $usage;

    private ?float $cost = null;

    private string $text = '';

    private mixed $output = null;

    private int $turnStartStep = 0;

    private int $repairAttempts = 0;

    public function __construct(private ?Agent $agent = null)
    {
        $this->usage = new Usage;
    }

    // ---------------------------------------------------------------------
    // Reading the result
    // ---------------------------------------------------------------------

    /** The final answer text (or the latest text, if the run did not complete). */
    public function text(): string
    {
        return $this->text;
    }

    public function status(): RunStatus
    {
        return $this->status;
    }

    public function completed(): bool
    {
        return $this->status === RunStatus::Completed;
    }

    public function awaitingApproval(): bool
    {
        return $this->status === RunStatus::AwaitingApproval;
    }

    /** The validated structured output when expect() was used: a DTO instance or an array. */
    public function output(): mixed
    {
        return $this->output;
    }

    /** @return list<Step> */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Every tool call the model made during the run, in order.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return array_merge(...array_map(fn (Step $step) => $step->toolCalls, $this->steps));
    }

    /**
     * Every tool result (including errors, denials and rejections), in order.
     *
     * @return list<ToolResult>
     */
    public function toolResults(): array
    {
        return array_merge(...array_map(fn (Step $step) => $step->toolResults(), $this->steps));
    }

    public function usage(): Usage
    {
        return $this->usage;
    }

    /** Estimated cost in USD from the price table, or null when the model has no price configured. */
    public function cost(): ?float
    {
        return $this->cost;
    }

    /** @return list<Message> */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * Actions still waiting for a decision.
     *
     * @return list<PendingAction>
     */
    public function pending(): array
    {
        return array_values(array_filter($this->pending, fn (PendingAction $a) => $a->isPending()));
    }

    // ---------------------------------------------------------------------
    // Human in the loop
    // ---------------------------------------------------------------------

    /**
     * Approve a pending action. Once nothing is pending any more, the tool
     * calls run and the loop continues.
     */
    public function approve(string $id): self
    {
        return $this->decide($id, true);
    }

    /** Reject a pending action. The model is told it was rejected (and why, if you say). */
    public function reject(string $id, ?string $reason = null): self
    {
        return $this->decide($id, false, $reason);
    }

    public function approveAll(): self
    {
        foreach ($this->pending() as $action) {
            $action->resolve(true);
        }

        return $this->resumeIfReady();
    }

    public function rejectAll(?string $reason = null): self
    {
        foreach ($this->pending() as $action) {
            $action->resolve(false, $reason);
        }

        return $this->resumeIfReady();
    }

    /** Ask a follow-up question in the same conversation. */
    public function continue(string $prompt): self
    {
        if ($this->awaitingApproval()) {
            throw new ApprovalException('Approve or reject the pending actions before continuing the conversation.');
        }

        $this->addMessage(Message::user($prompt));
        $this->turnStartStep = count($this->steps);
        $this->repairAttempts = 0;
        $this->output = null;
        $this->status = RunStatus::Running;

        return $this->agent()->drive($this);
    }

    private function decide(string $id, bool $approved, ?string $reason = null): self
    {
        $action = collect($this->pending)->first(fn (PendingAction $a) => $a->id() === $id);

        if ($action === null) {
            throw new ApprovalException("There is no pending action with id [{$id}].");
        }

        if (! $action->isPending()) {
            throw new ApprovalException("Action [{$id}] was already {$action->status()}.");
        }

        $action->resolve($approved, $reason);

        return $this->resumeIfReady();
    }

    private function resumeIfReady(): self
    {
        return $this->pending() === [] && $this->status === RunStatus::AwaitingApproval
            ? $this->agent()->drive($this)
            : $this;
    }

    private function agent(): Agent
    {
        return $this->agent ?? throw new ApprovalException(
            'This run is not attached to an agent. Use Agent::make()->...->resume($state) to continue a stored run.'
        );
    }

    // ---------------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------------

    /** A JSON-safe snapshot. Store it (cache, database) and pass it to Agent::resume(). */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'text' => $this->text,
            'messages' => array_map(fn (Message $m) => $m->toArray(), $this->messages),
            'steps' => array_map(fn (Step $s) => $s->toArray(), $this->steps),
            'pending' => array_map(fn (PendingAction $a) => $a->toArray(), $this->pending),
            'held_results' => array_map(fn (ToolResult $r) => $r->toArray(), $this->heldResults),
            'usage' => $this->usage->toArray(),
            'cost' => $this->cost,
            'turn_start_step' => $this->turnStartStep,
            'repair_attempts' => $this->repairAttempts,
        ];
    }

    /** @param  array<string, mixed>  $state */
    public static function fromArray(array $state, ?Agent $agent = null): self
    {
        $run = new self($agent);
        $run->status = RunStatus::from($state['status']);
        $run->text = $state['text'] ?? '';
        $run->messages = array_map(Message::fromArray(...), $state['messages'] ?? []);
        $run->steps = array_map(Step::fromArray(...), $state['steps'] ?? []);
        $run->pending = array_map(PendingAction::fromArray(...), $state['pending'] ?? []);
        $run->heldResults = array_map(ToolResult::fromArray(...), $state['held_results'] ?? []);
        $run->usage = Usage::fromArray($state['usage'] ?? []);
        $run->cost = $state['cost'] ?? null;
        $run->turnStartStep = $state['turn_start_step'] ?? 0;
        $run->repairAttempts = $state['repair_attempts'] ?? 0;

        return $run;
    }

    // ---------------------------------------------------------------------
    // Internal: used by Agent while driving the loop
    // ---------------------------------------------------------------------

    /** @internal */
    public function addMessage(Message $message): void
    {
        $this->messages[] = $message;
    }

    /** @internal */
    public function addStep(Step $step): void
    {
        $this->steps[] = $step;
        $this->usage = $this->usage->plus($step->usage);

        if ($step->cost !== null) {
            $this->cost = round(($this->cost ?? 0.0) + $step->cost, 6);
        }

        if ($step->text !== '') {
            $this->text = $step->text;
        }
    }

    /** @internal */
    public function lastStep(): ?Step
    {
        return $this->steps === [] ? null : $this->steps[array_key_last($this->steps)];
    }

    /** @internal Steps taken since the latest user prompt. */
    public function stepsThisTurn(): int
    {
        return count($this->steps) - $this->turnStartStep;
    }

    /**
     * @internal
     *
     * @param  list<PendingAction>  $actions
     * @param  list<ToolResult>  $heldResults
     */
    public function pause(array $actions, array $heldResults): void
    {
        $this->pending = $actions;
        $this->heldResults = $heldResults;
        $this->status = RunStatus::AwaitingApproval;
    }

    /**
     * @internal Hand back the resolved actions and the held results, and clear them.
     *
     * @return array{0: list<PendingAction>, 1: list<ToolResult>}
     */
    public function takeResolved(): array
    {
        $resolved = [$this->pending, $this->heldResults];
        $this->pending = [];
        $this->heldResults = [];
        $this->status = RunStatus::Running;

        return $resolved;
    }

    /** @internal */
    public function hasResolvedActions(): bool
    {
        return $this->pending !== [] && $this->pending() === [];
    }

    /** @internal */
    public function finish(RunStatus $status, mixed $output = null): void
    {
        $this->status = $status;
        $this->output = $output;
    }

    /** @internal */
    public function setStatus(RunStatus $status): void
    {
        $this->status = $status;
    }

    /** @internal */
    public function attach(Agent $agent): void
    {
        $this->agent = $agent;
    }

    /** @internal */
    public function repairAttempts(): int
    {
        return $this->repairAttempts;
    }

    /** @internal */
    public function recordRepairAttempt(): void
    {
        $this->repairAttempts++;
    }
}
