<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'api_key',
        'model',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_key',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'provider' => 'platform',
        'api_key' => null,
        'model' => null,
    ];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'api_key' => 'encrypted',
        ];
    }

    public static function for(Workspace|int $workspace): self
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->getKey() : $workspace;

        $setting = static::query()->where('workspace_id', $workspaceId)->first();

        if (! $setting) {
            $setting = new static;
            $setting->workspace_id = $workspaceId;
        }

        return $setting;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function effectiveModel(): string
    {
        return match ($this->provider) {
            AiProvider::Platform => (string) config('outreach.ai.platform_model'),
            AiProvider::Anthropic => $this->model ?: 'claude-opus-5-5',
            AiProvider::OpenAi => (string) $this->model,
        };
    }

    public function effectiveApiKey(): ?string
    {
        return $this->provider === AiProvider::Platform
            ? config('outreach.ai.platform_api_key')
            : $this->api_key;
    }
}
