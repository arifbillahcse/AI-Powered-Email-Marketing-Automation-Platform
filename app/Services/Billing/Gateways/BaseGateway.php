<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use Illuminate\Http\Request;
use InvalidArgumentException;

abstract class BaseGateway implements PaymentGateway
{
    public function handleWebhook(Request $request): ?PaymentResult
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return (array) config('billing.gateways.'.$this->key(), []);
    }

    /**
     * The amount to charge, in minor units of $currency (converted with the
     * configured exchange rate when the invoice is priced in another currency).
     */
    protected function chargeAmount(Invoice $invoice, string $currency): int
    {
        $from = strtoupper($invoice->currency);
        $to = strtoupper($currency);

        if ($from === $to) {
            return $invoice->amount;
        }

        $rate = config("billing.exchange_rates.{$from}.{$to}");

        if (! $rate) {
            throw new InvalidArgumentException("No exchange rate from {$from} to {$to} (config/billing.php).");
        }

        return (int) round($invoice->amount * (float) $rate);
    }

    /**
     * Remember what we asked the gateway for, to verify the payment later.
     */
    protected function remember(Invoice $invoice, string $reference, int $amount, string $currency): void
    {
        $invoice->forceFill([
            'gateway' => $this->key(),
            'gateway_reference' => $reference,
            'meta' => [...($invoice->meta ?? []), 'charge' => ['amount' => $amount, 'currency' => strtoupper($currency)]],
        ])->save();
    }

    protected function matchesCharge(Invoice $invoice, int $amount, string $currency): bool
    {
        $charge = $invoice->meta['charge'] ?? null;

        return $charge !== null
            && (int) $charge['amount'] === $amount
            && strtoupper((string) $charge['currency']) === strtoupper($currency);
    }

    protected static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    protected static function minor(string|float|int $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    protected function returnUrl(Invoice $invoice): string
    {
        return route('billing.return', ['gateway' => $this->key(), 'invoice' => $invoice->getKey()]);
    }
}
