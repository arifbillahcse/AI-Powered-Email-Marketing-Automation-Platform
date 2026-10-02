<?php

namespace App\Services\Dns;

class NativeDnsResolver implements DnsResolver
{
    public function txt(string $host): array
    {
        return array_values(array_map(
            fn (array $record): string => isset($record['entries']) ? implode('', $record['entries']) : (string) ($record['txt'] ?? ''),
            $this->lookup($host, DNS_TXT),
        ));
    }

    public function mx(string $host): array
    {
        $records = $this->lookup($host, DNS_MX);
        usort($records, fn (array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

        return array_values(array_map(fn (array $record): string => strtolower((string) $record['target']), $records));
    }

    public function cname(string $host): ?string
    {
        $record = $this->lookup($host, DNS_CNAME)[0] ?? null;

        return $record ? strtolower(rtrim((string) $record['target'], '.')) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function lookup(string $host, int $type): array
    {
        return array_values(@dns_get_record($host, $type) ?: []);
    }
}
