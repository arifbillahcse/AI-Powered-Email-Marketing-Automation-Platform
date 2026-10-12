<?php

namespace App\Jobs;

use App\Models\SendingDomain;
use App\Models\User;
use App\Services\Compliance\BlacklistChecker;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * DNSBL lookups for one sending domain (a few dozen DNS queries). New
 * listings alert the workspace's owners and admins; a user who asked for
 * the check also gets the result.
 */
class CheckDomainBlacklists implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public int $timeout = 45;

    public function __construct(
        public int $sendingDomainId,
        public ?int $requestedBy = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->sendingDomainId;
    }

    public function handle(BlacklistChecker $checker): void
    {
        $domain = SendingDomain::query()->find($this->sendingDomainId);

        if (! $domain) {
            return;
        }

        $result = $checker->checkAndStore($domain);

        if ($this->requestedBy && ($user = User::query()->find($this->requestedBy))) {
            $notification = Notification::make()->title("Blacklist check for {$domain->name}");

            if ($result['listings'] !== []) {
                $notification->body('Listed on '.collect($result['listings'])->map(fn (array $listing): string => "{$listing['list']} ({$listing['target']})")->implode(', ').'.')->danger();
            } else {
                $notification->body('Not listed on '.implode(', ', array_diff($result['checked'], $result['unknown'])).'.'
                    .($result['unknown'] !== [] ? ' Couldn\'t check: '.implode(', ', $result['unknown']).'.' : ''))->success();
            }

            $notification->sendToDatabase($user);
        }
    }
}
