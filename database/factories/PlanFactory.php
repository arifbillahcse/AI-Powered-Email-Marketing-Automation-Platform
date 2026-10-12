<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Starter', 'Growth', 'Agency', 'Pro', 'Scale', 'Team', 'Basic', 'Plus']);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'currency' => 'USD',
            'price_monthly' => 2900,
            'price_yearly' => 29000,
            'trial_days' => 14,
            'limits' => ['mailboxes' => 3, 'leads' => 5000, 'emails_per_month' => 6000, 'team_members' => 2],
            'modules' => ['ai'],
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    public function unlimited(): static
    {
        return $this->state(fn (): array => ['limits' => null]);
    }
}
