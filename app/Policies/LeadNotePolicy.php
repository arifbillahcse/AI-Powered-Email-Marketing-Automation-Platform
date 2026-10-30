<?php

namespace App\Policies;

use App\Models\LeadNote;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;
use Illuminate\Database\Eloquent\Model;

/**
 * Members read every note; everyone but Clients can write one. You change
 * your own notes; owners and admins can also remove anyone's.
 */
class LeadNotePolicy
{
    use AuthorizesWorkspaceRecords {
        update as protected canWriteInWorkspace;
    }

    public function update(User $user, Model $record): bool
    {
        /** @var LeadNote $record */
        return $this->canWriteInWorkspace($user, $record) && $record->user_id === $user->getKey();
    }

    public function delete(User $user, Model $record): bool
    {
        /** @var LeadNote $record */
        return $this->update($user, $record) || (bool) $user->roleIn($record->workspace_id)?->canManageTeam();
    }
}
