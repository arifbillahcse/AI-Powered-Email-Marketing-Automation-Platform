<?php

namespace App\Models;

use App\Enums\AiContentType;
use App\Enums\AiGenerationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI-written piece of content for a lead in a campaign step, waiting for
 * (or past) human review. Only approved content is ever sent.
 */
class AiGeneration extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'output',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'ai_prompt_template_id' => null,
        'output' => null,
        'edited' => false,
        'error' => null,
        'provider' => null,
        'model' => null,
        'generated_at' => null,
        'approved_at' => null,
        'approved_by' => null,
    ];

    protected function casts(): array
    {
        return [
            'type' => AiContentType::class,
            'status' => AiGenerationStatus::class,
            'edited' => 'boolean',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
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
     * @return BelongsTo<CampaignStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class, 'campaign_step_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<AiPromptTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AiPromptTemplate::class, 'ai_prompt_template_id');
    }
}
