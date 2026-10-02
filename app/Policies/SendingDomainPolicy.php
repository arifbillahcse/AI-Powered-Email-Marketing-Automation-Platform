<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesWorkspaceRecords;

class SendingDomainPolicy
{
    use AuthorizesWorkspaceRecords;
}
