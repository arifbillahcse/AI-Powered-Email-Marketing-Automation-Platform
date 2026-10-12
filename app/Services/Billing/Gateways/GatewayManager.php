<?php

namespace App\Services\Billing\Gateways;

use InvalidArgumentException;

class GatewayManager
{
    /**
     * @var array<string, class-string<PaymentGateway>>
     */
    public const GATEWAYS = [
        'stripe' => StripeGateway::class,
        'sslcommerz' => SslCommerzGateway::class,
        'bkash' => BkashGateway::class,
        'manual' => ManualGateway::class,
    ];

    public function get(string $key): PaymentGateway
    {
        $class = self::GATEWAYS[$key] ?? throw new InvalidArgumentException("Unknown payment gateway [{$key}].");

        return app($class);
    }

    /**
     * @return array<string, PaymentGateway>
     */
    public function available(): array
    {
        return collect(array_keys(self::GATEWAYS))
            ->mapWithKeys(fn (string $key): array => [$key => $this->get($key)])
            ->filter(fn (PaymentGateway $gateway): bool => $gateway->available())
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(fn (PaymentGateway $gateway): string => $gateway->label(), $this->available());
    }
}
