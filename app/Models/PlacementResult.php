<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one placement test email landed in one seed mailbox.
 */
class PlacementResult extends Model
{
    public const STATUSES = ['pending', 'inbox', 'spam', 'missing', 'failed'];

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'checked_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlacementTest, $this>
     */
    public function placementTest(): BelongsTo
    {
        return $this->belongsTo(PlacementTest::class);
    }

    /**
     * @return BelongsTo<EmailAccount, $this>
     */
    public function seed(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'seed_id');
    }

    public static function color(string $status): string
    {
        return match ($status) {
            'inbox' => 'success',
            'spam' => 'danger',
            'missing', 'failed' => 'warning',
            default => 'gray',
        };
    }
}
