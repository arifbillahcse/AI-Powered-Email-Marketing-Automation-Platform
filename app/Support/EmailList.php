<?php

namespace App\Support;

/**
 * Reads email addresses out of a spreadsheet cell. Scraped lists often put
 * several in one cell ("info@acme.com; sales@acme.com, abuse@acme.com") and
 * mix in website placeholders ("name@company.com") and file names
 * ("logo@2x.png"). Only the first sensible one becomes the lead's email.
 */
class EmailList
{
    /** Mailboxes that read like a complaints desk: never the first choice for outreach. */
    public const AVOID = [
        'abuse', 'privacy', 'dpo', 'gdpr', 'legal', 'compliance', 'security', 'postmaster', 'hostmaster',
        'noc', 'tac', 'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'unsubscribe', 'mailer-daemon',
    ];

    /** Domains that only appear as examples on websites. */
    protected const PLACEHOLDER_DOMAINS = [
        'company.com', 'example.com', 'example.org', 'example.net', 'domain.com', 'yourdomain.com', 'yourcompany.com',
        'email.com', 'mysite.com', 'website.com', 'test.com', 'sentry.io', 'wixpress.com',
    ];

    protected const FILE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'css', 'js', 'woff', 'woff2', 'ttf', 'pdf', 'zip'];

    /**
     * Every usable address in the cell, lowercase, in order, without repeats.
     *
     * @return list<string>
     */
    public static function all(?string $cell): array
    {
        $found = [];

        foreach (preg_split('/[\s;,|]+/', (string) $cell, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            $candidate = mb_strtolower(trim($candidate, " \t\n\r\0\x0B<>()[]\"'"));

            if (static::usable($candidate)) {
                $found[$candidate] = $candidate;
            }
        }

        return array_values($found);
    }

    /**
     * The address to email: the first one that isn't a complaints-desk
     * mailbox, or else the first one.
     */
    public static function primary(?string $cell): ?string
    {
        $all = static::all($cell);

        foreach ($all as $email) {
            if (! static::avoided($email)) {
                return $email;
            }
        }

        return $all[0] ?? null;
    }

    public static function avoided(string $email): bool
    {
        $local = explode('+', strstr($email, '@', true) ?: $email)[0];

        return in_array($local, self::AVOID, true);
    }

    protected static function usable(string $email): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || substr_count($email, '@') !== 1) {
            return false;
        }

        $domain = explode('@', $email)[1];
        $tld = substr(strrchr($domain, '.') ?: '', 1);

        // A real top-level domain has letters ("4.2.2" is a version number,
        // "logo@2x.png" a file name).
        return ! in_array($domain, self::PLACEHOLDER_DOMAINS, true)
            && strlen($tld) >= 2
            && preg_match('/[a-z]/', $tld) === 1
            && ! in_array($tld, self::FILE_EXTENSIONS, true);
    }
}
