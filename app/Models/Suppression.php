<?php

namespace App\Models;

use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email address or whole domain that must never be emailed by this
 * workspace. Checked before every send (see Lead::scopeWhereNotSuppressed).
 */
class Suppression extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'value',
        'reason',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'reason' => 'manual',
        'created_by' => null,
    ];

    protected function casts(): array
    {
        return [
            'type' => SuppressionType::class,
            'reason' => SuppressionReason::class,
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
