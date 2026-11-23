<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Exceptions;

use Illuminate\Http\Client\Response;

/** The LLM provider returned an error or something we could not understand. */
class ProviderException extends AgentException
{
    public ?int $status = null;

    public static function fromResponse(string $provider, Response $response): self
    {
        $message = $response->json('error.message') ?? mb_substr($response->body(), 0, 500);

        $exception = new self(sprintf('%s request failed with HTTP %d: %s', $provider, $response->status(), $message));
        $exception->status = $response->status();

        return $exception;
    }

    public static function missingApiKey(string $provider): self
    {
        return new self("No API key configured for the [{$provider}] provider. Set it in config/agent.php or your .env file.");
    }
}
