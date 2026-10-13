<?php

namespace App\Console\Commands;

use App\Services\Archive\EventArchiver;
use Illuminate\Console\Command;

class ArchiveEvents extends Command
{
    protected $signature = 'events:archive';

    protected $description = 'Move email events older than ARCHIVE_EVENTS_AFTER_DAYS to gzipped files (storage/app/private/archives)';

    public function handle(EventArchiver $archiver): int
    {
        $count = $archiver->run();

        $this->info("Archived {$count} events.");

        return self::SUCCESS;
    }
}
