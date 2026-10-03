<?php

namespace App\Models;

use App\Services\Leads\SegmentQuery;
use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved, dynamic filter over a workspace's leads. Campaigns (Phase 4) can
 * target a segment; its members are recalculated whenever it's used.
 *
 * Each rule: ['field' => string, 'operator' => string, 'value' => mixed, 'key' => ?string]
 */
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'match',
        'rules',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'match' => 'all',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
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
     * @return Builder<Lead>
     */
    public function leadsQuery(): Builder
    {
        return app(SegmentQuery::class)->apply(
            Lead::query()->where('leads.workspace_id', $this->workspace_id),
            $this->rules ?? [],
            $this->match,
            $this->workspace_id,
        );
    }
}
