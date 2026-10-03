<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/**
 * An AI call failed. The message is safe to show to users. Retryable
 * failures (rate limits, overload, network) are retried by the queue.
 */
class AiException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
