<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_usage';

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
        ];
    }

    /**
     * Tokens a workspace used since the start of this month, optionally only
     * through one provider (the included credits' allowance counts only
     * platform usage).
     */
    public static function tokensThisMonth(int $workspaceId, ?AiProvider $provider = null): int
    {
        return (int) static::query()
            ->where('workspace_id', $workspaceId)
            ->when($provider, fn ($query) => $query->where('provider', $provider->value))
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('coalesce(sum(input_tokens + output_tokens + cache_read_tokens + cache_write_tokens), 0) as total')
            ->value('total');
    }
}
