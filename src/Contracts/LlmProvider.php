<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Contracts;

use SamirMhsnv\LaravelAgent\Llm\LlmRequest;
use SamirMhsnv\LaravelAgent\Llm\LlmResponse;

/**
 * One model call: messages and tool definitions in, text and tool calls out.
 *
 * Providers translate between this package's neutral message format and a
 * vendor's wire format. They do not loop; the Agent does.
 */
interface LlmProvider
{
    public function name(): string;

    public function defaultModel(): string;

    public function chat(LlmRequest $request): LlmResponse;
}
