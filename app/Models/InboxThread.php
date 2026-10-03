<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Unibox conversation: everything between one lead and us about one
 * campaign (the campaign's emails, their replies, our replies).
 */
class InboxThread extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'email_account_id' => null,
        'subject' => null,
        'snippet' => null,
        'unread' => true,
        'auto_reply' => false,
        'message_count' => 0,
        'last_message_at' => null,
        'last_inbound_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'unread' => 'boolean',
            'auto_reply' => 'boolean',
            'message_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
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
     * @return BelongsTo<CampaignLead, $this>
     */
    public function campaignLead(): BelongsTo
    {
        return $this->belongsTo(CampaignLead::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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
     * @return HasMany<InboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class);
    }
}
