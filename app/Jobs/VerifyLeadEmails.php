<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Leads\EmailVerifier;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Verifies a chunk of leads. Stops before the cron worker's time limit and
 * queues the rest as a new job in the same batch.
 */
class VerifyLeadEmails implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 50;

    /**
     * @param  list<int>  $leadIds
     */
    public function __construct(
        public int $workspaceId,
        public array $leadIds,
    ) {
        $this->onQueue('imports');
    }

    public function handle(EmailVerifier $verifier): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $started = microtime(true);
        $remaining = $this->leadIds;

        while ($remaining !== []) {
            if (microtime(true) - $started > (int) config('outreach.verification.seconds_per_job', 35)) {
                $next = new self($this->workspaceId, $remaining);
                $this->batch() ? $this->batch()->add([$next]) : dispatch($next);

                return;
            }

            $id = array_shift($remaining);
            $email = Lead::query()->where('workspace_id', $this->workspaceId)->whereKey($id)->value('email');

            if ($email === null) {
                continue;
            }

            $result = $verifier->verify($email);

            // A query update: verification isn't a user edit for the timeline.
            Lead::query()->whereKey($id)->update([
                'verification_status' => $result['status']->value,
                'verification_reason' => mb_substr($result['reason'], 0, 255),
                'verified_at' => now(),
            ]);
        }
    }
}
