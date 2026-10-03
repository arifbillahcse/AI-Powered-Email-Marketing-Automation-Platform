<?php

namespace App\Services\Sending;

use App\Models\EmailAccount;

/**
 * Builds open/click/unsubscribe URLs for an email. They use the mailbox's
 * verified custom tracking domain when it has one, otherwise APP_URL.
 * Click links are signed so the redirect can't be abused as an open redirect.
 */
class TrackingUrls
{
    public function base(?EmailAccount $mailbox): string
    {
        if ($mailbox?->tracking_domain && $mailbox->tracking_domain_verified_at) {
            return config('outreach.sending.tracking_scheme').'://'.$mailbox->tracking_domain;
        }

        return rtrim((string) config('app.url'), '/');
    }

    public function open(?EmailAccount $mailbox, string $token): string
    {
        return $this->base($mailbox)."/t/o/{$token}.gif";
    }

    public function click(?EmailAccount $mailbox, string $token, string $url): string
    {
        return $this->base($mailbox)."/t/c/{$token}?".http_build_query([
            'u' => $url,
            's' => $this->signature($token, $url),
        ]);
    }

    public function unsubscribe(?EmailAccount $mailbox, string $token): string
    {
        return $this->base($mailbox)."/u/{$token}";
    }

    public function signature(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', "{$token}|{$url}", (string) config('app.key')), 0, 32);
    }

    public function verify(string $token, string $url, string $signature): bool
    {
        return hash_equals($this->signature($token, $url), $signature);
    }
}
