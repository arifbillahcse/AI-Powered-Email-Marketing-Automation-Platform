<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * SSLCommerz (cards, mobile banking, internet banking in Bangladesh).
 * Every success (browser return or IPN) is confirmed with the Order
 * Validation API before the invoice is marked paid.
 */
class SslCommerzGateway extends BaseGateway
{
    public function key(): string
    {
        return 'sslcommerz';
    }

    public function label(): string
    {
        return 'SSLCommerz (cards, bKash, Nagad, banks)';
    }

    public function available(): bool
    {
        $config = $this->config();

        return (bool) ($config['enabled'] ?? false) && filled($config['store_id'] ?? null) && filled($config['store_password'] ?? null);
    }

    public function start(Invoice $invoice, User $payer): CheckoutStart
    {
        $config = $this->config();
        $currency = strtoupper((string) ($config['currency'] ?? 'BDT'));
        $amount = $this->chargeAmount($invoice, $currency);
        $tranId = $invoice->number.'-'.Str::upper(Str::random(6));
        $return = $this->returnUrl($invoice);

        $response = Http::asForm()->timeout(20)->post($this->baseUrl().'/gwprocess/v4/api.php', [
            'store_id' => $config['store_id'],
            'store_passwd' => $config['store_password'],
            'total_amount' => self::decimal($amount),
            'currency' => $currency,
            'tran_id' => $tranId,
            'success_url' => $return,
            'fail_url' => $return,
            'cancel_url' => $return,
            'ipn_url' => route('billing.webhook', ['gateway' => $this->key()]),
            'cus_name' => $payer->name,
            'cus_email' => $payer->email,
            'cus_phone' => 'N/A',
            'cus_add1' => 'N/A',
            'cus_city' => 'N/A',
            'cus_country' => 'Bangladesh',
            'shipping_method' => 'NO',
            'num_of_item' => 1,
            'product_name' => Str::limit($invoice->description, 250, ''),
            'product_category' => 'Software',
            'product_profile' => 'non-physical-goods',
            'value_a' => (string) $invoice->getKey(),
        ]);

        if (strtoupper((string) $response->json('status')) !== 'SUCCESS' || ! $response->json('GatewayPageURL')) {
            throw new GatewayException('SSLCommerz: '.($response->json('failedreason') ?: 'the payment page could not be created.'));
        }

        $this->remember($invoice, $tranId, $amount, $currency);

        return new CheckoutStart((string) $response->json('GatewayPageURL'));
    }

    public function handleReturn(Invoice $invoice, Request $request): PaymentResult
    {
        if ((string) $request->input('tran_id') !== $invoice->gateway_reference) {
            return PaymentResult::failed($invoice, 'Unknown payment.');
        }

        if (! in_array(strtoupper((string) $request->input('status')), ['VALID', 'VALIDATED'], true) || blank($request->input('val_id'))) {
            return PaymentResult::failed($invoice, strtoupper((string) $request->input('status')) === 'CANCELLED' ? 'Payment canceled.' : 'The payment failed.');
        }

        return $this->validate($invoice, (string) $request->input('val_id'));
    }

    public function handleWebhook(Request $request): ?PaymentResult
    {
        $tranId = (string) $request->input('tran_id');
        $invoice = $tranId === '' ? null : Invoice::query()->where('gateway', $this->key())->where('gateway_reference', $tranId)->first();

        if (! $invoice) {
            return null;
        }

        if (! in_array(strtoupper((string) $request->input('status')), ['VALID', 'VALIDATED'], true) || blank($request->input('val_id'))) {
            return PaymentResult::failed($invoice, 'The payment failed.');
        }

        return $this->validate($invoice, (string) $request->input('val_id'));
    }

    /**
     * Ask SSLCommerz whether this payment is real, complete and for the
     * right amount.
     */
    protected function validate(Invoice $invoice, string $valId): PaymentResult
    {
        $config = $this->config();

        $response = Http::timeout(20)->get($this->baseUrl().'/validator/api/validationserverAPI.php', [
            'val_id' => $valId,
            'store_id' => $config['store_id'],
            'store_passwd' => $config['store_password'],
            'v' => 1,
            'format' => 'json',
        ]);

        $data = $response->json() ?? [];

        if (! in_array(strtoupper((string) ($data['status'] ?? '')), ['VALID', 'VALIDATED'], true)) {
            return PaymentResult::failed($invoice, 'SSLCommerz could not confirm the payment.');
        }

        if (($data['tran_id'] ?? null) !== $invoice->gateway_reference
            || ! $this->matchesCharge($invoice, self::minor($data['currency_amount'] ?? $data['amount'] ?? 0), (string) ($data['currency_type'] ?? $data['currency'] ?? ''))) {
            return PaymentResult::failed($invoice, 'The payment doesn\'t match the invoice.');
        }

        return PaymentResult::paid($invoice, (string) ($data['bank_tran_id'] ?? $valId));
    }

    protected function baseUrl(): string
    {
        return ($this->config()['sandbox'] ?? true) ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
    }
}
