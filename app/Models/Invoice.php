<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billing period to pay for (a new plan, or a renewal). Amounts are in
 * minor units of `currency`. `meta.charge` records what the gateway was
 * asked to collect (it may be converted, e.g. USD → BDT for bKash).
 */
class Invoice extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'interval' => 'monthly',
        'plan_id' => null,
        'is_renewal' => false,
        'gateway' => null,
        'gateway_reference' => null,
        'period_start' => null,
        'period_end' => null,
        'due_at' => null,
        'paid_at' => null,
        'meta' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'interval' => BillingInterval::class,
            'amount' => 'integer',
            'is_renewal' => 'boolean',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function formattedAmount(): string
    {
        return Plan::formatMoney($this->amount, $this->currency);
    }

    public function isOpen(): bool
    {
        return $this->status === InvoiceStatus::Open;
    }
}
