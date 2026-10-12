<?php

namespace App\Services\Billing\Gateways;

use RuntimeException;

/**
 * A gateway refused or failed; the message is safe to show the customer.
 */
class GatewayException extends RuntimeException {}
