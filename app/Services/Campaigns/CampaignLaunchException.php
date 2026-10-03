<?php

namespace App\Services\Campaigns;

use RuntimeException;

class CampaignLaunchException extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
