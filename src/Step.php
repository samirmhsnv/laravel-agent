<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent;

use SamirMhsnv\LaravelAgent\Llm\StopReason;
use SamirMhsnv\LaravelAgent\Llm\Usage;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;
use SamirMhsnv\LaravelAgent\Messages\ToolResult;

/**
 * One model call inside a run, plus the tool calls it asked for and their results.
 */
final class Step
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolResult>  $toolResults
     */
    public function __construct(
        public readonly int $index,
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly Usage $usage,
        public readonly ?float $cost,
        public readonly StopReason $stopReason,
        public readonly string $model,
        private array $toolResults = [],
    ) {}

    /** @return list<ToolResult> */
    public function toolResults(): array
    {
        return $this->toolResults;
    }

    /** @internal */
    public function addToolResult(ToolResult $result): void
    {
        $this->toolResults[] = $result;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'text' => $this->text,
            'tool_calls' => array_map(fn (ToolCall $c) => $c->toArray(), $this->toolCalls),
            'tool_results' => array_map(fn (ToolResult $r) => $r->toArray(), $this->toolResults),
            'usage' => $this->usage->toArray(),
            'cost' => $this->cost,
            'stop_reason' => $this->stopReason->value,
            'model' => $this->model,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['index'],
            $data['text'],
            array_map(ToolCall::fromArray(...), $data['tool_calls'] ?? []),
            Usage::fromArray($data['usage'] ?? []),
            $data['cost'] ?? null,
            StopReason::from($data['stop_reason']),
            $data['model'],
            array_map(ToolResult::fromArray(...), $data['tool_results'] ?? []),
        );
    }
}
