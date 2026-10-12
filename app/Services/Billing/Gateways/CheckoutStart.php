<?php

namespace App\Services\Billing\Gateways;

final class CheckoutStart
{
    public function __construct(
        public readonly ?string $redirectUrl,
        public readonly ?string $instructions = null,
    ) {}
}
