<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Cards via Stripe Checkout (one payment per invoice, over Stripe's REST
 * API). Confirmed by retrieving the Checkout Session on return, and by the
 * signed `checkout.session.completed` webhook.
 */
class StripeGateway extends BaseGateway
{
    protected const API = 'https://api.stripe.com/v1';

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Card (Stripe)';
    }

    public function available(): bool
    {
        return (bool) ($this->config()['enabled'] ?? false) && filled($this->config()['secret'] ?? null);
    }

    public function start(Invoice $invoice, User $payer): CheckoutStart
    {
        $currency = strtolower($invoice->currency);

        $response = $this->http()->asForm()->post(self::API.'/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => (string) $invoice->getKey(),
            'customer_email' => $payer->email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $invoice->amount,
                    'product_data' => ['name' => "{$invoice->number}: {$invoice->description}"],
                ],
            ]],
            'metadata' => ['invoice_id' => (string) $invoice->getKey(), 'invoice_number' => $invoice->number],
            'success_url' => $this->returnUrl($invoice).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->returnUrl($invoice).'?canceled=1',
        ]);

        if (! $response->successful() || ! $response->json('url')) {
            throw new GatewayException('Stripe: '.($response->json('error.message') ?? 'the payment page could not be created.'));
        }

        $this->remember($invoice, (string) $response->json('id'), $invoice->amount, $currency);

        return new CheckoutStart((string) $response->json('url'));
    }

    public function handleReturn(Invoice $invoice, Request $request): PaymentResult
    {
        if ($request->boolean('canceled')) {
            return PaymentResult::failed($invoice, 'Payment canceled.');
        }

        $sessionId = (string) $request->query('session_id', '');

        if ($sessionId === '' || $sessionId !== $invoice->gateway_reference) {
            return PaymentResult::failed($invoice, 'Unknown payment session.');
        }

        $response = $this->http()->get(self::API.'/checkout/sessions/'.rawurlencode($sessionId));

        return $response->successful()
            ? $this->resultFromSession($response->json() ?? [])
            : PaymentResult::failed($invoice, 'Stripe could not confirm the payment.');
    }

    public function handleWebhook(Request $request): ?PaymentResult
    {
        if (! $this->validSignature($request->getContent(), (string) $request->header('Stripe-Signature'))) {
            throw new GatewayException('Invalid Stripe signature.');
        }

        $event = json_decode($request->getContent(), true) ?: [];

        if (! in_array($event['type'] ?? null, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return null;
        }

        return $this->resultFromSession($event['data']['object'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function resultFromSession(array $session): PaymentResult
    {
        $invoice = Invoice::query()->find((int) ($session['client_reference_id'] ?? 0));

        if (! $invoice || $invoice->gateway_reference !== ($session['id'] ?? null)) {
            return PaymentResult::failed($invoice, 'The payment doesn\'t match this invoice.');
        }

        if (($session['payment_status'] ?? null) !== 'paid') {
            return PaymentResult::failed($invoice, 'The payment hasn\'t completed.');
        }

        if (! $this->matchesCharge($invoice, (int) ($session['amount_total'] ?? -1), (string) ($session['currency'] ?? ''))) {
            return PaymentResult::failed($invoice, 'The amount paid doesn\'t match the invoice.');
        }

        return PaymentResult::paid($invoice, (string) ($session['payment_intent'] ?? $session['id']));
    }

    /**
     * Stripe-Signature: t=timestamp,v1=hmac_sha256("t.payload", secret).
     */
    public function validSignature(string $payload, string $header, int $tolerance = 300): bool
    {
        $secret = (string) ($this->config()['webhook_secret'] ?? '');

        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    protected function http(): PendingRequest
    {
        return Http::withToken((string) $this->config()['secret'])->timeout(20);
    }
}
