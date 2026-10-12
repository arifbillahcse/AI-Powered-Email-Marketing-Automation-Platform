<?php

namespace App\Services\Campaigns;

use App\Enums\EmailVerificationStatus;
use App\Models\Campaign;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * The leads a campaign targets: everyone in any of its lists or segments,
 * minus anyone on the suppression list and addresses verification found
 * invalid (or, with "verified only", everyone not verified as valid).
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
            ->whereNotSuppressed()
            ->when(
                $campaign->verified_only,
                fn (Builder $query) => $query->where('leads.verification_status', EmailVerificationStatus::Valid->value),
                fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->whereNull('leads.verification_status')
                    ->orWhere('leads.verification_status', '!=', EmailVerificationStatus::Invalid->value)),
            );
    }

    public function count(Campaign $campaign): int
    {
        return $this->query($campaign)->count();
    }
}
