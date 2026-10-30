<?php

namespace App\Support;

/**
 * Turns a stored value into a link only when it safely can be one. Lead
 * data comes from imports and scraping, so it is untrusted: only http(s)
 * URLs and plain email addresses become links ("javascript:" and friends
 * never do).
 */
class SafeLink
{
    /**
     * @return array{href: string, label: string}|null
     */
    public static function parse(?string $value): ?array
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/\s/', $value)) {
            return null;
        }

        if (preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL)) {
            $parts = parse_url($value);

            if (empty($parts['host'])) {
                return null;
            }

            $label = preg_replace('/^www\./i', '', $parts['host']).rtrim($parts['path'] ?? '', '/');

            return ['href' => $value, 'label' => mb_strimwidth($label, 0, 60, '…')];
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['href' => 'mailto:'.$value, 'label' => $value];
        }

        return null;
    }
}
