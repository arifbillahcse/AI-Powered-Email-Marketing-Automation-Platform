<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Keys and integrations reach the workspace's data from outside: owners
 * and admins only.
 */
class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace && (bool) $user->roleIn($workspace)?->canManageTeam();
    }

    public function view(User $user, Model $record): bool
    {
        return (bool) $user->roleIn($record->workspace_id)?->canManageTeam();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }
}
