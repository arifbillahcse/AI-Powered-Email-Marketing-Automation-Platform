<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AuthorizesWorkspaceRecords;

/**
 * Generations are created by "AI personalize" on a campaign, never by hand.
 * Reviewing (approve, edit, reject, regenerate) counts as updating.
 */
class AiGenerationPolicy
{
    use AuthorizesWorkspaceRecords;

    public function create(User $user): bool
    {
        return false;
    }
}
