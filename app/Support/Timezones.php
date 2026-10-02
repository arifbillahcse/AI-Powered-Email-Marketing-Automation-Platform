<?php

namespace App\Support;

use DateTimeZone;

class Timezones
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $zones = DateTimeZone::listIdentifiers();

        return array_combine($zones, array_map(
            fn (string $zone): string => str_replace(['/', '_'], [' / ', ' '], $zone),
            $zones,
        ));
    }
}
