<?php

namespace App\Jobs;

use App\Models\SendingDomain;
use App\Services\Dns\DomainHealthChecker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckSendingDomain implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public function __construct(
        public SendingDomain $domain,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->domain->getKey();
    }

    public function handle(DomainHealthChecker $checker): void
    {
        $checker->checkAndStore($this->domain);
    }
}
