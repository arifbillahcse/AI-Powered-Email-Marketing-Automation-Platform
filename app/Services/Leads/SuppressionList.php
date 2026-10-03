<?php

namespace App\Services\Leads;

use App\Enums\LeadActivityType;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Lead;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The workspace-wide do-not-contact list. Unsubscribes (Phase 5) and hard
 * bounces / complaints (Phases 5 and 9) are added here automatically.
 */
class SuppressionList
{
    /**
     * Accepts "person@acme.com", "acme.com" or "@acme.com".
     */
    public function add(int $workspaceId, string $entry, SuppressionReason $reason = SuppressionReason::Manual, ?User $by = null): Suppression
    {
        [$type, $value] = $this->parse($entry);

        $suppression = Suppression::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', $type->value)
            ->where('value', $value)
            ->first();

        if ($suppression) {
            return $suppression;
        }

        $suppression = new Suppression(['type' => $type, 'value' => $value, 'reason' => $reason]);
        $suppression->workspace_id = $workspaceId;
        $suppression->created_by = $by?->getKey();
        $suppression->save();

        if ($type === SuppressionType::Email) {
            Lead::query()
                ->where('workspace_id', $workspaceId)
                ->where('email', $value)
                ->first()
                ?->logActivity(LeadActivityType::Suppressed, "Added to the suppression list ({$reason->getLabel()})");
        }

        return $suppression;
    }

    /**
     * Add many entries (one per line, or comma separated). Returns how many
     * were new and which lines were invalid.
     *
     * @return array{added: int, invalid: list<string>}
     */
    public function addMany(int $workspaceId, string $entries, SuppressionReason $reason, ?User $by = null): array
    {
        $added = 0;
        $invalid = [];

        foreach (preg_split('/[\s,;]+/', $entries) ?: [] as $entry) {
            if (trim($entry) === '') {
                continue;
            }

            try {
                $suppression = $this->add($workspaceId, $entry, $reason, $by);
                $added += $suppression->wasRecentlyCreated ? 1 : 0;
            } catch (InvalidArgumentException) {
                $invalid[] = $entry;
            }
        }

        return ['added' => $added, 'invalid' => $invalid];
    }

    public function isSuppressed(int $workspaceId, string $email): bool
    {
        $email = Str::lower(trim($email));
        $domain = Str::after($email, '@');

        return Suppression::query()
            ->where('workspace_id', $workspaceId)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('type', SuppressionType::Email->value)->where('value', $email))
                ->orWhere(fn ($q) => $q->where('type', SuppressionType::Domain->value)->where('value', $domain)))
            ->exists();
    }

    /**
     * @return array{0: SuppressionType, 1: string}
     */
    public function parse(string $entry): array
    {
        $entry = Str::lower(trim($entry));

        if (str_starts_with($entry, '@')) {
            $entry = substr($entry, 1);
        }

        if (str_contains($entry, '@')) {
            if (! filter_var($entry, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("\"{$entry}\" isn't a valid email address.");
            }

            return [SuppressionType::Email, $entry];
        }

        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $entry)) {
            throw new InvalidArgumentException("\"{$entry}\" isn't a valid email address or domain.");
        }

        return [SuppressionType::Domain, $entry];
    }
}
