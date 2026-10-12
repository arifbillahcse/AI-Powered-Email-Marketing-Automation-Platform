<?php

namespace App\Services\Compliance;

use App\Filament\App\Resources\SendingDomains\SendingDomainResource;
use App\Models\EmailAccount;
use App\Models\SendingDomain;
use App\Models\Workspace;
use App\Services\Dns\DnsResolver;
use Throwable;

/**
 * Checks a sending domain against domain blocklists (DBL, SURBL, URIBL) and
 * the public IPs of its mailboxes' SMTP servers against IP blocklists
 * (Spamhaus ZEN, SpamCop, ...). A listing is an A record in 127.0.0.0/8;
 * the codes lists use to refuse a query (e.g. Spamhaus's 127.255.255.x for
 * public resolvers) count as "couldn't check", never as listed.
 */
class BlacklistChecker
{
    public const PENDING = 'pending';

    public const CLEAN = 'clean';

    public const LISTED = 'listed';

    public function __construct(
        protected DnsResolver $dns,
        protected WorkspaceAlerts $alerts,
    ) {}

    /**
     * Check, store the result, and alert owners/admins about new listings.
     *
     * @return array{listings: list<array{list: string, target: string}>, checked: list<string>, ips: list<string>, unknown: list<string>}
     */
    public function checkAndStore(SendingDomain $domain): array
    {
        $before = collect($domain->blacklist['listings'] ?? [])->map(fn (array $listing): string => "{$listing['list']}|{$listing['target']}");
        $result = $this->check($domain);

        $domain->forceFill([
            'blacklist' => $result,
            'blacklist_status' => $result['listings'] === [] ? self::CLEAN : self::LISTED,
            'blacklist_checked_at' => now(),
        ])->save();

        $new = collect($result['listings'])->reject(fn (array $listing): bool => $before->contains("{$listing['list']}|{$listing['target']}"));

        if ($new->isNotEmpty()) {
            $this->alerts->send(
                $domain->workspace_id,
                "{$domain->name} is on a blacklist",
                'Listed on '.$new->map(fn (array $listing): string => "{$listing['list']} ({$listing['target']})")->implode(', ')
                    .'. Emails from it may be rejected or sent to spam. Pause campaigns from this domain, find the cause (bad list, compromised account), then request delisting on the blacklist\'s website.',
                $this->url($domain),
                'Open domain',
            );
        }

        return $result;
    }

    /**
     * @return array{listings: list<array{list: string, target: string}>, checked: list<string>, ips: list<string>, unknown: list<string>}
     */
    public function check(SendingDomain $domain): array
    {
        $listings = [];
        $checked = [];
        $unknown = [];

        foreach ((array) config('outreach.blacklists.domains', []) as $zone => $name) {
            $checked[] = $name;

            match ($this->lookup("{$domain->name}.{$zone}")) {
                self::LISTED => $listings[] = ['list' => $name, 'target' => $domain->name],
                self::PENDING => $unknown[] = $name,
                default => null,
            };
        }

        $ips = $this->sendingIps($domain);

        foreach ((array) config('outreach.blacklists.ips', []) as $zone => $name) {
            if ($ips === []) {
                break;
            }

            $checked[] = $name;

            foreach ($ips as $ip) {
                match ($this->lookup(implode('.', array_reverse(explode('.', $ip))).".{$zone}")) {
                    self::LISTED => $listings[] = ['list' => $name, 'target' => $ip],
                    self::PENDING => $unknown[] = $name,
                    default => null,
                };
            }
        }

        return [
            'listings' => $listings,
            'checked' => array_values(array_unique($checked)),
            'ips' => $ips,
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * Public IPv4 addresses of the SMTP servers this domain's mailboxes send through.
     *
     * @return list<string>
     */
    public function sendingIps(SendingDomain $domain): array
    {
        $ips = [];

        $hosts = EmailAccount::query()
            ->where('sending_domain_id', $domain->getKey())
            ->distinct()
            ->pluck('smtp_host');

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));
            $addresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : $this->dns->a($host);

            foreach ($addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $ips[] = $address;
                }
            }
        }

        return array_slice(array_values(array_unique($ips)), 0, (int) config('outreach.blacklists.max_ips', 5));
    }

    /**
     * @return string listed | clean | pending (the list refused to answer)
     */
    protected function lookup(string $query): string
    {
        try {
            $answers = $this->dns->a($query);
        } catch (Throwable) {
            return self::PENDING;
        }

        if ($answers === []) {
            return self::CLEAN;
        }

        foreach ($answers as $answer) {
            if (str_starts_with($answer, '127.') && ! str_starts_with($answer, '127.255.255.') && $answer !== '127.0.0.1') {
                return self::LISTED;
            }
        }

        return self::PENDING;
    }

    protected function url(SendingDomain $domain): ?string
    {
        try {
            $workspace = Workspace::query()->find($domain->workspace_id);

            return $workspace ? SendingDomainResource::getUrl('view', ['record' => $domain], panel: 'app', tenant: $workspace) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
