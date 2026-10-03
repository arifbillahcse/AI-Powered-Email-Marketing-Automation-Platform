<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'delay_days' => 0,
        'subject' => null,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'delay_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function isReplyInThread(): bool
    {
        return blank($this->subject) && $this->position > 1;
    }
}
