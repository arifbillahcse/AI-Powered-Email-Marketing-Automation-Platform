<?php

namespace Database\Factories;

use App\Models\Segment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->words(2, true),
            'match' => 'all',
            'rules' => [['field' => 'status', 'operator' => 'equals', 'value' => 'new']],
        ];
    }
}
