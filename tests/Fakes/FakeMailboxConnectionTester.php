<?php

namespace Tests\Fakes;

use App\Models\EmailAccount;
use App\Services\Mail\ConnectionTestResult;
use App\Services\Mail\MailboxConnectionTester;

class FakeMailboxConnectionTester extends MailboxConnectionTester
{
    /** @var list<string> */
    public array $tested = [];

    public function __construct(
        public ConnectionTestResult $result = new ConnectionTestResult,
    ) {}

    public function test(EmailAccount $account): ConnectionTestResult
    {
        $this->tested[] = $account->email;

        return $this->result;
    }
}
