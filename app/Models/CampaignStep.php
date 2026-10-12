<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignStep extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'position',
        'delay_days',
        'subject',
        'body',
        'ab_winner',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'delay_days' => 0,
        'subject' => null,
        'ab_winner' => null,
        'ab_decided_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'delay_days' => 'integer',
            'ab_decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * A/B versions B, C, ... (the step's own subject and body are version A).
     *
     * @return HasMany<CampaignStepVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(CampaignStepVariant::class)->orderBy('label');
    }

    public function isReplyInThread(): bool
    {
        return blank($this->subject) && $this->position > 1;
    }
}
