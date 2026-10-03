<?php

namespace App\Models;

use App\Enums\InboxMessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply received from a lead (inbound) or written in the Unibox
 * (outbound). Bodies are stored as plain text and never rendered as HTML.
 */
class InboxMessage extends Model
{
    public const INBOUND = 'inbound';

    public const OUTBOUND = 'outbound';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'received',
        'email_account_id' => null,
        'email_message_id' => null,
        'user_id' => null,
        'message_id' => null,
        'in_reply_to' => null,
        'references' => null,
        'from_name' => null,
        'subject' => null,
        'auto_reply' => false,
        'error' => null,
        'imap_uid' => null,
        'sent_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => InboxMessageStatus::class,
            'auto_reply' => 'boolean',
            'imap_uid' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function isInbound(): bool
    {
        return $this->direction === self::INBOUND;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<InboxThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(InboxThread::class, 'inbox_thread_id');
    }

    /**
     * @return BelongsTo<EmailAccount, $this>
     */
    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
