<?php

namespace SamirMhsnv\LaravelAgent\Tests\Fixtures;

use SamirMhsnv\LaravelAgent\Attributes\AsTool;
use SamirMhsnv\LaravelAgent\Attributes\Param;
use SamirMhsnv\LaravelAgent\Attributes\RequiresApproval;
use SamirMhsnv\LaravelAgent\Tools\Tool;

#[AsTool(name: 'send_email', description: 'Send an email to a customer.')]
#[RequiresApproval(reason: 'Emails reach real customers.')]
final class SendEmail extends Tool
{
    /** @var list<array<string, string>> */
    public static array $sent = [];

    public function handle(string $to, string $subject, #[Param(description: 'Plain text body')] string $body): string
    {
        self::$sent[] = compact('to', 'subject', 'body');

        return "Email sent to {$to}.";
    }
}
