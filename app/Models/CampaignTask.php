<?php

namespace App\Models;

use App\Enums\CampaignTaskStatus;
use App\Enums\StepType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual sequence step (LinkedIn, call, other) waiting for someone on
 * the team. The lead's sequence continues once it's done or skipped.
 */
class CampaignTask extends Model
{
    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'campaign_step_id' => null,
        'instructions' => null,
        'status' => 'open',
        'completed_at' => null,
        'completed_by' => null,
    ];

    protected function casts(): array
    {
        return [
            'type' => StepType::class,
            'status' => CampaignTaskStatus::class,
            'step_position' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<CampaignLead, $this>
     */
    public function campaignLead(): BelongsTo
    {
        return $this->belongsTo(CampaignLead::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === CampaignTaskStatus::Open;
    }
}
