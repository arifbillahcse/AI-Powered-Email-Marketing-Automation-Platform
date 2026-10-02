<?php

namespace App\Services\Mail;

/**
 * Users choose the SMTP/IMAP hosts our servers connect to. Refuse hosts that
 * resolve to private, loopback, link-local (cloud metadata) or reserved IPs
 * so the connection test can't be used to probe our internal network.
 */
class HostGuard
{
    public function __construct(
        protected bool $allowPrivateHosts = false,
    ) {}

    public function assertAllowed(string $host): void
    {
        if ($this->allowPrivateHosts) {
            return;
        }

        $host = trim($host);

        if ($host === '' || ! preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new MailboxConnectionException("\"{$host}\" isn't a valid server hostname.");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if ($ips === []) {
            throw new MailboxConnectionException("Couldn't find the server \"{$host}\". Check the hostname.");
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new MailboxConnectionException("\"{$host}\" points to a private network address, which isn't allowed.");
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
