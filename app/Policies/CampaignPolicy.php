<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;
use Illuminate\Database\Eloquent\Model;

class CampaignPolicy
{
    use AuthorizesWorkspaceRecords;

    /**
     * @param  Campaign  $record
     */
    public function delete(User $user, Model $record): bool
    {
        return (bool) $user->roleIn($record->workspace_id)?->canWrite();
    }
}
