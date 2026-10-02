<?php

namespace App\Services\Dns;

interface DnsResolver
{
    /**
     * TXT record values (multi-string records joined).
     *
     * @return list<string>
     */
    public function txt(string $host): array;

    /**
     * MX target hosts, lowest priority first.
     *
     * @return list<string>
     */
    public function mx(string $host): array;

    public function cname(string $host): ?string;
}
