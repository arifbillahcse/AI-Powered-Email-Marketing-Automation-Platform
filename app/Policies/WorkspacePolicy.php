<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    /**
     * Any signed-in user may create their own workspace.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $user->roleIn($workspace) !== null;
    }

    /**
     * Edit workspace settings (name, time zone, mailing address).
     */
    public function update(User $user, Workspace $workspace): bool
    {
        return (bool) $user->roleIn($workspace)?->canManageTeam();
    }

    /**
     * Invite people, change roles, remove members.
     */
    public function manageTeam(User $user, Workspace $workspace): bool
    {
        return (bool) $user->roleIn($workspace)?->canManageTeam();
    }
}
