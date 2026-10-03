<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;

/**
 * Conversations are created by reply detection, never by hand. Replying
 * and labelling count as updating.
 */
class InboxThreadPolicy
{
    use AuthorizesWorkspaceRecords;

    public function create(User $user): bool
    {
        return false;
    }
}
