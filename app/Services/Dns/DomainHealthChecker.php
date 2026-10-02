<?php

namespace App\Services\Dns;

use App\Enums\DnsCheckStatus;
use App\Enums\MailProvider;
use App\Models\SendingDomain;
use Illuminate\Support\Str;

/**
 * Checks the DNS records that decide whether a domain's email reaches the
 * inbox: MX (receive replies), SPF and DKIM (sender authentication) and
 * DMARC (required by Gmail/Yahoo for bulk senders). Each failing check
 * comes with a copy-paste fix where we can build one.
 */
class DomainHealthChecker
{
    /**
     * @param  list<string>  $dkimSelectors
     */
    public function __construct(
        protected DnsResolver $dns,
        protected array $dkimSelectors = [],
    ) {}

    public function checkAndStore(SendingDomain $domain): SendingDomain
    {
        $checks = $this->check($domain->name, $this->providersFor($domain), $domain->dkim_selector);

        $domain->forceFill([
            'checks' => $checks,
            'status' => DnsCheckStatus::worst(array_map(fn (array $check) => DnsCheckStatus::from($check['status']), $checks)),
            'last_checked_at' => now(),
        ])->save();

        return $domain;
    }

    /**
     * @param  list<MailProvider>  $providers
     * @return array<string, array<string, mixed>>
     */
    public function check(string $domain, array $providers = [], ?string $dkimSelector = null): array
    {
        return [
            'mx' => $this->checkMx($domain),
            'spf' => $this->checkSpf($domain, $providers),
            'dkim' => $this->checkDkim($domain, $providers, $dkimSelector),
            'dmarc' => $this->checkDmarc($domain),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkMx(string $domain): array
    {
        $records = $this->dns->mx($domain);

        if ($records === []) {
            return $this->result(DnsCheckStatus::Fail, 'No MX records. This domain can\'t receive replies, and many inboxes reject mail from domains without MX.', [],
                help: 'Add the MX records from your email provider\'s setup guide.');
        }

        return $this->result(DnsCheckStatus::Pass, 'Mail servers found.', $records);
    }

    /**
     * @param  list<MailProvider>  $providers
     * @return array<string, mixed>
     */
    protected function checkSpf(string $domain, array $providers): array
    {
        $records = array_values(array_filter(
            $this->dns->txt($domain),
            fn (string $txt): bool => Str::startsWith(Str::lower(trim($txt)), 'v=spf1'),
        ));

        $includes = array_values(array_unique(array_filter(array_map(fn (MailProvider $p) => $p->spfInclude(), $providers))));

        if ($records === []) {
            return $this->result(DnsCheckStatus::Fail, 'No SPF record. Receivers can\'t confirm you\'re allowed to send for this domain.', [],
                fix: $this->txtFix('@', $this->buildSpf($includes)));
        }

        if (count($records) > 1) {
            return $this->result(DnsCheckStatus::Fail, 'More than one SPF record. Only one is allowed, so receivers treat SPF as broken. Merge them into one record.', $records,
                fix: $this->txtFix('@', $this->mergeSpf($records[0], $includes)));
        }

        $record = $records[0];
        $lower = Str::lower($record);

        if (str_contains($lower, '+all')) {
            return $this->result(DnsCheckStatus::Fail, 'SPF ends with "+all", which lets anyone send as your domain.', $records,
                fix: $this->txtFix('@', str_ireplace('+all', '~all', $record)));
        }

        $missing = array_values(array_filter($includes, fn (string $include): bool => ! str_contains($lower, Str::lower($include))));

        if ($missing !== []) {
            return $this->result(DnsCheckStatus::Warning, 'SPF doesn\'t authorize your email provider ('.implode(', ', $missing).').', $records,
                fix: $this->txtFix('@', $this->mergeSpf($record, $includes)));
        }

        if (str_contains($lower, '?all') || ! preg_match('/[~-]all\b/', $lower)) {
            return $this->result(DnsCheckStatus::Warning, 'SPF is neutral (no "~all" or "-all"), so it gives receivers little protection.', $records,
                fix: $this->txtFix('@', $this->mergeSpf($record, $includes)));
        }

        return $this->result(DnsCheckStatus::Pass, 'SPF is set up.', $records);
    }

    /**
     * @param  list<MailProvider>  $providers
     * @return array<string, mixed>
     */
    protected function checkDkim(string $domain, array $providers, ?string $selector): array
    {
        $selectors = array_values(array_unique(array_filter([$selector, ...$this->dkimSelectors])));
        $found = [];

        foreach ($selectors as $candidate) {
            foreach ($this->dns->txt("{$candidate}._domainkey.{$domain}") as $txt) {
                if (str_contains($txt, 'p=') && ! preg_match('/p=\s*(;|$)/', $txt)) {
                    $found[] = "{$candidate}._domainkey: ".Str::limit($txt, 80);
                }
            }

            if ($found !== []) {
                break;
            }
        }

        if ($found !== []) {
            return $this->result(DnsCheckStatus::Pass, 'DKIM signing key found.', $found);
        }

        return $this->result(DnsCheckStatus::Fail, $selector
            ? "No DKIM key found for selector \"{$selector}\"."
            : 'No DKIM key found on common selectors. If you use a custom selector, set it on this domain.', [],
            help: $this->dkimHelp($providers));
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkDmarc(string $domain): array
    {
        $records = array_values(array_filter(
            $this->dns->txt("_dmarc.{$domain}"),
            fn (string $txt): bool => Str::startsWith(Str::lower(trim($txt)), 'v=dmarc1'),
        ));

        $suggested = "v=DMARC1; p=none; rua=mailto:dmarc@{$domain}";

        if ($records === []) {
            return $this->result(DnsCheckStatus::Fail, 'No DMARC record. Gmail and Yahoo require one for senders.', [],
                fix: $this->txtFix('_dmarc', $suggested));
        }

        if (count($records) > 1) {
            return $this->result(DnsCheckStatus::Fail, 'More than one DMARC record. Keep only one.', $records,
                fix: $this->txtFix('_dmarc', $records[0]));
        }

        return $this->result(DnsCheckStatus::Pass, 'DMARC is set up.', $records);
    }

    /**
     * @param  list<string>  $includes
     */
    protected function buildSpf(array $includes): string
    {
        $mechanisms = $includes === [] ? ['a', 'mx'] : $includes;

        return 'v=spf1 '.implode(' ', $mechanisms).' ~all';
    }

    /**
     * Add missing includes to an existing record and make it end in ~all.
     *
     * @param  list<string>  $includes
     */
    protected function mergeSpf(string $record, array $includes): string
    {
        $terms = preg_split('/\s+/', trim($record)) ?: [];
        $terms = array_values(array_filter($terms, fn (string $term): bool => ! preg_match('/^[+?~-]?all$/i', $term) && Str::lower($term) !== 'v=spf1'));

        foreach ($includes as $include) {
            if (! in_array(Str::lower($include), array_map(Str::lower(...), $terms), true)) {
                $terms[] = $include;
            }
        }

        return trim('v=spf1 '.implode(' ', $terms).' ~all');
    }

    /**
     * @param  list<MailProvider>  $providers
     */
    protected function dkimHelp(array $providers): string
    {
        return match ($providers[0] ?? null) {
            MailProvider::Google => 'Google Admin console → Apps → Google Workspace → Gmail → Authenticate email → Generate new record, add the TXT record it shows, then click "Start authentication".',
            MailProvider::Microsoft => 'Microsoft Defender portal → Email authentication settings → DKIM → select the domain → create the two CNAME records it shows and enable signing.',
            MailProvider::Zoho => 'Zoho Mail admin → Domains → select the domain → Email configuration → DKIM → add a selector and publish the TXT record it shows.',
            default => 'Enable DKIM signing in your email provider\'s admin panel and publish the TXT record it gives you.',
        };
    }

    /**
     * @return list<MailProvider>
     */
    protected function providersFor(SendingDomain $domain): array
    {
        return $domain->emailAccounts()
            ->pluck('provider')
            ->map(fn ($provider) => $provider instanceof MailProvider ? $provider : MailProvider::from($provider))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{type: string, host: string, value: string}
     */
    protected function txtFix(string $host, string $value): array
    {
        return ['type' => 'TXT', 'host' => $host, 'value' => $value];
    }

    /**
     * @param  list<string>  $records
     * @param  array{type: string, host: string, value: string}|null  $fix
     * @return array<string, mixed>
     */
    protected function result(DnsCheckStatus $status, string $summary, array $records, ?array $fix = null, ?string $help = null): array
    {
        return [
            'status' => $status->value,
            'summary' => $summary,
            'records' => $records,
            'fix' => $fix,
            'help' => $help,
        ];
    }
}
