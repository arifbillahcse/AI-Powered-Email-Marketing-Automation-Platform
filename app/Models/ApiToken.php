<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A REST API key for one workspace. Only the SHA-256 hash is stored; the
 * key itself is shown once, when it's created.
 */
class ApiToken extends Model
{
    public const PREFIX = 'oak_';

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'abilities', 'expires_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'created_by' => null,
        'last_used_at' => null,
        'expires_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Create a key and return it with its plain-text value (shown once).
     *
     * @param  list<string>  $abilities
     * @return array{token: self, plain: string}
     */
    public static function issue(Workspace $workspace, string $name, array $abilities, ?User $by = null, ?DateTimeInterface $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(40);

        $token = new self(['name' => $name, 'abilities' => array_values($abilities), 'expires_at' => $expiresAt]);
        $token->forceFill([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $by?->getKey(),
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 12),
        ])->save();

        return ['token' => $token, 'plain' => $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        return static::query()->where('token_hash', hash('sha256', $plain))->first();
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
