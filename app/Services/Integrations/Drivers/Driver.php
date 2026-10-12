<?php

namespace App\Services\Integrations\Drivers;

use App\Models\Integration;
use App\Services\Integrations\IntegrationException;

interface Driver
{
    /**
     * @param  array<string, mixed>  $payload
     * @return int|null HTTP status of the delivery
     *
     * @throws IntegrationException
     */
    public function deliver(Integration $integration, string $event, array $payload): ?int;
}
