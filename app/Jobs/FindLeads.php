<?php

namespace App\Jobs;

use App\Enums\LeadActivityType;
use App\Models\Lead;
use App\Models\LeadList;
use App\Services\Billing\Entitlements;
use App\Services\LeadFinder\LeadFinder;
use App\Services\LeadFinder\LeadFinderException;
use App\Services\Leads\SuppressionList;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finds people at one company domain and adds them to a lead list. Leads
 * that already exist are only added to the list; suppressed addresses are
 * skipped; the plan's lead limit applies.
 */
class FindLeads implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    /**
     * @param  array{department?: ?string, seniority?: ?string, limit?: int}  $filters
     */
    public function __construct(
        public int $workspaceId,
        public int $leadListId,
        public string $domain,
        public array $filters = [],
    ) {
        $this->onQueue('imports');
    }

    public function backoff(): int
    {
        return 60;
    }

    public function handle(LeadFinder $finder, SuppressionList $suppressions, Entitlements $entitlements): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $list = LeadList::query()->where('workspace_id', $this->workspaceId)->find($this->leadListId);

        if (! $list) {
            return;
        }

        try {
            $result = $finder->search($this->domain, $this->filters);
        } catch (LeadFinderException $exception) {
            report($exception);

            return;
        }

        $remaining = $entitlements->remaining($this->workspaceId, 'leads');

        foreach ($result['people'] as $person) {
            if ($suppressions->isSuppressed($this->workspaceId, $person['email'])) {
                continue;
            }

            $lead = Lead::query()->where('workspace_id', $this->workspaceId)->where('email', $person['email'])->first();

            if (! $lead) {
                if ($remaining !== null && $remaining <= 0) {
                    break;
                }

                $lead = new Lead([
                    ...array_filter($person, fn ($value): bool => $value !== null),
                    'company' => $result['company'],
                    'website' => 'https://'.$this->domain,
                ]);
                $lead->workspace_id = $this->workspaceId;
                $lead->source = 'lead_finder';
                $lead->save();
                $lead->logActivity(LeadActivityType::Imported, "Found with the lead finder ({$this->domain})");

                if ($remaining !== null) {
                    $remaining--;
                }
            }

            $lead->lists()->syncWithoutDetaching([$list->getKey()]);
        }
    }
}
