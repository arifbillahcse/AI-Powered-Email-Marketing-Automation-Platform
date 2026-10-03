<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * The leads a campaign targets: everyone in any of its lists or segments,
 * minus anyone on the suppression list.
 */
class CampaignAudience
{
    /**
     * @return Builder<Lead>
     */
    public function query(Campaign $campaign): Builder
    {
        $listIds = $campaign->leadLists()->pluck('lead_lists.id')->all();
        $segments = $campaign->segments()->get();

        $query = Lead::query()->where('leads.workspace_id', $campaign->workspace_id);

        if ($listIds === [] && $segments->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where(function (Builder $audience) use ($listIds, $segments): void {
                if ($listIds !== []) {
                    $audience->orWhereHas('lists', fn (Builder $list) => $list->whereIn('lead_lists.id', $listIds));
                }

                foreach ($segments as $segment) {
                    $audience->orWhereIn('leads.id', $segment->leadsQuery()->select('leads.id'));
                }
            })
            ->whereNotSuppressed();
    }

    public function count(Campaign $campaign): int
    {
        return $this->query($campaign)->count();
    }
}
