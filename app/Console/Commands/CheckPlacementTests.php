<?php

namespace App\Console\Commands;

use App\Jobs\CheckPlacementSeed;
use App\Models\PlacementResult;
use App\Models\PlacementTest;
use App\Models\User;
use App\Services\Placement\PlacementTester;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class CheckPlacementTests extends Command
{
    protected $signature = 'placement:check';

    protected $description = 'Check seed mailboxes for inbox placement test emails, and finish tests that are done';

    public function handle(PlacementTester $tester): int
    {
        $wait = (int) config('outreach.placement.wait_minutes');

        // Not found in time: missing (blocked, or still in transit).
        PlacementResult::query()
            ->where('status', 'pending')
            ->whereIn('placement_test_id', PlacementTest::query()->select('id')->where('status', 'checking')->where('sent_at', '<', now()->subMinutes($wait)))
            ->update(['status' => 'missing', 'checked_at' => now(), 'updated_at' => now()]);

        $seeds = PlacementResult::query()
            ->where('status', 'pending')
            ->whereIn('placement_test_id', PlacementTest::query()->select('id')->where('status', 'checking')->where('sent_at', '<=', now()->subMinute()))
            ->distinct()
            ->pluck('seed_id');

        foreach ($seeds as $seedId) {
            CheckPlacementSeed::dispatch((int) $seedId);
        }

        $finished = 0;

        PlacementTest::query()
            ->where('status', 'checking')
            ->whereDoesntHave('results', fn ($query) => $query->where('status', 'pending'))
            ->each(function (PlacementTest $test) use ($tester, &$finished): void {
                $done = PlacementTest::query()->whereKey($test->getKey())->where('status', 'checking')->update(['status' => 'done', 'finished_at' => now()]);

                if ($done !== 1) {
                    return;
                }

                $finished++;
                $summary = $tester->summary($test);

                if ($test->created_by && ($user = User::query()->find($test->created_by))) {
                    Notification::make()
                        ->title('Inbox placement test finished')
                        ->body("\"{$test->subject}\": {$summary['inbox']} inbox, {$summary['spam']} spam, {$summary['missing']} missing.")
                        ->status($summary['inbox'] < $summary['spam'] + $summary['missing'] ? 'warning' : 'success')
                        ->sendToDatabase($user);
                }
            });

        $this->info("Checking {$seeds->count()} seed mailboxes; {$finished} tests finished.");

        return self::SUCCESS;
    }
}
