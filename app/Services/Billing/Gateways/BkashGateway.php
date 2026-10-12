<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * bKash Tokenized Checkout (BDT). The customer approves the payment in
 * bKash and comes back with a paymentID; we execute it server-side and
 * only a "Completed" transaction for the right amount marks the invoice paid.
 */
class BkashGateway extends BaseGateway
{
    public function key(): string
    {
        return 'bkash';
    }

    public function label(): string
    {
        return 'bKash';
    }

    public function available(): bool
    {
        $config = $this->config();

        return (bool) ($config['enabled'] ?? false)
            && filled($config['app_key'] ?? null) && filled($config['app_secret'] ?? null)
            && filled($config['username'] ?? null) && filled($config['password'] ?? null);
    }

    public function start(Invoice $invoice, User $payer): CheckoutStart
    {
        $amount = $this->chargeAmount($invoice, 'BDT');

        $response = $this->api()->post($this->baseUrl().'/create', [
            'mode' => '0011',
            'payerReference' => (string) $invoice->workspace_id,
            'callbackURL' => $this->returnUrl($invoice),
            'amount' => self::decimal($amount),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $invoice->number,
        ]);

        if ($response->json('statusCode') !== '0000' || ! $response->json('bkashURL')) {
            throw new GatewayException('bKash: '.($response->json('statusMessage') ?? 'the payment could not be created.'));
        }

        $this->remember($invoice, (string) $response->json('paymentID'), $amount, 'BDT');

        return new CheckoutStart((string) $response->json('bkashURL'));
    }

    public function handleReturn(Invoice $invoice, Request $request): PaymentResult
    {
        $paymentId = (string) $request->query('paymentID', '');

        if ($paymentId === '' || $paymentId !== $invoice->gateway_reference) {
            return PaymentResult::failed($invoice, 'Unknown payment.');
        }

        if ($request->query('status') !== 'success') {
            return PaymentResult::failed($invoice, $request->query('status') === 'cancel' ? 'Payment canceled.' : 'The payment failed.');
        }

        $data = $this->api()->post($this->baseUrl().'/execute', ['paymentID' => $paymentId])->json() ?? [];

        // Already executed (e.g. a refreshed page): ask for its status instead.
        if (($data['transactionStatus'] ?? null) !== 'Completed') {
            $data = $this->api()->post($this->baseUrl().'/payment/status', ['paymentID' => $paymentId])->json() ?? [];
        }

        if (($data['transactionStatus'] ?? null) !== 'Completed') {
            return PaymentResult::failed($invoice, 'bKash: '.($data['statusMessage'] ?? 'the payment wasn\'t completed.'));
        }

        if (($data['merchantInvoiceNumber'] ?? $invoice->number) !== $invoice->number
            || ! $this->matchesCharge($invoice, self::minor($data['amount'] ?? 0), (string) ($data['currency'] ?? 'BDT'))) {
            return PaymentResult::failed($invoice, 'The payment doesn\'t match the invoice.');
        }

        return PaymentResult::paid($invoice, (string) ($data['trxID'] ?? $paymentId));
    }

    protected function api(): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->withHeaders([
            'Authorization' => $this->token(),
            'X-APP-Key' => (string) $this->config()['app_key'],
        ]);
    }

    /**
     * Grant tokens last an hour; reuse one for 50 minutes.
     */
    protected function token(): string
    {
        $config = $this->config();

        return Cache::remember('billing:bkash:token:'.md5((string) $config['app_key']), now()->addMinutes(50), function () use ($config): string {
            $response = Http::acceptJson()->timeout(30)->withHeaders([
                'username' => (string) $config['username'],
                'password' => (string) $config['password'],
            ])->post($this->baseUrl().'/token/grant', [
                'app_key' => $config['app_key'],
                'app_secret' => $config['app_secret'],
            ]);

            if (! $response->json('id_token')) {
                throw new GatewayException('bKash: '.($response->json('statusMessage') ?? 'could not sign in to bKash.'));
            }

            return (string) $response->json('id_token');
        });
    }

    protected function baseUrl(): string
    {
        return ($this->config()['sandbox'] ?? true)
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout';
    }
}
