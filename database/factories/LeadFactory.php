<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'workspace_id' => Workspace::factory(),
            'email' => strtolower($first.'.'.$last.'.'.fake()->unique()->numberBetween(1, 999999)).'@'.fake()->domainName(),
            'first_name' => $first,
            'last_name' => $last,
            'company' => fake()->company(),
            'title' => fake()->jobTitle(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'status' => LeadStatus::New,
        ];
    }
}
