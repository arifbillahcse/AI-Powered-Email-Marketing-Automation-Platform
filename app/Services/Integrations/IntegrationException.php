<?php

namespace App\Services\Integrations;

use RuntimeException;

class IntegrationException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
