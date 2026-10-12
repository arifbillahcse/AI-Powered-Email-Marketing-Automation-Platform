<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Filament\App\Pages\Billing;
use App\Models\Invoice;
use App\Models\Workspace;
use App\Services\Billing\Gateways\GatewayException;
use App\Services\Billing\Gateways\GatewayManager;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\Gateways\PaymentResult;
use App\Services\Billing\SubscriptionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Where payment gateways send the customer back (return) and notify us
 * (webhook / IPN). No session or CSRF: gateways POST cross-site. Nothing
 * from the request is trusted; each gateway re-checks the payment with its
 * own API (or a webhook signature) before an invoice is marked paid.
 */
class BillingCallbackController extends Controller
{
    public function __construct(
        protected GatewayManager $gateways,
        protected SubscriptionManager $subscriptions,
    ) {}

    public function return(Request $request, string $gateway, Invoice $invoice): RedirectResponse
    {
        $result = $this->resolve($gateway, $invoice, fn ($handler) => $handler->handleReturn($invoice, $request));

        if ($result?->paid && $result->invoice) {
            $this->subscriptions->markPaid($result->invoice, $gateway, $result->transactionId);
        }

        $outcome = match (true) {
            $result?->paid => 'paid',
            str_contains((string) $result?->message, 'canceled') => 'canceled',
            default => 'failed',
        };

        return redirect()->to($this->billingUrl($invoice, $outcome));
    }

    public function webhook(Request $request, string $gateway): JsonResponse
    {
        try {
            $result = $this->gateways->get($gateway)->handleWebhook($request);
        } catch (InvalidArgumentException) {
            abort(404);
        } catch (GatewayException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }

        if ($result?->paid && $result->invoice) {
            $this->subscriptions->markPaid($result->invoice, $gateway, $result->transactionId);
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param  callable(PaymentGateway): PaymentResult  $handle
     */
    protected function resolve(string $gateway, Invoice $invoice, callable $handle): ?PaymentResult
    {
        try {
            $handler = $this->gateways->get($gateway);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        // Already settled (e.g. the webhook arrived first).
        if (! $invoice->isOpen()) {
            return new PaymentResult($invoice->status === InvoiceStatus::Paid, $invoice);
        }

        // The payment must have been started with this gateway.
        if ($invoice->gateway !== $gateway) {
            return PaymentResult::failed($invoice, 'Unknown payment.');
        }

        try {
            return $handle($handler);
        } catch (GatewayException $exception) {
            Log::warning('Payment confirmation failed', ['gateway' => $gateway, 'invoice' => $invoice->getKey(), 'error' => $exception->getMessage()]);

            return PaymentResult::failed($invoice, 'The payment could not be confirmed.');
        }
    }

    protected function billingUrl(Invoice $invoice, string $outcome): string
    {
        $workspace = Workspace::query()->findOrFail($invoice->workspace_id);

        return Billing::getUrl(['payment' => $outcome, 'invoice' => $invoice->number], panel: 'app', tenant: $workspace);
    }
}
