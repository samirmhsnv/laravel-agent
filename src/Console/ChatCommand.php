<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Console;

use Illuminate\Console\Command;
use SamirMhsnv\LaravelAgent\AgentManager;
use SamirMhsnv\LaravelAgent\AgentRun;
use SamirMhsnv\LaravelAgent\RunStatus;
use SamirMhsnv\LaravelAgent\Step;

/**
 * Interactive REPL against the tools listed in config('agent.chat.tools').
 */
class ChatCommand extends Command
{
    protected $signature = 'agent:chat
        {--provider= : Provider name from config/agent.php}
        {--model= : Model id (defaults to the provider\'s model)}
        {--tool=* : Extra tool classes to load}
        {--max-steps= : Model calls allowed per message}';

    protected $description = 'Chat with an agent and its configured tools in the terminal';

    public function handle(AgentManager $manager): int
    {
        $tools = array_values(array_unique([...config('agent.chat.tools', []), ...$this->option('tool')]));

        $agent = $manager->make()
            ->instructions((string) config('agent.chat.instructions', ''))
            ->tools($tools);

        if ($this->option('provider') || $this->option('model')) {
            $agent->using($this->option('provider') ?: $manager->defaultProviderName(), $this->option('model') ?: null);
        }

        if ($this->option('max-steps')) {
            $agent->maxSteps((int) $this->option('max-steps'));
        }

        $names = array_keys($agent->registry()->all());
        $this->components->info('Agent chat. Tools: '.($names === [] ? 'none' : implode(', ', $names)).'. Type "exit" to quit.');

        $run = null;

        while (true) {
            $prompt = trim((string) $this->ask('You'));

            if ($prompt === '') {
                continue;
            }

            if (in_array(strtolower($prompt), ['exit', 'quit', ':q'], true)) {
                return self::SUCCESS;
            }

            $seen = $run === null ? 0 : count($run->steps());
            $run = $run === null ? $agent->run($prompt) : $run->continue($prompt);
            $run = $this->settleApprovals($run, $seen);

            $this->printToolActivity($run, $seen);
            $this->newLine();
            $this->line('<fg=cyan>Agent</> '.($run->text() !== '' ? $run->text() : '(no answer)'));

            if ($run->status() !== RunStatus::Completed) {
                $this->components->warn("Run ended with status: {$run->status()->value}");
            }

            $cost = $run->cost() === null ? 'n/a' : '$'.number_format($run->cost(), 4);
            $this->line(sprintf(
                '<fg=gray>%d steps, %d tokens in, %d tokens out, est. cost %s (whole conversation)</>',
                count($run->steps()),
                $run->usage()->inputTokens + $run->usage()->cacheReadTokens,
                $run->usage()->outputTokens,
                $cost,
            ));
            $this->newLine();
        }
    }

    private function settleApprovals(AgentRun $run, int &$seen): AgentRun
    {
        while ($run->awaitingApproval()) {
            $this->printToolActivity($run, $seen);
            $seen = count($run->steps());

            foreach ($run->pending() as $action) {
                $question = sprintf(
                    'Allow %s(%s)?%s',
                    $action->tool(),
                    json_encode($action->arguments(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $action->reason ? " ({$action->reason})" : '',
                );

                $run = $this->confirm($question)
                    ? $run->approve($action->id())
                    : $run->reject($action->id(), 'Declined in the terminal.');

                if (! $run->awaitingApproval() || $run->pending() === []) {
                    break;
                }
            }
        }

        return $run;
    }

    private function printToolActivity(AgentRun $run, int $fromStep): void
    {
        /** @var list<Step> $steps */
        $steps = array_slice($run->steps(), $fromStep);

        foreach ($steps as $step) {
            foreach ($step->toolResults() as $result) {
                $preview = mb_strimwidth(str_replace("\n", ' ', $result->content), 0, 120, '...');
                $this->line(sprintf('  <fg=gray>%s %s -> %s</>', $result->isError ? '[x]' : '[tool]', $result->name, $preview));
            }
        }
    }
}
