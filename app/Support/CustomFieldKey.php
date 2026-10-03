<?php

namespace App\Support;

use Illuminate\Support\Str;

class CustomFieldKey
{
    /**
     * "Company Size" → "company_size", so any CSV header works as {{company_size}}.
     */
    public static function normalize(string $key): string
    {
        $key = Str::of(Str::ascii($key))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');

        return Str::limit((string) $key, 60, '');
    }
}
