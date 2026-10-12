<?php

namespace App\Services\Leads;

use App\Enums\EmailVerificationStatus;
use App\Services\Dns\DnsResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks whether an address is worth emailing:
 *
 * - invalid: bad syntax, a disposable address, or a domain that can't
 *   receive email (no MX, no A record);
 * - risky: role addresses (info@, sales@), catch-all domains;
 * - valid: the domain accepts email (and, with a verification service
 *   configured, the mailbox exists);
 * - unknown: the service couldn't tell.
 */
class EmailVerifier
{
    /** @var array<string, bool> Domain => accepts email (per instance, i.e. per job) */
    protected array $domains = [];

    public function __construct(
        protected DnsResolver $dns,
    ) {}

    /**
     * @return array{status: EmailVerificationStatus, reason: string}
     */
    public function verify(string $email): array
    {
        $email = Str::lower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || substr_count($email, '@') !== 1) {
            return $this->result(EmailVerificationStatus::Invalid, 'Not a valid email address.');
        }

        [$local, $domain] = explode('@', $email);

        if (in_array($domain, (array) config('outreach.verification.disposable_domains', []), true)) {
            return $this->result(EmailVerificationStatus::Invalid, 'Disposable (throwaway) address.');
        }

        if (! $this->acceptsEmail($domain)) {
            return $this->result(EmailVerificationStatus::Invalid, "{$domain} has no mail server.");
        }

        if (filled(config('outreach.verification.provider')) && filled(config('outreach.verification.api_key'))) {
            return $this->viaService($email);
        }

        if (in_array(Str::before($local, '+'), (array) config('outreach.verification.role_accounts', []), true)) {
            return $this->result(EmailVerificationStatus::Risky, 'Role address (shared inbox): lower reply rates and more complaints.');
        }

        return $this->result(EmailVerificationStatus::Valid, 'The domain accepts email (the mailbox itself wasn\'t checked).');
    }

    protected function acceptsEmail(string $domain): bool
    {
        return $this->domains[$domain] ??= (function () use ($domain): bool {
            try {
                // No MX means mail goes to the domain's own A record (RFC 5321).
                return $this->dns->mx($domain) !== [] || $this->dns->a($domain) !== [];
            } catch (Throwable) {
                return true; // A DNS hiccup is not proof the address is bad.
            }
        })();
    }

    /**
     * @return array{status: EmailVerificationStatus, reason: string}
     */
    protected function viaService(string $email): array
    {
        $provider = (string) config('outreach.verification.provider');
        $key = (string) config('outreach.verification.api_key');

        try {
            $response = match ($provider) {
                'millionverifier' => Http::timeout(20)->get('https://api.millionverifier.com/api/v3/', ['api' => $key, 'email' => $email, 'timeout' => 10]),
                'zerobounce' => Http::timeout(20)->get('https://api.zerobounce.net/v2/validate', ['api_key' => $key, 'email' => $email]),
                default => null,
            };
        } catch (Throwable) {
            $response = null;
        }

        if (! $response?->successful()) {
            return $this->result(EmailVerificationStatus::Unknown, 'The verification service didn\'t answer. Try again later.');
        }

        $result = Str::lower((string) ($provider === 'millionverifier' ? $response->json('result') : $response->json('status')));

        return match ($result) {
            'ok', 'valid' => $this->result(EmailVerificationStatus::Valid, 'The mailbox exists.'),
            'catch_all', 'catch-all' => $this->result(EmailVerificationStatus::Risky, 'Catch-all domain: it accepts any address, so this one may not exist.'),
            'invalid' => $this->result(EmailVerificationStatus::Invalid, 'The mailbox doesn\'t exist.'),
            'disposable' => $this->result(EmailVerificationStatus::Invalid, 'Disposable (throwaway) address.'),
            'spamtrap', 'abuse', 'do_not_mail' => $this->result(EmailVerificationStatus::Invalid, 'Known spam trap or complainer: never email it.'),
            default => $this->result(EmailVerificationStatus::Unknown, 'The verification service couldn\'t tell.'),
        };
    }

    /**
     * @return array{status: EmailVerificationStatus, reason: string}
     */
    protected function result(EmailVerificationStatus $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason];
    }
}
