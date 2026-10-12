<?php

namespace App\Services\Leads;

use App\Enums\EmailVerificationStatus;
use App\Jobs\VerifyLeadEmails;
use App\Models\Lead;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Number;

/**
 * Queues verification for a selection of leads (any size) and tells the
 * user the results when the whole batch is done.
 */
class EmailVerification
{
    public const CHUNK = 200;

    /**
     * @param  Builder<Lead>  $leads
     * @return int Leads queued
     */
    public function queue(int $workspaceId, Builder $leads, ?User $by = null): int
    {
        $jobs = [];
        $count = 0;

        (clone $leads)
            ->setEagerLoads([])
            ->where('leads.workspace_id', $workspaceId)
            ->select('leads.id')
            ->reorder()
            ->chunkById(self::CHUNK, function ($chunk) use ($workspaceId, &$jobs, &$count): void {
                $ids = $chunk->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $jobs[] = new VerifyLeadEmails($workspaceId, $ids);
                $count += count($ids);
            }, 'leads.id', 'id');

        if ($jobs === []) {
            return 0;
        }

        $since = now()->subSecond()->toDateTimeString();
        $userId = $by?->getKey();

        Bus::batch($jobs)
            ->name('verify-leads')
            ->onQueue('imports')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($workspaceId, $userId, $since): void {
                if ($userId && ($user = User::query()->find($userId))) {
                    app(EmailVerification::class)->notify($user, $workspaceId, $since);
                }
            })
            ->dispatch();

        return $count;
    }

    public function notify(User $user, int $workspaceId, string $since): void
    {
        $counts = Lead::query()
            ->where('workspace_id', $workspaceId)
            ->where('verified_at', '>=', $since)
            ->groupBy('verification_status')
            ->selectRaw('verification_status, count(*) as total')
            ->pluck('total', 'verification_status');

        $parts = collect(EmailVerificationStatus::cases())
            ->filter(fn (EmailVerificationStatus $status): bool => (int) ($counts[$status->value] ?? 0) > 0)
            ->map(fn (EmailVerificationStatus $status): string => Number::format((int) $counts[$status->value]).' '.strtolower($status->getLabel()));

        Notification::make()
            ->title('Email verification finished')
            ->body($parts->implode(', ').'. Invalid addresses are never emailed by campaigns.')
            ->success()
            ->sendToDatabase($user);
    }
}
