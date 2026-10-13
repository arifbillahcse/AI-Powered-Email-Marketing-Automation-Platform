<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inbox placement test: one email from a mailbox to every seed mailbox,
 * then a check of where each landed (inbox, spam or missing).
 */
class PlacementTest extends Model
{
    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'created_by' => null,
        'status' => 'sending',
        'error' => null,
        'sent_at' => null,
        'finished_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<EmailAccount, $this>
     */
    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    /**
     * @return HasMany<PlacementResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(PlacementResult::class);
    }
}
