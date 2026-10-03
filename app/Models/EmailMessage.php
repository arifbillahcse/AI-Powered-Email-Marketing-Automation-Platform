<?php

namespace App\Models;

use App\Enums\EmailEventType;
use App\Enums\EmailMessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Request;

class EmailMessage extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'sending',
        'campaign_step_id' => null,
        'email_account_id' => null,
        'message_id' => null,
        'subject' => null,
        'error' => null,
        'sent_at' => null,
        'opened_at' => null,
        'open_count' => 0,
        'clicked_at' => null,
        'click_count' => 0,
        'bounced_at' => null,
        'replied_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailMessageStatus::class,
            'step_position' => 'integer',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'open_count' => 'integer',
            'clicked_at' => 'datetime',
            'click_count' => 'integer',
            'bounced_at' => 'datetime',
            'replied_at' => 'datetime',
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
     * @return BelongsTo<EmailAccount, $this>
     */
    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    /**
     * @return HasMany<EmailEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }

    public function recordEvent(EmailEventType $type, ?string $url = null): EmailEvent
    {
        $event = new EmailEvent;
        $event->forceFill([
            'workspace_id' => $this->workspace_id,
            'email_message_id' => $this->getKey(),
            'campaign_id' => $this->campaign_id,
            'lead_id' => $this->lead_id,
            'email_account_id' => $this->email_account_id,
            'type' => $type,
            'url' => $url ? mb_substr($url, 0, 2048) : null,
            'ip' => Request::ip(),
            'user_agent' => mb_substr((string) Request::userAgent(), 0, 500) ?: null,
            'created_at' => now(),
        ])->save();

        return $event;
    }
}
