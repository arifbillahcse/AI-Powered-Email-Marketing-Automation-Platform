<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;

/**
 * A price list entry, managed by super admins. Limits are null for
 * unlimited; modules are the module keys (config/modules.php) included.
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    public const LIMITS = [
        'mailboxes' => 'Mailboxes',
        'leads' => 'Leads',
        'emails_per_month' => 'Emails per month',
        'team_members' => 'Team members',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name', 'slug', 'description', 'currency', 'price_monthly', 'price_yearly',
        'trial_days', 'limits', 'modules', 'is_active', 'is_default', 'sort',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'description' => null,
        'currency' => 'USD',
        'price_monthly' => 0,
        'price_yearly' => 0,
        'trial_days' => 14,
        'limits' => null,
        'modules' => null,
        'is_active' => true,
        'is_default' => false,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'trial_days' => 'integer',
            'limits' => 'array',
            'modules' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // One default plan at a time.
        static::saved(function (Plan $plan): void {
            if ($plan->is_default) {
                static::query()->whereKeyNot($plan->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function price(BillingInterval $interval): int
    {
        return $interval === BillingInterval::Yearly ? $this->price_yearly : $this->price_monthly;
    }

    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function includesModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }

    public static function formatMoney(int $minor, string $currency): string
    {
        return (string) Number::currency($minor / 100, $currency);
    }
}
