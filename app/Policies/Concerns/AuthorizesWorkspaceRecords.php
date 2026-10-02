<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard rules for records owned by a workspace (`workspace_id`):
 * any member can view, everyone except Clients can create and change.
 */
trait AuthorizesWorkspaceRecords
{
    public function viewAny(User $user): bool
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace && $user->roleIn($workspace) !== null;
    }

    public function view(User $user, Model $record): bool
    {
        return $user->roleIn($record->workspace_id) !== null;
    }

    public function create(User $user): bool
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace && (bool) $user->roleIn($workspace)?->canWrite();
    }

    public function update(User $user, Model $record): bool
    {
        return (bool) $user->roleIn($record->workspace_id)?->canWrite();
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }
}
