<?php

namespace App\Services\Agency;

use App\Models\Workspace;
use App\Services\Dns\DnsResolver;
use Illuminate\Support\Str;

/**
 * Checks that a workspace's custom domain (app.agency.com) points at this
 * platform: a CNAME to the target host, or the same A records.
 */
class CustomDomainVerifier
{
    public function __construct(
        protected DnsResolver $dns,
    ) {}

    public function target(): string
    {
        return Str::lower((string) config('outreach.agency.domain_target'));
    }

    /**
     * Null when verified, or a message explaining what's wrong.
     */
    public function verify(Workspace $workspace): ?string
    {
        $domain = $workspace->custom_domain;

        if (blank($domain)) {
            return 'No custom domain set.';
        }

        $cname = $this->dns->cname($domain);
        $verified = $cname !== null && Str::lower(rtrim($cname, '.')) === $this->target();

        if (! $verified && $cname === null) {
            $ours = $this->dns->a($this->target());
            $theirs = $this->dns->a($domain);
            $verified = $ours !== [] && $theirs !== [] && array_diff($theirs, $ours) === [];
        }

        $workspace->forceFill(['custom_domain_verified_at' => $verified ? now() : null])->save();

        return match (true) {
            $verified => null,
            $cname !== null => "{$domain} points to {$cname}, but it must point to {$this->target()}.",
            default => "{$domain} doesn't point to us yet. Add: CNAME {$domain} → {$this->target()} (DNS changes can take a while).",
        };
    }
}
