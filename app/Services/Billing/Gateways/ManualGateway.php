<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Bank transfer (or any offline payment): the customer gets instructions,
 * and a super admin marks the invoice paid once the money arrives.
 */
class ManualGateway extends BaseGateway
{
    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return (string) ($this->config()['label'] ?? 'Bank transfer');
    }

    public function available(): bool
    {
        return (bool) ($this->config()['enabled'] ?? false);
    }

    public function start(Invoice $invoice, User $payer): CheckoutStart
    {
        $invoice->forceFill(['gateway' => $this->key()])->save();

        return new CheckoutStart(null, (string) ($this->config()['instructions'] ?? '')." Reference: {$invoice->number}.");
    }

    public function handleReturn(Invoice $invoice, Request $request): PaymentResult
    {
        return PaymentResult::failed($invoice, 'Bank transfers are confirmed by our team.');
    }
}
