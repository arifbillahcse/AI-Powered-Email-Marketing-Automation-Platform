<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed local development data. Never run in production.
     */
    public function run(): void
    {
        User::factory()->superAdmin()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
        ]);

        User::factory()->create([
            'name' => 'Demo Customer',
            'email' => 'demo@example.com',
        ]);
    }
}
