<?php

namespace App\Models;

use App\Enums\DnsCheckStatus;
use Database\Factories\SendingDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A domain the workspace sends from, with its latest DNS health results.
 *
 * `checks` holds one entry per check (mx, spf, dkim, dmarc):
 * ['status' => 'pass|warning|fail', 'summary' => string, 'records' => list<string>,
 *  'fix' => ?['type' => string, 'host' => string, 'value' => string], 'help' => ?string]
 */
class SendingDomain extends Model
{
    /** @use HasFactory<SendingDomainFactory> */
    use HasFactory;

    public const CHECKS = ['mx', 'spf', 'dkim', 'dmarc'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'dkim_selector',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'dkim_selector' => null,
        'checks' => null,
        'last_checked_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => DnsCheckStatus::class,
            'checks' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SendingDomain $domain): void {
            $domain->name = Str::lower(trim($domain->name, " \t\n\r\0\x0B."));
        });
    }

    public static function findOrCreateFor(int $workspaceId, string $name): self
    {
        $domain = static::query()->firstOrNew([
            'workspace_id' => $workspaceId,
            'name' => Str::lower($name),
        ]);

        if (! $domain->exists) {
            $domain->workspace_id = $workspaceId;
            $domain->save();
        }

        return $domain;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<EmailAccount, $this>
     */
    public function emailAccounts(): HasMany
    {
        return $this->hasMany(EmailAccount::class);
    }

    public function checkStatus(string $check): DnsCheckStatus
    {
        $status = $this->checks[$check]['status'] ?? null;

        return $status ? DnsCheckStatus::from($status) : DnsCheckStatus::Pending;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function check(string $check): ?array
    {
        return $this->checks[$check] ?? null;
    }
}
