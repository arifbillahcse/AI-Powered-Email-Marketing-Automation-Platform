<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesWorkspaceRecords;

class EmailAccountPolicy
{
    use AuthorizesWorkspaceRecords;
}
