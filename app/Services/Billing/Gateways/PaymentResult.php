<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;

final class PaymentResult
{
    public function __construct(
        public readonly bool $paid,
        public readonly ?Invoice $invoice,
        public readonly ?string $transactionId = null,
        public readonly string $message = '',
    ) {}

    public static function paid(Invoice $invoice, ?string $transactionId): self
    {
        return new self(true, $invoice, $transactionId, 'Payment received.');
    }

    public static function failed(?Invoice $invoice, string $message): self
    {
        return new self(false, $invoice, null, $message);
    }
}
