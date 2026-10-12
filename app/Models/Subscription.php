<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's plan and paid-up period (one per workspace).
 */
class Subscription extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'interval' => 'monthly',
        'trial_ends_at' => null,
        'current_period_start' => null,
        'current_period_end' => null,
        'cancel_at_period_end' => false,
        'canceled_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'interval' => BillingInterval::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
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

    /**
     * Trial, active, or within the grace period after a missed payment.
     */
    public function isUsable(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Trialing => $this->trial_ends_at === null || $this->trial_ends_at->isFuture(),
            SubscriptionStatus::Active, SubscriptionStatus::PastDue => true,
            SubscriptionStatus::Expired => false,
        };
    }
}
