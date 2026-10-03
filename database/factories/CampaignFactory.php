<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->catchPhrase(),
            'timezone' => 'UTC',
            'send_days' => [1, 2, 3, 4, 5],
            'send_window_start' => '09:00',
            'send_window_end' => '17:00',
            'daily_limit' => 100,
        ];
    }

    /**
     * A two-step sequence: an intro and a same-thread follow-up.
     */
    public function withSteps(): static
    {
        return $this->afterCreating(function (Campaign $campaign): void {
            $campaign->steps()->createMany([
                ['position' => 1, 'delay_days' => 0, 'subject' => 'Quick question, {{first_name|there}}', 'body' => '<p>{Hi|Hello} {{first_name|there}},</p><p>Saw {{company|your company}} is growing.</p>'],
                ['position' => 2, 'delay_days' => 3, 'subject' => null, 'body' => '<p>Just bumping this up, {{first_name|there}}.</p>'],
            ]);
        });
    }

    public function template(): static
    {
        return $this->state(fn (): array => ['is_template' => true]);
    }
}
