<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * A way to pay an invoice. Payments are only ever confirmed by asking the
 * gateway server-to-server (or by a verified webhook signature), never by
 * trusting what the browser sends back.
 */
interface PaymentGateway
{
    public function key(): string;

    public function label(): string;

    /** Configured and switched on in config/billing.php. */
    public function available(): bool;

    /**
     * Start paying: a URL to send the customer to, or instructions to show.
     */
    public function start(Invoice $invoice, User $payer): CheckoutStart;

    /**
     * The customer came back from the gateway (GET or POST).
     */
    public function handleReturn(Invoice $invoice, Request $request): PaymentResult;

    /**
     * A server-to-server notification. Null when it isn't about a payment.
     */
    public function handleWebhook(Request $request): ?PaymentResult;
}
