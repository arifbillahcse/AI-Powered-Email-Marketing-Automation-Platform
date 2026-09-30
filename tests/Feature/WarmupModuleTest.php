<?php

use App\Models\User;

it('shows warmup as locked while the module is disabled', function () {
    config(['modules.warmup.enabled' => false]);

    $this->actingAs(User::factory()->create())
        ->get('/app/warmup')
        ->assertOk()
        ->assertSee('Inbox warmup is coming soon')
        ->assertSee('Soon');
});

it('unlocks warmup when the module is enabled', function () {
    config(['modules.warmup.enabled' => true]);

    $this->actingAs(User::factory()->create())
        ->get('/app/warmup')
        ->assertOk()
        ->assertDontSee('Inbox warmup is coming soon');
});
