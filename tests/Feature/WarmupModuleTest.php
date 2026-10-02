<?php

use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->withMember($this->user)->create();
});

it('shows warmup as locked while the module is disabled', function () {
    config(['modules.warmup.enabled' => false]);

    $this->actingAs($this->user)
        ->get("/app/{$this->workspace->slug}/warmup")
        ->assertOk()
        ->assertSee('Inbox warmup is coming soon')
        ->assertSee('Soon');
});

it('unlocks warmup when the module is enabled', function () {
    config(['modules.warmup.enabled' => true]);

    $this->actingAs($this->user)
        ->get("/app/{$this->workspace->slug}/warmup")
        ->assertOk()
        ->assertDontSee('Inbox warmup is coming soon');
});
