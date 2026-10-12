<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One warmup email between two mailboxes of the warmup pool. Never shown
 * in the Unibox and never counted as campaign mail.
 */
class WarmupMessage extends Model
{
    use MassPrunable;

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'parent_id' => null,
        'depth' => 0,
        'message_id' => null,
        'status' => 'sent',
        'error' => null,
        'sent_at' => null,
        'delivered_at' => null,
        'landed_in_spam' => false,
        'replied_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'landed_in_spam' => 'boolean',
            'replied_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        return Str::random(32);
    }

    /**
     * @return BelongsTo<EmailAccount, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'sender_id');
    }

    /**
     * @return BelongsTo<EmailAccount, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'recipient_id');
    }

    /**
     * @return BelongsTo<WarmupMessage, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Health reports look back a week; keep 90 days.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(90));
    }
}
