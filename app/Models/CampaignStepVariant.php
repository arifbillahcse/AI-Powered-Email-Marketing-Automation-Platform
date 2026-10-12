<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another version (B, C, ...) of a sequence email for A/B/n testing. A
 * blank subject keeps the step's subject.
 */
class CampaignStepVariant extends Model
{
    public const LABELS = ['B', 'C', 'D', 'E'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'subject',
        'body',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subject' => null,
    ];

    protected static function booted(): void
    {
        // Next free letter when none is given (the editor doesn't ask).
        static::creating(function (CampaignStepVariant $variant): void {
            if (blank($variant->label)) {
                $used = static::query()->where('campaign_step_id', $variant->campaign_step_id)->pluck('label')->all();
                $variant->label = collect(self::LABELS)->first(fn (string $label): bool => ! in_array($label, $used, true)) ?? 'Z';
            }
        });
    }

    /**
     * @return BelongsTo<CampaignStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class, 'campaign_step_id');
    }
}
