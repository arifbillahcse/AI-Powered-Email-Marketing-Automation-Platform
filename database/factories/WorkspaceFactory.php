<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'timezone' => 'UTC',
        ];
    }

    public function withMailingAddress(): static
    {
        return $this->state(fn (): array => [
            'company_name' => fake()->company(),
            'address_line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
        ]);
    }

    /**
     * Attach a member with the given role after creating the workspace.
     */
    public function withMember(User $user, WorkspaceRole $role = WorkspaceRole::Owner): static
    {
        return $this->afterCreating(fn (Workspace $workspace) => $workspace->addMember($user, $role));
    }
}
