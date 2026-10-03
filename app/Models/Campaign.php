<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'timezone',
        'send_days',
        'send_window_start',
        'send_window_end',
        'daily_limit',
        'stop_on_reply',
        'track_opens',
        'track_clicks',
        'plain_text',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_template' => false,
        'timezone' => 'UTC',
        'stop_on_reply' => true,
        'track_opens' => false,
        'track_clicks' => false,
        'plain_text' => false,
        'created_by' => null,
        'launched_at' => null,
        'paused_at' => null,
        'completed_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'is_template' => 'boolean',
            'send_days' => 'array',
            'daily_limit' => 'integer',
            'stop_on_reply' => 'boolean',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'plain_text' => 'boolean',
            'launched_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Campaign $campaign): void {
            $campaign->send_days = collect($campaign->send_days ?? [])->map(fn ($day): int => (int) $day)->unique()->sort()->values()->all();
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
     * @return HasMany<CampaignStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<EmailAccount, $this>
     */
    public function emailAccounts(): BelongsToMany
    {
        return $this->belongsToMany(EmailAccount::class, 'campaign_email_account');
    }

    /**
     * @return BelongsToMany<LeadList, $this>
     */
    public function leadLists(): BelongsToMany
    {
        return $this->belongsToMany(LeadList::class, 'campaign_lead_list');
    }

    /**
     * @return BelongsToMany<Segment, $this>
     */
    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(Segment::class, 'campaign_segment');
    }

    /**
     * @return HasMany<CampaignLead, $this>
     */
    public function campaignLeads(): HasMany
    {
        return $this->hasMany(CampaignLead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status !== CampaignStatus::Completed;
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    public function scopeTemplates(Builder $query): void
    {
        $query->where('is_template', true);
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    public function scopeCampaigns(Builder $query): void
    {
        $query->where('is_template', false);
    }
}
