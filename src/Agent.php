<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use SamirMhsnv\LaravelAgent\Contracts\ActionGuard;
use SamirMhsnv\LaravelAgent\Contracts\LlmProvider;
use SamirMhsnv\LaravelAgent\Contracts\Tool;
use SamirMhsnv\LaravelAgent\Events\AgentStepCompleted;
use SamirMhsnv\LaravelAgent\Events\ApprovalRequested;
use SamirMhsnv\LaravelAgent\Events\ToolCalled;
use SamirMhsnv\LaravelAgent\Exceptions\StructuredOutputException;
use SamirMhsnv\LaravelAgent\Exceptions\ToolException;
use SamirMhsnv\LaravelAgent\Guards\ClosureGuard;
use SamirMhsnv\LaravelAgent\Guards\GuardDecision;
use SamirMhsnv\LaravelAgent\Llm\CostCalculator;
use SamirMhsnv\LaravelAgent\Llm\LlmRequest;
use SamirMhsnv\LaravelAgent\Llm\StopReason;
use SamirMhsnv\LaravelAgent\Messages\Message;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;
use SamirMhsnv\LaravelAgent\Messages\ToolResult;
use SamirMhsnv\LaravelAgent\Support\Hydrator;
use SamirMhsnv\LaravelAgent\Support\Json;
use SamirMhsnv\LaravelAgent\Support\JsonSchemaValidator;
use SamirMhsnv\LaravelAgent\Support\SchemaBuilder;
use SamirMhsnv\LaravelAgent\Tools\ToolRegistry;

/**
 * A configured agent: instructions, tools, guards and a model.
 *
 *     $run = Agent::make()
 *         ->instructions('You answer questions about orders.')
 *         ->tools([LookupOrder::class])
 *         ->maxSteps(8)
 *         ->run('Where is order 1042?');
 *
 * The loop: call the model, run the tools it asks for (subject to guards and
 * approvals), send the results back, repeat until it answers or maxSteps is hit.
 */
class Agent
{
    protected ?string $instructions = null;

    protected ToolRegistry $tools;

    protected int $maxSteps;

    protected ?string $provider = null;

    protected ?string $model = null;

    protected ?int $maxTokens = null;

    /** @var class-string|null */
    protected ?string $expectClass = null;

    /** @var array<string, mixed>|null */
    protected ?array $expectSchema = null;

    /** @var list<ActionGuard> */
    protected array $guards = [];

    /** @var list<Message> */
    protected array $history = [];

    /** @param  array<string, mixed>  $config  The "agent" config array. */
    public function __construct(
        protected AgentManager $manager,
        protected Container $container,
        protected Dispatcher $events,
        protected CostCalculator $costs,
        protected array $config = [],
        protected ?LoggerInterface $logger = null,
    ) {
        $this->tools = new ToolRegistry($container);
        $this->maxSteps = (int) ($config['max_steps'] ?? 8);
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    public function instructions(string $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    /** @param  iterable<Tool|class-string<Tool>>  $tools */
    public function tools(iterable $tools): static
    {
        foreach ($tools as $tool) {
            $this->tools->add($tool);
        }

        return $this;
    }

    /** @param  Tool|class-string<Tool>  $tool */
    public function tool(Tool|string $tool): static
    {
        $this->tools->add($tool);

        return $this;
    }

    /** The maximum number of model calls per prompt. */
    public function maxSteps(int $steps): static
    {
        if ($steps < 1) {
            throw new InvalidArgumentException('maxSteps must be at least 1.');
        }

        $this->maxSteps = $steps;

        return $this;
    }

    /** Pick a provider from config/agent.php, and optionally a model. */
    public function using(string $provider, ?string $model = null): static
    {
        $this->provider = $provider;
        $this->model = $model;

        return $this;
    }

    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function maxTokens(int $maxTokens): static
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    /**
     * Require the final answer to be JSON matching a DTO class or a JSON schema.
     * The validated value is available as $run->output().
     *
     * @param  class-string|array<string, mixed>  $shape
     */
    public function expect(string|array $shape): static
    {
        if (is_string($shape)) {
            $this->expectClass = $shape;
            $this->expectSchema = SchemaBuilder::forClass($shape);
        } else {
            $this->expectClass = null;
            $this->expectSchema = $shape;
        }

        return $this;
    }

    /**
     * Add a guard that sees every tool call before it runs.
     *
     * @param  ActionGuard|class-string<ActionGuard>|Closure(ToolCall, Tool, AgentRun): (GuardDecision|bool|null)  $guard
     */
    public function guard(ActionGuard|Closure|string $guard): static
    {
        $this->guards[] = match (true) {
            $guard instanceof Closure => new ClosureGuard($guard),
            is_string($guard) => $this->container->make($guard),
            default => $guard,
        };

        return $this;
    }

    /**
     * Start from an existing conversation, e.g. one loaded from your database.
     *
     * @param  list<Message>  $messages
     */
    public function withHistory(array $messages): static
    {
        $this->history = array_values($messages);

        return $this;
    }

    public function registry(): ToolRegistry
    {
        return $this->tools;
    }

    // ---------------------------------------------------------------------
    // Running
    // ---------------------------------------------------------------------

    public function run(string $prompt): AgentRun
    {
        $run = new AgentRun($this);

        foreach ($this->history as $message) {
            $run->addMessage($message);
        }

        $run->addMessage(Message::user($prompt));

        return $this->drive($run);
    }

    /**
     * Continue a run stored with $run->toArray(). Configure the agent the same
     * way as for the original run (tools, guards, expect...) before resuming.
     *
     * @param  array<string, mixed>  $state
     */
    public function resume(array $state): AgentRun
    {
        return AgentRun::fromArray($state, $this);
    }

    /**
     * @internal Advance the loop until the run finishes or pauses.
     */
    public function drive(AgentRun $run): AgentRun
    {
        $run->attach($this);

        if ($run->hasResolvedActions()) {
            $this->executeResolvedActions($run);
        }

        $provider = $this->resolveProvider();

        while (true) {
            if ($run->stepsThisTurn() >= $this->maxSteps) {
                return $this->finish($run, RunStatus::MaxStepsReached);
            }

            $response = $provider->chat($this->buildRequest($run, $provider));
            $run->addMessage($response->toMessage());

            $step = new Step(
                index: count($run->steps()),
                text: $response->text,
                toolCalls: $response->toolCalls,
                usage: $response->usage,
                cost: $this->costs->estimate($response->model, $response->usage),
                stopReason: $response->stopReason,
                model: $response->model,
            );
            $run->addStep($step);

            if ($response->toolCalls === []) {
                $this->events->dispatch(new AgentStepCompleted($run, $step));

                if ($response->stopReason === StopReason::Refusal) {
                    return $this->finish($run, RunStatus::Refused);
                }

                if ($this->expectSchema === null) {
                    return $this->finish($run, RunStatus::Completed);
                }

                $output = $this->parseStructured($run, $response->text);

                if ($output !== null) {
                    return $this->finish($run, RunStatus::Completed, $output[0]);
                }

                continue; // a repair prompt was added; ask again
            }

            if ($this->handleToolCalls($run, $step)) {
                $this->events->dispatch(new AgentStepCompleted($run, $step));

                return $run; // paused for approval
            }

            $this->events->dispatch(new AgentStepCompleted($run, $step));
        }
    }

    /** Runs or holds the step's tool calls. Returns true when the run paused for approval. */
    protected function handleToolCalls(AgentRun $run, Step $step): bool
    {
        $results = [];
        $pending = [];

        foreach ($step->toolCalls as $call) {
            $tool = $this->tools->get($call->name);

            if ($tool === null) {
                $results[] = $this->record($run, $step, ToolResult::error($call, "Unknown tool [{$call->name}]."));

                continue;
            }

            $decision = $this->decide($call, $tool, $run);

            if ($decision->denied()) {
                $results[] = $this->record($run, $step, ToolResult::error($call, "Blocked: {$decision->reason}"));

                continue;
            }

            if ($decision->needsApproval()) {
                $pending[] = new PendingAction($call, $decision->reason);

                continue;
            }

            $results[] = $this->execute($run, $step, $call, $tool);
        }

        if ($pending !== []) {
            $run->pause($pending, $results);

            foreach ($pending as $action) {
                $this->events->dispatch(new ApprovalRequested($run, $action));
            }

            return true;
        }

        $run->addMessage(Message::toolResults($results));

        return false;
    }

    protected function decide(ToolCall $call, Tool $tool, AgentRun $run): GuardDecision
    {
        foreach ($this->guards as $guard) {
            $decision = $guard->check($call, $tool, $run);

            if (! $decision->allowed()) {
                return $decision;
            }
        }

        $attribute = ToolRegistry::approvalRequirement($tool);

        return $attribute !== null
            ? GuardDecision::requireApproval($attribute->reason)
            : GuardDecision::allow();
    }

    protected function executeResolvedActions(AgentRun $run): void
    {
        [$actions, $results] = $run->takeResolved();
        $step = $run->lastStep();

        foreach ($actions as $action) {
            $tool = $this->tools->get($action->tool());

            if ($action->status() === PendingAction::REJECTED) {
                $message = 'The user rejected this action.'.($action->rejectionReason() ? " Reason: {$action->rejectionReason()}" : '');
                $results[] = $this->record($run, $step, ToolResult::error($action->call, $message));
            } elseif ($tool === null) {
                $results[] = $this->record($run, $step, ToolResult::error($action->call, "Unknown tool [{$action->tool()}]."));
            } else {
                $results[] = $this->execute($run, $step, $action->call, $tool);
            }
        }

        // Keep the original call order: providers expect results to line up with calls.
        $order = array_flip(array_map(fn (ToolCall $c) => $c->id, $step?->toolCalls ?? []));
        usort($results, fn (ToolResult $a, ToolResult $b) => ($order[$a->toolCallId] ?? 0) <=> ($order[$b->toolCallId] ?? 0));

        $run->addMessage(Message::toolResults($results));
    }

    protected function execute(AgentRun $run, ?Step $step, ToolCall $call, Tool $tool): ToolResult
    {
        $started = hrtime(true);

        try {
            $result = new ToolResult($call->id, $call->name, Json::stringify($tool->execute($call->arguments)));
        } catch (ToolException $e) {
            $result = ToolResult::error($call, $e->getMessage());
        }

        $this->events->dispatch(new ToolCalled($run, $call, $result, (hrtime(true) - $started) / 1e6));

        return $this->record($run, $step, $result, dispatch: false);
    }

    private function record(AgentRun $run, ?Step $step, ToolResult $result, bool $dispatch = true): ToolResult
    {
        $step?->addToolResult($result);

        if ($dispatch) {
            $call = collect($step?->toolCalls ?? [])->first(fn (ToolCall $c) => $c->id === $result->toolCallId)
                ?? new ToolCall($result->toolCallId, $result->name);
            $this->events->dispatch(new ToolCalled($run, $call, $result, 0.0));
        }

        return $result;
    }

    /**
     * Returns [value] on success, or null after queueing a repair prompt.
     *
     * @return array{0: mixed}|null
     */
    protected function parseStructured(AgentRun $run, string $text): ?array
    {
        $errors = [];

        try {
            $value = Json::extract($text);
            $errors = JsonSchemaValidator::validate($value, $this->expectSchema);
        } catch (JsonException $e) {
            $value = null;
            $errors = ['The response is not valid JSON: '.$e->getMessage()];
        }

        if ($errors === []) {
            try {
                return [$this->expectClass !== null ? Hydrator::hydrate($this->expectClass, $value) : $value];
            } catch (ToolException $e) {
                $errors = [$e->getMessage()];
            }
        }

        $maxRepairs = (int) ($this->config['structured_output']['repair_attempts'] ?? 1);

        if ($run->repairAttempts() >= $maxRepairs || $run->stepsThisTurn() >= $this->maxSteps) {
            $this->finish($run, RunStatus::Completed);

            throw new StructuredOutputException(
                'The model did not return output matching the expected schema: '.implode(' ', $errors),
                $errors,
                $text,
            );
        }

        $run->recordRepairAttempt();
        $run->addMessage(Message::user(
            "Your answer did not match the required JSON schema:\n- ".implode("\n- ", $errors)
            ."\nReply again with only the corrected JSON."
        ));

        return null;
    }

    protected function buildRequest(AgentRun $run, LlmProvider $provider): LlmRequest
    {
        $system = $this->instructions;
        $native = (bool) ($this->config['structured_output']['native'] ?? true);

        if ($this->expectSchema !== null) {
            $system = trim(($system ?? '')."\n\nWhen you give your final answer, reply with only a JSON value that matches this JSON schema, with no other text:\n"
                .json_encode(Json::wireSchema($this->expectSchema), JSON_UNESCAPED_SLASHES));
        }

        return new LlmRequest(
            model: $this->model ?? $provider->defaultModel(),
            messages: $run->messages(),
            system: $system,
            tools: $this->tools->definitions(),
            maxTokens: $this->maxTokens,
            responseSchema: $native ? $this->expectSchema : null,
        );
    }

    protected function resolveProvider(): LlmProvider
    {
        return $this->manager->provider($this->provider);
    }

    protected function finish(AgentRun $run, RunStatus $status, mixed $output = null): AgentRun
    {
        $run->finish($status, $output);

        if (($this->config['logging']['enabled'] ?? false) && $this->logger !== null) {
            $last = $run->lastStep();

            $this->logger->info('Agent run finished', [
                'status' => $status->value,
                'provider' => $this->provider ?? $this->manager->defaultProviderName(),
                'model' => $last?->model,
                'steps' => count($run->steps()),
                'tool_calls' => count($run->toolCalls()),
                'usage' => $run->usage()->toArray(),
                'cost_usd' => $run->cost(),
            ]);
        }

        return $run;
    }
}
