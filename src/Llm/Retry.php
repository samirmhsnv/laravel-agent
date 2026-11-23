<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/** @internal Shared retry policy: connection errors, 408, 409, 429 and 5xx. */
final class Retry
{
    public static function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return in_array($status, [408, 409, 429], true) || $status >= 500;
        }

        return false;
    }
}
