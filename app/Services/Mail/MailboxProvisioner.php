<?php

namespace App\Services\Mail;

use App\Enums\EmailAccountStatus;
use App\Enums\MailEncryption;
use App\Enums\MailProvider;
use App\Models\EmailAccount;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\Csv\Reader;
use Throwable;

/**
 * Creates mailboxes outside the one-at-a-time form: from an OAuth sign-in
 * (Google / Microsoft 365) or a CSV of many SMTP/IMAP accounts.
 */
class MailboxProvisioner
{
    public const MAX_IMPORT_ROWS = 500;

    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    /**
     * Sending defaults for new mailboxes (config/outreach.php).
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'daily_limit' => (int) config('outreach.mailboxes.default_daily_limit'),
            'min_delay_seconds' => (int) config('outreach.mailboxes.default_min_delay_seconds'),
            'max_delay_seconds' => (int) config('outreach.mailboxes.default_max_delay_seconds'),
            'send_window_start' => config('outreach.mailboxes.default_send_window')[0],
            'send_window_end' => config('outreach.mailboxes.default_send_window')[1],
            'send_days' => config('outreach.mailboxes.default_send_days'),
        ];
    }

    /**
     * Create (or reconnect) an OAuth mailbox.
     *
     * @param  array{email: string, name: ?string, access_token: string, refresh_token: ?string, expires_at: CarbonInterface}  $tokens
     *
     * @throws InvalidArgumentException When the plan's mailbox limit is reached
     */
    public function connectOAuth(Workspace $workspace, MailProvider $provider, array $tokens): EmailAccount
    {
        $account = EmailAccount::query()->where('workspace_id', $workspace->getKey())->where('email', $tokens['email'])->first();

        if (! $account && ($reason = $this->entitlements->denies($workspace, 'mailboxes'))) {
            throw new InvalidArgumentException($reason);
        }

        $account ??= new EmailAccount([
            'email' => $tokens['email'],
            'from_name' => $tokens['name'] ?: Str::headline(Str::before($tokens['email'], '@')),
            ...$this->defaults(),
        ]);

        $account->fill([...$provider->preset(), 'provider' => $provider, 'smtp_username' => null, 'imap_username' => null]);
        $account->workspace_id = $workspace->getKey();
        $account->forceFill([
            'auth_type' => 'oauth',
            'smtp_password' => null,
            'imap_password' => null,
            'oauth_access_token' => $tokens['access_token'],
            // Google only returns a refresh token on first consent; keep the old one.
            'oauth_refresh_token' => $tokens['refresh_token'] ?? $account->oauth_refresh_token,
            'oauth_expires_at' => $tokens['expires_at'],
            'status' => EmailAccountStatus::Active,
            'last_error' => null,
        ])->save();

        return $account;
    }

    /**
     * Import mailboxes from CSV. Columns (header row, any order):
     * email, from_name, smtp_host, smtp_port, smtp_encryption, smtp_username,
     * smtp_password, imap_host, imap_port, imap_encryption, imap_username,
     * imap_password, daily_limit. Rows for an existing address update it.
     * Server settings may be left out for Gmail, Microsoft 365 and Zoho
     * addresses when `provider` is given (google, microsoft, zoho).
     *
     * @return array{created: list<EmailAccount>, updated: list<EmailAccount>, errors: list<string>}
     */
    public function import(Workspace $workspace, string $csv): array
    {
        try {
            $reader = Reader::createFromString($csv);
            $reader->setHeaderOffset(0);
            $rows = iterator_to_array($reader->getRecords(), false);
        } catch (Throwable) {
            return ['created' => [], 'updated' => [], 'errors' => ['The file isn\'t a valid CSV with a header row.']];
        }

        if (count($rows) > self::MAX_IMPORT_ROWS) {
            return ['created' => [], 'updated' => [], 'errors' => ['Import at most '.self::MAX_IMPORT_ROWS.' mailboxes at a time.']];
        }

        $created = [];
        $updated = [];
        $errors = [];
        $remaining = $this->entitlements->remaining($workspace, 'mailboxes');

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $row = array_change_key_case(array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row), CASE_LOWER);
            $provider = MailProvider::tryFrom(Str::lower((string) ($row['provider'] ?? ''))) ?? MailProvider::guessFromSmtpHost($row['smtp_host'] ?? null);
            $data = array_filter([...$provider->preset(), ...array_filter($row, fn ($value): bool => $value !== '' && $value !== null)], fn ($value): bool => $value !== null);
            $data['email'] = Str::lower((string) ($data['email'] ?? ''));

            $validator = Validator::make($data, [
                'email' => ['required', 'email', 'max:255'],
                'from_name' => ['nullable', 'string', 'max:100'],
                'smtp_host' => ['required', 'string', 'max:255'],
                'smtp_port' => ['required', 'integer', 'between:1,65535'],
                'smtp_encryption' => ['nullable', 'in:ssl,tls,none'],
                'smtp_password' => ['required', 'string', 'max:500'],
                'imap_host' => ['required', 'string', 'max:255'],
                'imap_port' => ['required', 'integer', 'between:1,65535'],
                'imap_encryption' => ['nullable', 'in:ssl,tls,none'],
                'daily_limit' => ['nullable', 'integer', 'between:1,'.config('outreach.mailboxes.max_daily_limit')],
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$line}".($data['email'] ? " ({$data['email']})" : '').': '.$validator->errors()->first();

                continue;
            }

            $account = EmailAccount::query()->where('workspace_id', $workspace->getKey())->where('email', $data['email'])->first();

            if (! $account && $remaining !== null && $remaining <= 0) {
                $errors[] = "Row {$line} ({$data['email']}): your plan's mailbox limit is reached.";

                continue;
            }

            $isNew = $account === null;
            $account ??= new EmailAccount($this->defaults());
            $account->fill([
                'email' => $data['email'],
                'from_name' => $data['from_name'] ?? $account->from_name ?? Str::headline(Str::before($data['email'], '@')),
                'provider' => $provider,
                'smtp_host' => Str::lower($data['smtp_host']),
                'smtp_port' => (int) $data['smtp_port'],
                'smtp_encryption' => MailEncryption::tryFrom((string) ($data['smtp_encryption'] ?? '')) ?? ((int) $data['smtp_port'] === 465 ? MailEncryption::Ssl : MailEncryption::Tls),
                'smtp_username' => $data['smtp_username'] ?? null,
                'smtp_password' => $data['smtp_password'],
                'imap_host' => Str::lower($data['imap_host']),
                'imap_port' => (int) $data['imap_port'],
                'imap_encryption' => MailEncryption::tryFrom((string) ($data['imap_encryption'] ?? '')) ?? MailEncryption::Ssl,
                'imap_username' => $data['imap_username'] ?? null,
                'imap_password' => $data['imap_password'] ?? null,
                ...(isset($data['daily_limit']) ? ['daily_limit' => (int) $data['daily_limit']] : []),
            ]);
            $account->workspace_id = $workspace->getKey();
            $account->forceFill(['auth_type' => 'password'])->save();

            if ($isNew) {
                $created[] = $account;
                $remaining = $remaining === null ? null : $remaining - 1;
            } else {
                $updated[] = $account;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }
}
