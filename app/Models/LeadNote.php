<?php

namespace App\Models;

use App\Enums\LeadActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * A team note about a lead: what you found on their website, who you
 * contacted, what to do next. Plain text; every workspace member sees it.
 */
class LeadNote extends Model
{
    public const MAX_LENGTH = 5000;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'body',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'user_id' => null,
    ];

    protected static function booted(): void
    {
        static::creating(function (LeadNote $note): void {
            $note->user_id ??= Auth::id();
        });

        static::created(function (LeadNote $note): void {
            $note->lead->logActivity(LeadActivityType::NoteAdded, 'Note: '.str($note->body)->squish()->limit(120));
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
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
