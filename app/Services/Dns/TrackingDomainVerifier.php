<?php

namespace App\Services\Dns;

use App\Models\EmailAccount;
use Illuminate\Support\Str;

class TrackingDomainVerifier
{
    public function __construct(
        protected DnsResolver $dns,
        protected string $cnameTarget,
    ) {}

    public function target(): string
    {
        return $this->cnameTarget;
    }

    /**
     * Returns null when verified, or a message explaining what's wrong.
     */
    public function verify(EmailAccount $account): ?string
    {
        if (blank($account->tracking_domain)) {
            return 'No tracking domain set.';
        }

        $cname = $this->dns->cname($account->tracking_domain);
        $verified = $cname !== null && Str::lower(rtrim($cname, '.')) === Str::lower($this->cnameTarget);

        $account->forceFill(['tracking_domain_verified_at' => $verified ? now() : null])->save();

        return match (true) {
            $verified => null,
            $cname === null => "No CNAME record found for {$account->tracking_domain}. Add: CNAME {$account->tracking_domain} → {$this->cnameTarget}",
            default => "{$account->tracking_domain} points to {$cname}, but it must point to {$this->cnameTarget}.",
        };
    }
}
