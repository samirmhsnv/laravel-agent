# Laravel Agent

[![tests](https://github.com/samirmhsnv/laravel-agent/actions/workflows/tests.yml/badge.svg)](https://github.com/samirmhsnv/laravel-agent/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

AI agents with tool calling for Laravel. You write tools as plain PHP classes, the package
turns them into JSON schemas, runs the model-tool loop, and gives you a result with the
answer, every step, token usage and an estimated cost.

It is provider-agnostic (Anthropic and OpenAI included, others via a small interface) and
has guardrails built in: tools that need a human's approval pause the run instead of
executing, guards can veto calls by policy, and a read-only SQL tool lets the model query
allowlisted tables without being able to change anything.

```php
$run = Agent::make()
    ->instructions('You help the support team answer questions about orders.')
    ->tools([LookupOrder::class, RefundOrder::class])
    ->maxSteps(8)
    ->run('Where is order 1042, and can we refund it?');

$run->text();       // "Order 1042 shipped on Monday... I've requested a refund, pending approval."
$run->pending();    // [PendingAction refund_order(order_id: 1042)]  <- waits for a human
$run->usage();      // input/output/cache tokens across all steps
$run->cost();       // 0.0123 (USD, estimated from config/agent.php)
```

- [Requirements](#requirements)
- [Installation](#installation)
- [30-second example](#30-second-example)
- [Defining tools](#defining-tools)
- [Approvals (human in the loop)](#approvals-human-in-the-loop)
- [Guards](#guards)
- [Ask your database in plain language, safely](#ask-your-database-in-plain-language-safely)
- [Structured output](#structured-output)
- [Providers](#providers)
- [Events and logging](#events-and-logging)
- [Conversations and the agent:chat REPL](#conversations-and-the-agentchat-repl)
- [Testing your agents](#testing-your-agents)
- [Security notes and honest limits](#security-notes-and-honest-limits)
- [Roadmap](#roadmap)

## Requirements

- PHP 8.3 or newer
- Laravel 12 (Laravel 11 is end-of-life and has unpatched security advisories)
- An API key for Anthropic or OpenAI (or an OpenAI-compatible server)

## Installation

```bash
composer require samirmhsnv/laravel-agent
php artisan vendor:publish --tag=agent-config
```

Add a key to `.env`:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
# optional
AGENT_PROVIDER=anthropic          # or openai
ANTHROPIC_MODEL=claude-sonnet-5-5 # claude-opus-5-5, claude-haiku-5-5, ...
```

## 30-second example

A tool is a class with an `#[AsTool]` attribute and a typed `handle()` method:

```php
use App\Models\Order;
use SamirMhsnv\LaravelAgent\Attributes\AsTool;
use SamirMhsnv\LaravelAgent\Attributes\Param;
use SamirMhsnv\LaravelAgent\Tools\Tool;

#[AsTool(name: 'lookup_order', description: 'Look up an order by its number. Returns status, total and shipping date.')]
final class LookupOrder extends Tool
{
    public function handle(#[Param(description: 'The order number, e.g. 1042')] int $number): array
    {
        $order = Order::where('number', $number)->first();

        return $order?->only(['number', 'status', 'total', 'shipped_at']) ?? ['error' => 'No such order'];
    }
}
```

Give it to an agent and ask a question:

```php
use SamirMhsnv\LaravelAgent\Facades\Agent;

$run = Agent::make()
    ->instructions('You answer questions about orders. Be brief.')
    ->tools([LookupOrder::class])
    ->run('Has order 1042 shipped yet?');

echo $run->text(); // "Yes, order 1042 shipped on 3 November."
```

What happened: the model saw the `lookup_order` schema, asked to call it with
`{"number": 1042}`, the package validated and cast the arguments, called `handle()`, sent the
JSON result back, and the model wrote the answer. That is two model calls, or two "steps".

The run object tells you everything that happened:

```php
$run->status();      // RunStatus::Completed | AwaitingApproval | MaxStepsReached | Refused
$run->steps();       // list<Step>: text, toolCalls, toolResults(), usage, cost, model for each call
$run->toolCalls();   // every ToolCall across the run
$run->toolResults(); // every ToolResult, including errors and rejections
$run->usage();       // Usage: inputTokens, outputTokens, cacheReadTokens, cacheWriteTokens
$run->cost();        // ?float, USD
$run->messages();    // the full provider-neutral transcript
```

## Defining tools

The JSON schema comes from `handle()`'s signature:

| PHP                                   | JSON schema                                      |
| ------------------------------------- | ------------------------------------------------ |
| `string`, `int`, `float`, `bool`      | `string`, `integer`, `number`, `boolean`         |
| `array` + `#[Param(items: 'integer')]`| `array` of `integer` (default item type: string) |
| a backed enum                         | `string`/`integer` with `enum` of its values     |
| a class with a constructor (a DTO)    | nested `object` built from its parameters        |
| `?type` or a default value            | optional (nullable types also allow `null`)      |

```php
enum Priority: string { case Low = 'low'; case High = 'high'; }

#[AsTool(name: 'create_ticket', description: 'Open a support ticket for a customer.')]
final class CreateTicket extends Tool
{
    public function __construct(private TicketService $tickets) {} // resolved from the container

    public function handle(
        #[Param(description: 'Customer email')] string $email,
        #[Param(description: 'One-line summary')] string $title,
        Priority $priority = Priority::Low,
        #[Param(items: 'string', description: 'Labels such as "billing"')] array $labels = [],
    ): string {
        $ticket = $this->tickets->open($email, $title, $priority, $labels);

        return "Created ticket #{$ticket->id}.";
    }
}
```

Arguments from the model are validated and cast before `handle()` runs (`"3"` becomes `3`
for an `int`, `"high"` becomes `Priority::High`). If something is missing or wrong, the
model gets an error result such as `Missing required argument [email].` and usually fixes
it on the next step.

**Errors.** Throw `SamirMhsnv\LaravelAgent\Exceptions\ToolException` for problems the model
should hear about ("no such customer"). It becomes an error result and the loop continues.
Any other exception is a bug in your code and bubbles up as usual.

**Return values.** Strings are sent as-is; arrays, models, collections and anything
`JsonSerializable` are JSON-encoded.

**Tools without the base class.** Implement `SamirMhsnv\LaravelAgent\Contracts\Tool`
(`name()`, `description()`, `parameters()`, `execute(array $arguments)`) when you want to
write the schema yourself. `SafeSqlTool` does this.

## Approvals (human in the loop)

Mark a tool with `#[RequiresApproval]` and it will never run on the model's say-so alone:

```php
use SamirMhsnv\LaravelAgent\Attributes\RequiresApproval;

#[AsTool(name: 'refund_order', description: 'Refund an order in full.')]
#[RequiresApproval(reason: 'Refunds move money.')]
final class RefundOrder extends Tool
{
    public function handle(int $number): string { /* ... */ }
}
```

When the model calls it, the run pauses. Other, safe tool calls from the same step still
run; their results are held until the decision is made.

```php
$run = Agent::make()->tools([LookupOrder::class, RefundOrder::class])
    ->run('Order 1042 arrived broken, please refund it.');

$run->status();                 // RunStatus::AwaitingApproval
$action = $run->pending()[0];
$action->tool();                // "refund_order"
$action->arguments();           // ['number' => 1042]
$action->reason;                // "Refunds move money."

$run->approve($action->id());   // runs the tool, resumes the loop, returns the run
// or
$run->reject($action->id(), 'Refunds over $500 need a manager.'); // the model is told, and why
```

In a web app the approval usually happens in a later request. Store the state and resume it
with an identically configured agent:

```php
// Request 1: the agent pauses
Cache::put("agent-run:{$ticket->id}", $run->toArray(), now()->addDay());
Notification::send($manager, new RefundNeedsApproval($run->pending()[0]));

// Request 2: the manager clicks "Approve"
$run = Agent::make()
    ->tools([LookupOrder::class, RefundOrder::class])   // same tools as before
    ->resume(Cache::pull("agent-run:{$ticket->id}"))
    ->approve($actionId);

return $run->text();
```

`toArray()` is plain JSON-safe data, so a database column works just as well as the cache.

## Guards

A guard sees every tool call before it runs and returns a decision: allow, deny (the model
gets the reason) or require approval. Use it for rules that depend on arguments or context
rather than on the tool alone.

```php
use SamirMhsnv\LaravelAgent\AgentRun;
use SamirMhsnv\LaravelAgent\Contracts\ActionGuard;
use SamirMhsnv\LaravelAgent\Contracts\Tool;
use SamirMhsnv\LaravelAgent\Guards\GuardDecision;
use SamirMhsnv\LaravelAgent\Messages\ToolCall;

final class RefundLimitGuard implements ActionGuard
{
    public function check(ToolCall $call, Tool $tool, AgentRun $run): GuardDecision
    {
        if ($call->name !== 'refund_order') {
            return GuardDecision::allow();
        }

        $order = Order::firstWhere('number', $call->arguments['number'] ?? null);

        return match (true) {
            $order === null => GuardDecision::deny('That order does not exist.'),
            $order->user_id !== auth()->id() => GuardDecision::deny('You can only refund your own orders.'),
            $order->total > 500 => GuardDecision::requireApproval('Refunds over $500 need a manager.'),
            default => GuardDecision::allow(),
        };
    }
}

Agent::make()->tools([...])->guard(RefundLimitGuard::class);

// Closures work too: return a GuardDecision, false (deny) or true/null (allow).
Agent::make()->guard(fn (ToolCall $call) => $call->name !== 'delete_account');
```

Name-based rules for every agent live in `config/agent.php` (wildcards allowed):

```php
'policy' => [
    'deny' => ['delete_*'],
    'require_approval' => ['send_email', 'refund_*'],
],
'guards' => [App\Agents\RefundLimitGuard::class], // applied to every Agent::make()
```

Guards run in order; the first one that does not allow decides. A tool with
`#[RequiresApproval]` needs approval even if every guard allows it.

## Ask your database in plain language, safely

`SafeSqlTool` lets the model answer questions like "which five products earned the most
last month?" by writing SQL, but only read-only SQL over tables you allowlist.

```php
// config/agent.php
'sql' => [
    'connection' => 'reporting',            // use a database user with SELECT permission only
    'tables' => ['orders', 'order_items', 'products'],
    'max_rows' => 100,
],
```

```php
use SamirMhsnv\LaravelAgent\Sql\SafeSqlTool;

$run = Agent::make()
    ->instructions('You are a data analyst. Answer with numbers from the database, and show the query you used.')
    ->tools([SafeSqlTool::class])
    ->run('Which 5 products earned the most revenue in October 2026?');
```

The tool description includes the allowed tables, their columns and the SQL dialect, so the
model knows what it can query. Every query goes through these checks before it reaches the
database:

- exactly one statement (one trailing `;` is fine; `SELECT 1; DROP TABLE x` is not)
- must start with `SELECT` or `WITH`
- no comments at all (`--`, `#`, `/* */`, MySQL's `/*! */`), which closes most comment-based tricks
- no write, DDL, locking, transaction or session keywords anywhere outside string literals
  (`INSERT`, `UPDATE`, `DELETE`, `INTO`, `FOR UPDATE`, `PRAGMA`, `ATTACH`, `SET`, ...),
  including data-modifying CTEs such as `WITH x AS (DELETE ... RETURNING *)`
- no dangerous functions (`SLEEP`, `pg_sleep`, `load_extension`, `pg_read_file`, `dblink`, ...)
- every table after `FROM`/`JOIN`, in subqueries, unions and comma joins, must be on the
  allowlist; CTE names are recognised; system catalogs are therefore off-limits
- no table-valued functions in `FROM` (`generate_series(...)`, `pragma_table_info(...)`)
- no backslashes inside string literals, because MySQL and Postgres disagree on what they mean
- a maximum query length

Then the query is wrapped as `SELECT * FROM (<query>) AS agent_query LIMIT <max_rows + 1>`,
so a row limit applies whatever the model wrote, and it runs inside a transaction that is
always rolled back. A rejected query throws `UnsafeQueryException` with a clear message
(`Query rejected: table [users] is not allowed (allowed tables: orders, products).`), which
the model sees and can act on.

You can also use it directly, outside an agent:

```php
app(SafeSqlTool::class)->query('SELECT status, COUNT(*) AS n FROM orders GROUP BY status');
// ['columns' => ['status', 'n'], 'rows' => [...], 'row_count' => 3, 'truncated' => false]
```

Read the [security notes](#security-notes-and-honest-limits) before pointing this at
production data.

## Structured output

Ask for the final answer as a DTO (the schema is generated from its constructor) or as a
JSON schema array:

```php
final readonly class TicketTriage
{
    public function __construct(
        public Priority $priority,
        #[Param(description: 'One of: billing, shipping, product, other')] public string $category,
        public string $summary,
        public bool $needsHuman,
    ) {}
}

$run = Agent::make()->expect(TicketTriage::class)->run($email->body);

$triage = $run->output(); // TicketTriage instance, validated
```

```php
$run = Agent::make()->expect([
    'type' => 'object',
    'properties' => ['sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']]],
    'required' => ['sentiment'],
    'additionalProperties' => false,
])->run('Classify: "The update broke my export"');

$run->output(); // ['sentiment' => 'negative']
```

The package asks the provider for schema-constrained JSON where it supports it
(`output_config.format` on Anthropic, `response_format` on OpenAI), and always validates the
answer itself. If validation fails, the model gets one chance to correct it (configurable);
after that a `StructuredOutputException` is thrown with the errors and the raw text. Tools and
`expect()` can be combined: the model may call tools first and answer in JSON at the end.

## Providers

```php
// config/agent.php
'default' => env('AGENT_PROVIDER', 'anthropic'),

'providers' => [
    'anthropic' => [
        'driver' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'version' => '2026-10-21',  // anthropic-version header
        'effort' => null,           // low|medium|high|xhigh|max, null = model default
        'max_tokens' => 16000,
    ],
    'openai' => [
        'driver' => 'openai',
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5'),
    ],
    // Any OpenAI-compatible server (vLLM, Ollama, OpenRouter, ...) as its own entry:
    'local' => [
        'driver' => 'openai',
        'api_key' => 'unused',
        'base_url' => 'http://localhost:11434/v1',
        'model' => 'qwen3:32b',
    ],
],
```

Pick one per agent:

```php
Agent::make()->using('openai')->run('...');
Agent::make()->using('anthropic', 'claude-opus-5-5')->run('...');
Agent::make()->model('claude-haiku-5-5')->run('...'); // default provider, other model
```

Both built-in providers use Laravel's HTTP client (no vendor SDKs), retry on connection
errors, 408/409/429 and 5xx, and throw `ProviderException` with the API's error message
otherwise. The Anthropic provider sends assistant turns back with their original content
blocks, which the Messages API requires for thinking blocks.

**Adding a provider.** Implement `SamirMhsnv\LaravelAgent\Contracts\LlmProvider` (one
`chat(LlmRequest): LlmResponse` method plus `name()` and `defaultModel()`) and register it:

```php
// AppServiceProvider::boot()
Agent::extend('gemini', fn ($app, array $config, string $name) => new GeminiProvider($config));
```

Then add `'gemini' => ['driver' => 'gemini', ...]` to `providers`.

**Cost.** `$run->cost()` is an estimate from the `pricing` table in the config (USD per
million tokens, cache reads and writes priced separately). The table ships with Anthropic's
current models; add your own models there. Models without a price give `null` rather than a
wrong number.

## Events and logging

| Event                                | When                                                               |
| ------------------------------------ | ------------------------------------------------------------------ |
| `Events\AgentStepCompleted`          | after each model call, with the `Step` (text, tool calls, usage)   |
| `Events\ToolCalled`                  | for every tool call, including blocked, rejected and failed ones; has the duration |
| `Events\ApprovalRequested`           | when a call is held for a human; a good place to send a notification |

```php
Event::listen(function (ApprovalRequested $event) {
    Slack::to('#approvals')->send("Agent wants to run {$event->action->tool()}: ".json_encode($event->action->arguments()));
});

Event::listen(fn (ToolCalled $e) => Log::debug("tool {$e->call->name} took {$e->durationMs}ms"));
```

When a run finishes, one log line is written with status, steps, tool call count, tokens and
estimated cost (`logging.enabled`, `logging.channel` in the config).

## Conversations and the agent:chat REPL

Continue a conversation with `continue()`, or start from stored messages with
`withHistory()`:

```php
$run = Agent::make()->tools([LookupOrder::class])->run('Has order 1042 shipped?');
$run->continue('And when will it arrive?');
```

`maxSteps` applies per prompt, not to the whole conversation.

For trying tools out, there is an interactive REPL. It uses the tools in
`config('agent.chat.tools')` plus any you pass with `--tool`, prints each tool call, asks
before running tools that need approval, and shows tokens and cost:

```bash
php artisan agent:chat
php artisan agent:chat --provider=openai --tool="App\Agents\Tools\LookupOrder"
```

## Testing your agents

No real API calls are needed: fake the HTTP layer with `Http::fake()`. The package ships
response builders for both providers.

```php
use Illuminate\Support\Facades\Http;
use SamirMhsnv\LaravelAgent\Testing\AnthropicResponse;

it('refunds after approval', function () {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(AnthropicResponse::toolUse('lookup_order', ['number' => 1042]))
        ->push(AnthropicResponse::toolUse('refund_order', ['number' => 1042], id: 'toolu_refund'))
        ->push(AnthropicResponse::text('Refunded order 1042.')),
    ]);

    $run = Agent::make()->tools([LookupOrder::class, RefundOrder::class])->run('Refund 1042');

    expect($run->awaitingApproval())->toBeTrue();

    $run->approve('toolu_refund');

    expect($run->text())->toBe('Refunded order 1042.');
    Http::assertSentCount(3);
});
```

`OpenAiResponse::text()` / `::toolCall()` / `::toolCalls()` do the same for OpenAI. Use
`Http::recorded()` to assert on exactly what was sent to the model (system prompt, tool
schemas, tool results).

The package's own suite (Pest + Testbench, in-memory SQLite) covers schema generation, the
loop for both providers, parallel tool calls, maxSteps, approval pause/resume including
storing and resuming state, guards, structured output and repair, and a long list of
accepted and rejected SQL queries.

```bash
composer test
```

## Security notes and honest limits

Please read this before giving an agent access to anything that matters.

- **The model's output is untrusted input.** Tool arguments come from a model that may have
  read a malicious email, web page or database row (prompt injection). Validate in your tools
  as you would validate a form request, and authorize against the *current user*, not against
  what the model claims.
- **Approval is your strongest control.** Anything that sends, pays, deletes or is hard to
  undo should be `#[RequiresApproval]`, and the approval screen should show the real
  arguments, not the model's summary of them.
- **SafeSqlTool is one layer, not the whole defence.** The validator is a conservative
  tokenizer, not a full SQL parser, and it errs on the side of rejecting. It does not
  understand every dialect's corner cases. Always run it on a connection whose database user
  can only `SELECT` from the allowlisted tables (and ideally on a read replica). Also note:
  - it does not do row-level security: if a table is allowlisted, the model can read every
    row and column in it. Use a view with only the columns and rows you want to expose,
    and allowlist the view.
  - it cannot stop expensive-but-valid queries (a big cross join). Set a statement timeout on
    the database user (`statement_timeout` in Postgres, `max_execution_time` in MySQL).
  - a column literally named like a forbidden keyword (`set`, `lock`) must be quoted.
  - results are sent to your LLM provider. Do not allowlist tables with data you are not
    allowed to share with them.
- **Costs are estimates.** They come from your price table and the token counts the provider
  reports. Check your provider's billing for real numbers.
- **What this package does not do (yet):** streaming responses, images and files as input,
  running tool calls concurrently, provider-side prompt caching controls, and
  automatic conversation summarisation for very long histories. `maxSteps` and `max_tokens`
  are your budget controls.
- **Resuming requires the same configuration.** A stored run holds the conversation, not the
  agent. Resume it with the same tools and guards, otherwise pending calls may no longer
  resolve to a tool.

## Roadmap

- Streaming (`->stream()`), with events for text deltas and tool calls
- Prompt caching controls for Anthropic (cache the system prompt and tool definitions)
- An `AgentFake` for asserting on runs without building HTTP responses
- Queueable runs (`->dispatch()`) and a database-backed store for paused runs
- More providers: Gemini, Mistral, Bedrock
- Tools from MCP servers
- `SafeSqlTool`: optional column allowlists and per-table row filters

Contributions and issues are welcome.

## License

MIT. See [LICENSE](LICENSE).
