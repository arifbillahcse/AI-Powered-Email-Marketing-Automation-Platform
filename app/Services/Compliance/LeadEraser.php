<?php

namespace App\Services\Compliance;

use App\Enums\SuppressionReason;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\SuppressionList;
use Illuminate\Support\Facades\DB;

/**
 * Right to erasure (GDPR art. 17): deletes the lead and everything about
 * them (timeline, campaign progress, emails sent, replies, AI content: all
 * cascade from the lead). Only the email address is kept, on the
 * suppression list, so they are never contacted again. Their emails stop
 * counting in analytics.
 */
class LeadEraser
{
    public function __construct(
        protected SuppressionList $suppressions,
    ) {}

    public function erase(Lead $lead, ?User $by = null): void
    {
        DB::transaction(function () use ($lead, $by): void {
            $suppression = $this->suppressions->add($lead->workspace_id, $lead->email, SuppressionReason::Erased, $by);

            // An existing entry (e.g. "unsubscribed") now records the erasure.
            if ($suppression->reason !== SuppressionReason::Erased) {
                $suppression->forceFill(['reason' => SuppressionReason::Erased])->save();
            }

            $lead->delete();
        });
    }
}
