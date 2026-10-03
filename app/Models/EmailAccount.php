<?php

namespace App\Models;

use App\Enums\EmailAccountStatus;
use App\Enums\MailEncryption;
use App\Enums\MailProvider;
use Database\Factories\EmailAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A connected mailbox that campaigns send from and replies are read from.
 */
class EmailAccount extends Model
{
    /** @use HasFactory<EmailAccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'from_name',
        'provider',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',
        'daily_limit',
        'min_delay_seconds',
        'max_delay_seconds',
        'send_window_start',
        'send_window_end',
        'send_days',
        'signature',
        'tracking_domain',
        'warmup_enabled',
        'warmup_daily_target',
        'warmup_reply_rate',
    ];

    /**
     * Credentials never leave the server (also keeps them out of form fills).
     *
     * @var list<string>
     */
    protected $hidden = [
        'smtp_password',
        'imap_password',
    ];

    /**
     * Mirrors column defaults (strict mode) and sets sensible starting limits.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'provider' => 'custom',
        'status' => 'active',
        'sending_domain_id' => null,
        'smtp_username' => null,
        'imap_username' => null,
        'imap_password' => null,
        'signature' => null,
        'tracking_domain' => null,
        'tracking_domain_verified_at' => null,
        'last_error' => null,
        'last_tested_at' => null,
        'warmup_enabled' => false,
        'warmup_daily_target' => 20,
        'warmup_reply_rate' => 30,
        'warmup_started_at' => null,
        'next_send_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'provider' => MailProvider::class,
            'status' => EmailAccountStatus::class,
            'smtp_encryption' => MailEncryption::class,
            'imap_encryption' => MailEncryption::class,
            'smtp_port' => 'integer',
            'imap_port' => 'integer',
            'smtp_password' => 'encrypted',
            'imap_password' => 'encrypted',
            'daily_limit' => 'integer',
            'min_delay_seconds' => 'integer',
            'max_delay_seconds' => 'integer',
            'send_days' => 'array',
            'tracking_domain_verified_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'warmup_enabled' => 'boolean',
            'warmup_started_at' => 'datetime',
            'next_send_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (EmailAccount $account): void {
            $account->email = Str::lower(trim($account->email));

            // Form checkboxes submit strings; store ISO weekday numbers.
            $account->send_days = collect($account->send_days ?? [])->map(fn ($day): int => (int) $day)->unique()->sort()->values()->all();

            if ($account->tracking_domain !== null) {
                $account->tracking_domain = Str::lower(trim($account->tracking_domain)) ?: null;
            }

            if ($account->isDirty('tracking_domain')) {
                $account->tracking_domain_verified_at = null;
            }
        });

        // Link to the sending domain once workspace_id is certainly set.
        static::saved(function (EmailAccount $account): void {
            if ($account->sending_domain_id && ! $account->wasChanged('email')) {
                return;
            }

            $account->sending_domain_id = SendingDomain::findOrCreateFor($account->workspace_id, $account->domain())->getKey();
            $account->saveQuietly();
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<SendingDomain, $this>
     */
    public function sendingDomain(): BelongsTo
    {
        return $this->belongsTo(SendingDomain::class);
    }

    public function domain(): string
    {
        return Str::lower(Str::after($this->email, '@'));
    }

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function emailMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function smtpUsername(): string
    {
        return $this->smtp_username ?: $this->email;
    }

    public function imapUsername(): string
    {
        return $this->imap_username ?: $this->smtpUsername();
    }

    public function imapPassword(): string
    {
        return $this->imap_password ?: $this->smtp_password;
    }

    public function isActive(): bool
    {
        return $this->status === EmailAccountStatus::Active;
    }

    public function markTested(?string $error): void
    {
        $this->last_tested_at = now();
        $this->last_error = $error;

        if ($error !== null) {
            $this->status = EmailAccountStatus::Error;
        } elseif ($this->status === EmailAccountStatus::Error) {
            // A passing test clears an error, but never un-pauses on its own.
            $this->status = EmailAccountStatus::Active;
        }

        $this->save();
    }
}
