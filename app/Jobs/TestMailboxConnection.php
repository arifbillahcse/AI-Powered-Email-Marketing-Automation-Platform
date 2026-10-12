<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Services\Mail\MailboxConnectionTester;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Logs in to one mailbox's SMTP and IMAP servers and records the result
 * (used after a bulk import, where testing inline would take too long).
 */
class TestMailboxConnection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(
        public int $emailAccountId,
    ) {}

    public function handle(MailboxConnectionTester $tester): void
    {
        $account = EmailAccount::query()->find($this->emailAccountId);

        if ($account) {
            $tester->testAndRecord($account);
        }
    }
}
