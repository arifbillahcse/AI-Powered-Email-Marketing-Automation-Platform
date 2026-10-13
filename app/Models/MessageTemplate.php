<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * A saved one-off email. Variables ({{first_name|there}}) and spintax are
 * filled in for the lead when the email is sent.
 */
class MessageTemplate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'subject',
        'body',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'created_by' => null,
        'subject' => null,
        'times_used' => 0,
    ];

    protected function casts(): array
    {
        return [
            'times_used' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MessageTemplate $template): void {
            $template->created_by ??= Auth::id();
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
