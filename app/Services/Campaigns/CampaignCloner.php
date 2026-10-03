<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CampaignCloner
{
    /**
     * Copy a campaign or template as a new draft (or as a template).
     * Templates keep the sequence, schedule and options, but not the
     * audience or mailboxes.
     */
    public function clone(Campaign $source, string $name, bool $asTemplate = false, ?User $by = null): Campaign
    {
        return DB::transaction(function () use ($source, $name, $asTemplate, $by): Campaign {
            $copy = new Campaign($source->only([
                'timezone', 'send_days', 'send_window_start', 'send_window_end', 'daily_limit',
                'stop_on_reply', 'track_opens', 'track_clicks', 'plain_text',
            ]));
            $copy->name = $name;
            $copy->workspace_id = $source->workspace_id;
            $copy->status = CampaignStatus::Draft;
            $copy->is_template = $asTemplate;
            $copy->created_by = $by?->getKey();
            $copy->save();

            foreach ($source->steps()->get() as $step) {
                $copy->steps()->create($step->only(['position', 'delay_days', 'subject', 'body']));
            }

            if (! $asTemplate && ! $source->is_template) {
                $copy->leadLists()->sync($source->leadLists()->pluck('lead_lists.id'));
                $copy->segments()->sync($source->segments()->pluck('segments.id'));
                $copy->emailAccounts()->sync($source->emailAccounts()->pluck('email_accounts.id'));
            }

            return $copy;
        });
    }
}
