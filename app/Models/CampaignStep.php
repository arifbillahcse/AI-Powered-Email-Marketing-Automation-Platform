<?php

namespace App\Models;

use App\Enums\StepType;
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
        'type',
        'delay_days',
        'subject',
        'body',
        'ab_winner',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'email',
        'delay_days' => 0,
        'subject' => null,
        'body' => null,
        'ab_winner' => null,
        'ab_decided_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'type' => StepType::class,
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
        return $this->isEmail() && blank($this->subject) && $this->position > 1;
    }

    public function isEmail(): bool
    {
        return $this->type === StepType::Email;
    }
}
