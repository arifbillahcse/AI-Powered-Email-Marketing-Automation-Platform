<?php

namespace App\Models;

use App\Enums\IntegrationType;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A connection that receives workspace events (see IntegrationEvents).
 * `config` holds URLs and credentials, encrypted at rest.
 */
class Integration extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['type', 'name', 'config', 'events', 'is_active'];

    /**
     * @var list<string>
     */
    protected $hidden = ['config'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'failure_count' => 0,
        'last_delivered_at' => null,
        'last_error' => null,
    ];

    protected function casts(): array
    {
        return [
            'type' => IntegrationType::class,
            'config' => AsEncryptedArrayObject::class,
            'events' => 'array',
            'is_active' => 'boolean',
            'failure_count' => 'integer',
            'last_delivered_at' => 'datetime',
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<IntegrationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(IntegrationDelivery::class);
    }
}
