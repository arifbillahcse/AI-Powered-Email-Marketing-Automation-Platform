<?php

namespace App\Services\Archive;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Moves raw email events (opens, clicks, ...) older than the retention
 * period out of the database into gzipped JSON-lines files on the local
 * disk, grouped by month: archives/email-events/2026-03/{first id}-{last id}.jsonl.gz
 * (`zcat archives/email-events/2026-03/*.gz` reads a whole month).
 *
 * Rows are deleted only after their file is written, in id order, so a
 * crash at worst archives a few rows twice; never loses them.
 */
class EventArchiver
{
    public const DIRECTORY = 'archives/email-events';

    /**
     * @return int Events archived
     */
    public function run(?CarbonImmutable $now = null): int
    {
        $days = (int) config('outreach.archive.events_after_days');

        if ($days <= 0) {
            return 0;
        }

        $cutoff = ($now ?? CarbonImmutable::now())->subDays($days);
        $started = microtime(true);
        $archived = 0;

        while (microtime(true) - $started < (int) config('outreach.archive.seconds_per_run')) {
            $rows = DB::table('email_events')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit((int) config('outreach.archive.chunk'))
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows->groupBy(fn (object $row): string => CarbonImmutable::parse((string) $row->created_at)->format('Y-m')) as $month => $events) {
                $lines = $events->map(fn (object $row): string => json_encode((array) $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->implode("\n")."\n";

                $this->write(self::DIRECTORY."/{$month}/{$events->first()->id}-{$events->last()->id}.jsonl.gz", $lines);
            }

            DB::table('email_events')->whereIn('id', $rows->pluck('id')->all())->delete();
            $archived += $rows->count();
        }

        return $archived;
    }

    protected function write(string $path, string $lines): void
    {
        $compressed = gzencode($lines, 6);

        if ($compressed === false) {
            throw new RuntimeException('Compressing archived events failed.');
        }

        if (! Storage::disk('local')->put($path, $compressed)) {
            throw new RuntimeException("Writing {$path} failed.");
        }
    }
}
