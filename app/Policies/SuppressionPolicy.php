<?php

namespace App\Policies;

use App\Enums\SuppressionReason;
use App\Models\Suppression;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;
use Illuminate\Database\Eloquent\Model;

class SuppressionPolicy
{
    use AuthorizesWorkspaceRecords;

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    /**
     * Unsubscribes and spam complaints are permanent (the law requires us to
     * honor them). Owners and admins can lift manual entries and bounces.
     *
     * @param  Suppression  $record
     */
    public function delete(User $user, Model $record): bool
    {
        if (in_array($record->reason, [SuppressionReason::Unsubscribed, SuppressionReason::Complaint], true)) {
            return false;
        }

        return (bool) $user->roleIn($record->workspace_id)?->canManageTeam();
    }
}
