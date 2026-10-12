<?php

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Gateways\BkashGateway;
use App\Services\Billing\Gateways\GatewayManager;
use App\Services\Billing\Gateways\SslCommerzGateway;
use App\Services\Billing\Gateways\StripeGateway;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->plan = billingOn(['price_monthly' => 2900, 'currency' => 'USD']);
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->addMember($this->owner, WorkspaceRole::Owner);
    $this->invoice = app(SubscriptionManager::class)->invoiceFor($this->workspace, $this->plan, BillingInterval::Monthly);

    config([
        'billing.exchange_rates.USD.BDT' => 122,
        'billing.gateways.stripe' => ['enabled' => true, 'secret' => 'sk_test_x', 'webhook_secret' => 'whsec_test'],
        'billing.gateways.sslcommerz' => ['enabled' => true, 'store_id' => 'store', 'store_password' => 'pass', 'sandbox' => true, 'currency' => 'BDT'],
        'billing.gateways.bkash' => ['enabled' => true, 'app_key' => 'key', 'app_secret' => 'secret', 'username' => 'user', 'password' => 'pass', 'sandbox' => true],
    ]);
});

function stripeSession(Invoice $invoice, array $overrides = []): array
{
    return array_merge([
        'id' => 'cs_test_1',
        'client_reference_id' => (string) $invoice->id,
        'payment_status' => 'paid',
        'amount_total' => 2900,
        'currency' => 'usd',
        'payment_intent' => 'pi_123',
    ], $overrides);
}

it('lists only configured gateways', function () {
    config(['billing.gateways.bkash.app_key' => null]);

    expect(array_keys(app(GatewayManager::class)->available()))->toBe(['stripe', 'sslcommerz', 'manual']);
});

it('pays with Stripe Checkout and confirms on return', function () {
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response(stripeSession($this->invoice)),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
    ]);

    $start = app(StripeGateway::class)->start($this->invoice, $this->owner);

    expect($start->redirectUrl)->toBe('https://checkout.stripe.com/c/pay/cs_test_1')
        ->and($this->invoice->refresh()->gateway_reference)->toBe('cs_test_1')
        ->and($this->invoice->meta['charge'])->toBe(['amount' => 2900, 'currency' => 'USD']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
        && (int) $request['line_items'][0]['price_data']['unit_amount'] === 2900
        && $request['client_reference_id'] === (string) $this->invoice->id);

    $this->get(route('billing.return', ['gateway' => 'stripe', 'invoice' => $this->invoice]).'?session_id=cs_test_1')
        ->assertRedirectContains('payment=paid');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->meta['transaction_id'])->toBe('pi_123')
        ->and(Subscription::sole()->status)->toBe(SubscriptionStatus::Active);
});

it('rejects a Stripe payment for the wrong amount', function () {
    $this->invoice->forceFill(['gateway' => 'stripe', 'gateway_reference' => 'cs_test_1', 'meta' => ['charge' => ['amount' => 2900, 'currency' => 'USD']]])->save();
    Http::fake(['api.stripe.com/*' => Http::response(stripeSession($this->invoice, ['amount_total' => 100]))]);

    $this->get(route('billing.return', ['gateway' => 'stripe', 'invoice' => $this->invoice]).'?session_id=cs_test_1')
        ->assertRedirectContains('payment=failed');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
});

it('accepts only signed Stripe webhooks', function () {
    $this->invoice->forceFill(['gateway' => 'stripe', 'gateway_reference' => 'cs_test_1', 'meta' => ['charge' => ['amount' => 2900, 'currency' => 'USD']]])->save();
    $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => stripeSession($this->invoice)]]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

    $this->call('POST', route('billing.webhook', ['gateway' => 'stripe']), [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=bad", 'CONTENT_TYPE' => 'application/json'], $payload)
        ->assertStatus(400);
    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);

    $this->call('POST', route('billing.webhook', ['gateway' => 'stripe']), [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'], $payload)
        ->assertOk();
    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('pays with SSLCommerz in BDT, validated server-side', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'gwprocess/v4/api.php')) {
            return Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/abc']);
        }

        return Http::response([
            'status' => 'VALID',
            'tran_id' => Invoice::query()->find($this->invoice->id)->gateway_reference,
            'currency_amount' => '3538.00',
            'currency_type' => 'BDT',
            'bank_tran_id' => 'BANK123',
        ]);
    });

    $start = app(SslCommerzGateway::class)->start($this->invoice, $this->owner);
    $tranId = $this->invoice->refresh()->gateway_reference;

    expect($start->redirectUrl)->toBe('https://sandbox.sslcommerz.com/EasyCheckOut/abc')
        ->and($this->invoice->meta['charge'])->toBe(['amount' => 353800, 'currency' => 'BDT'])
        ->and($tranId)->toStartWith($this->invoice->number.'-');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'gwprocess') && $request['total_amount'] === '3538.00' && $request['currency'] === 'BDT');

    // SSLCommerz POSTs back cross-site: no CSRF token, no session.
    $this->post(route('billing.return', ['gateway' => 'sslcommerz', 'invoice' => $this->invoice]), ['tran_id' => $tranId, 'val_id' => 'VAL1', 'status' => 'VALID'])
        ->assertRedirectContains('payment=paid');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->meta['transaction_id'])->toBe('BANK123');
});

it('never trusts an SSLCommerz success the validator rejects', function () {
    $this->invoice->forceFill(['gateway' => 'sslcommerz', 'gateway_reference' => 'T1', 'meta' => ['charge' => ['amount' => 353800, 'currency' => 'BDT']]])->save();
    Http::fake(['*' => Http::response(['status' => 'INVALID_TRANSACTION'])]);

    $this->post(route('billing.webhook', ['gateway' => 'sslcommerz']), ['tran_id' => 'T1', 'val_id' => 'FAKE', 'status' => 'VALID'])->assertOk();

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
});

it('pays with bKash after executing the payment', function () {
    Http::fake([
        '*/token/grant' => Http::response(['id_token' => 'token-1']),
        '*/create' => Http::response(['statusCode' => '0000', 'paymentID' => 'PAY1', 'bkashURL' => 'https://sandbox.payment.bkash.com/?paymentId=PAY1']),
        '*/execute' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'amount' => '3538.00', 'currency' => 'BDT', 'merchantInvoiceNumber' => $this->invoice->number, 'trxID' => 'TRX9']),
    ]);

    $start = app(BkashGateway::class)->start($this->invoice, $this->owner);

    expect($start->redirectUrl)->toBe('https://sandbox.payment.bkash.com/?paymentId=PAY1');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/create') && $request['amount'] === '3538.00' && $request->hasHeader('Authorization', 'token-1'));

    $this->get(route('billing.return', ['gateway' => 'bkash', 'invoice' => $this->invoice]).'?paymentID=PAY1&status=success')
        ->assertRedirectContains('payment=paid');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->meta['transaction_id'])->toBe('TRX9');
});

it('treats a canceled bKash payment as canceled', function () {
    $this->invoice->forceFill(['gateway' => 'bkash', 'gateway_reference' => 'PAY1', 'meta' => ['charge' => ['amount' => 353800, 'currency' => 'BDT']]])->save();
    Http::fake();

    $this->get(route('billing.return', ['gateway' => 'bkash', 'invoice' => $this->invoice]).'?paymentID=PAY1&status=cancel')
        ->assertRedirectContains('payment=canceled');

    Http::assertNothingSent();
    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Open);
});

it('ignores returns for a different gateway than the payment started with', function () {
    $this->invoice->forceFill(['gateway' => 'manual'])->save();
    Http::fake();

    $this->get(route('billing.return', ['gateway' => 'stripe', 'invoice' => $this->invoice]).'?session_id=cs_x')
        ->assertRedirectContains('payment=failed');

    Http::assertNothingSent();
});
