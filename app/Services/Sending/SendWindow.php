<?php

namespace App\Services\Sending;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Is it OK to send right now?" for a schedule of weekdays + hours in a
 * time zone, and "when does today start?" for daily limits.
 */
class SendWindow
{
    /**
     * @param  list<int>  $days  ISO weekdays (1 = Monday)
     */
    public static function isOpen(CarbonInterface $now, string $timezone, array $days, string $start, string $end): bool
    {
        $local = CarbonImmutable::instance($now)->setTimezone($timezone);

        if (! in_array($local->dayOfWeekIso, array_map('intval', $days), true)) {
            return false;
        }

        $time = $local->format('H:i');

        return $time >= $start && $time < $end;
    }

    /**
     * Start of "today" in the time zone, as a UTC instant, for daily counts.
     */
    public static function startOfDay(CarbonInterface $now, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->setTimezone($timezone)->startOfDay()->utc();
    }
}
