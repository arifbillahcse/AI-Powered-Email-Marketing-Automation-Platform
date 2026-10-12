<?php

namespace App\Services\Agency;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use App\Services\Workspaces\TeamManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Agencies run outreach for clients in client workspaces: separate leads,
 * mailboxes and campaigns, the agency's plan and branding, and optional
 * read-only access for the client (the Client role).
 */
class ClientWorkspaces
{
    public function __construct(
        protected Entitlements $entitlements,
        protected TeamManager $team,
    ) {}

    /**
     * Why $agency can't add client workspaces, or null.
     */
    public function denies(Workspace $agency): ?string
    {
        return match (true) {
            $agency->isClient() => 'A client workspace can\'t have clients of its own.',
            ! $this->entitlements->allowsModule($agency, 'white_label') => 'Client workspaces need a plan with the White Label module.',
            default => $this->entitlements->denies($agency, 'clients'),
        };
    }

    /**
     * Creates a client workspace. The creator becomes its owner and the
     * agency's other owners and admins its admins. With $clientEmail, the
     * client is invited with read-only access.
     *
     * @throws AgencyException
     */
    public function create(Workspace $agency, User $actor, string $name, string $timezone, ?string $clientEmail = null): Workspace
    {
        Gate::forUser($actor)->authorize('manageTeam', $agency);

        if ($reason = $this->denies($agency)) {
            throw new AgencyException($reason);
        }

        $client = DB::transaction(function () use ($agency, $actor, $name, $timezone): Workspace {
            $client = new Workspace(['name' => $name, 'timezone' => $timezone]);
            $client->parent_id = $agency->getKey();
            $client->save();

            $client->addMember($actor, WorkspaceRole::Owner);

            Membership::query()
                ->where('workspace_id', $agency->getKey())
                ->whereIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
                ->where('user_id', '!=', $actor->getKey())
                ->with('user')
                ->get()
                ->each(fn (Membership $membership) => $membership->user && $client->addMember($membership->user, WorkspaceRole::Admin));

            return $client;
        });

        if (filled($clientEmail)) {
            $this->team->invite($client, $actor, (string) $clientEmail, WorkspaceRole::Client);
        }

        return $client;
    }
}
