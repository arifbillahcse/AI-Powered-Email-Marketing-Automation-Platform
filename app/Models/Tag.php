<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsToMany<Lead, $this>
     */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'lead_tag');
    }

    public static function normalizeName(string $name): string
    {
        return Str::limit(Str::lower(trim(preg_replace('/\s+/', ' ', $name))), 50, '');
    }

    /**
     * IDs of the workspace's tags with these names, creating missing ones.
     *
     * @param  array<string>  $names
     * @return list<int>
     */
    public static function idsForNames(int $workspaceId, array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map(static::normalizeName(...), $names))));

        if ($names === []) {
            return [];
        }

        $existing = static::query()->where('workspace_id', $workspaceId)->whereIn('name', $names)->pluck('id', 'name');

        foreach (array_diff($names, $existing->keys()->all()) as $name) {
            $tag = new static(['name' => $name]);
            $tag->workspace_id = $workspaceId;
            $tag->save();
            $existing[$name] = $tag->getKey();
        }

        return $existing->values()->map(fn ($id): int => (int) $id)->all();
    }
}
