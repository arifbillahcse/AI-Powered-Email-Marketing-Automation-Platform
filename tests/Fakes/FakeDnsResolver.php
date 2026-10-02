<?php

namespace Tests\Fakes;

use App\Services\Dns\DnsResolver;

class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    public array $txt = [];

    /** @var array<string, list<string>> */
    public array $mx = [];

    /** @var array<string, string> */
    public array $cname = [];

    public function txt(string $host): array
    {
        return $this->txt[$host] ?? [];
    }

    public function mx(string $host): array
    {
        return $this->mx[$host] ?? [];
    }

    public function cname(string $host): ?string
    {
        return $this->cname[$host] ?? null;
    }

    /**
     * A domain with every check passing.
     */
    public function healthy(string $domain, string $dkimSelector = 'google'): static
    {
        $this->mx[$domain] = ['aspmx.l.google.com'];
        $this->txt[$domain] = ['v=spf1 include:_spf.google.com ~all'];
        $this->txt["{$dkimSelector}._domainkey.{$domain}"] = ['v=DKIM1; k=rsa; p=MIIBIjANBgkqh'];
        $this->txt["_dmarc.{$domain}"] = ['v=DMARC1; p=none; rua=mailto:dmarc@'.$domain];

        return $this;
    }
}
