<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Widgets\OnboardingChecklist;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\Lead;
use Livewire\Livewire;

it('walks a new workspace through the setup steps', function () {
    $workspace = actingInWorkspace();
    $workspace->forceFill(['address_line1' => null, 'city' => null, 'country' => null])->save();

    $done = fn (): array => collect(OnboardingChecklist::steps($workspace->refresh()))->pluck('done', 'key')->all();

    expect($done())->toBe(['mailbox' => false, 'dns' => false, 'address' => false, 'warmup' => false, 'leads' => false, 'campaign' => false, 'launch' => false])
        ->and(OnboardingChecklist::canView())->toBeTrue();

    EmailAccount::factory()->for($workspace)->create(['warmup_enabled' => true]);
    Lead::factory()->for($workspace)->create();
    Campaign::factory()->for($workspace)->create();

    expect($done())->toMatchArray(['mailbox' => true, 'warmup' => true, 'leads' => true, 'campaign' => true, 'launch' => false]);
});

it('shows on the dashboard and can be hidden', function () {
    $workspace = actingInWorkspace();

    $this->get("/app/{$workspace->slug}")->assertOk()->assertSee('Get started');

    Livewire::test(OnboardingChecklist::class)->call('dismiss');

    expect($workspace->refresh()->onboarding_dismissed_at)->not->toBeNull()
        ->and(OnboardingChecklist::canView())->toBeFalse();

    $this->get("/app/{$workspace->slug}")->assertOk()->assertDontSee('Get started');
});

it('is not shown to clients', function () {
    actingInWorkspace(role: WorkspaceRole::Client);

    expect(OnboardingChecklist::canView())->toBeFalse();
});
