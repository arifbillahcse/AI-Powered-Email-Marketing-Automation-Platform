<?php

namespace App\Jobs;

use App\Mail\ClientReportMail;
use App\Models\Workspace;
use App\Services\Agency\Branding;
use App\Services\Agency\ClientReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a workspace's client report to its report recipients. Not
 * retried, so nobody gets the same report twice.
 */
class SendClientReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(
        public int $workspaceId,
    ) {}

    public function handle(ClientReport $reports, Branding $branding): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if (! $workspace || $workspace->isSuspended()) {
            return;
        }

        $recipients = collect($workspace->report_recipients ?? [])
            ->filter(fn ($email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->take(10)
            ->values()
            ->all();

        if ($recipients === []) {
            return;
        }

        $days = $workspace->report_frequency === 'monthly' ? 30 : 7;

        Mail::to($recipients)->send(new ClientReportMail(
            $reports->data($workspace, $days),
            $reports->url($workspace).'?days='.$days,
            $branding->nameFor($workspace),
        ));
    }
}
