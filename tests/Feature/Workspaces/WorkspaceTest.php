<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Tenancy\RegisterWorkspace;
use App\Filament\App\Tenancy\WorkspaceSettings;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('creates a workspace with the creator as owner', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    Livewire::test(RegisterWorkspace::class)
        ->fillForm(['name' => 'Softorio Agency', 'timezone' => 'Asia/Dhaka'])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::where('name', 'Softorio Agency')->sole();

    expect($workspace->timezone)->toBe('Asia/Dhaka')
        ->and($workspace->slug)->toStartWith('softorio-agency-')
        ->and($user->roleIn($workspace))->toBe(WorkspaceRole::Owner);
});

it('rejects unknown time zones', function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('app');

    Livewire::test(RegisterWorkspace::class)
        ->fillForm(['name' => 'Acme', 'timezone' => 'Mars/Olympus'])
        ->call('register')
        ->assertHasFormErrors(['timezone']);
});

it('gives every workspace a unique slug', function () {
    $a = Workspace::factory()->create(['name' => 'Acme']);
    $b = Workspace::factory()->create(['name' => 'Acme']);

    expect($a->slug)->not->toBe($b->slug);
});

it('isolates workspaces from non-members', function () {
    $outsider = User::factory()->create();
    Workspace::factory()->withMember($outsider)->create();
    $other = Workspace::factory()->withMember(User::factory()->create())->create();

    $this->actingAs($outsider)
        ->get("/app/{$other->slug}")
        ->assertNotFound();

    $this->actingAs($outsider)
        ->get("/app/{$other->slug}/team")
        ->assertNotFound();
});

it('lets a user switch between their workspaces', function () {
    $user = User::factory()->create();
    $first = Workspace::factory()->withMember($user)->create(['name' => 'Alpha']);
    $second = Workspace::factory()->withMember($user, WorkspaceRole::Member)->create(['name' => 'Beta']);

    $this->actingAs($user)->get("/app/{$first->slug}")->assertOk();
    $this->actingAs($user)->get("/app/{$second->slug}")->assertOk();

    expect($user->getTenants(Filament::getPanel('app'))->pluck('name')->all())->toBe(['Alpha', 'Beta']);
});

it('lets owners and admins update workspace settings', function (WorkspaceRole $role) {
    $workspace = actingInWorkspace(role: $role);

    Livewire::test(WorkspaceSettings::class)
        ->fillForm([
            'name' => 'Renamed',
            'timezone' => 'Europe/London',
            'company_name' => 'Hostorio Ltd',
            'address_line1' => 'House 12, Road 5',
            'city' => 'Dhaka',
            'postal_code' => '1207',
            'country' => 'bd',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $workspace->refresh();

    expect($workspace->name)->toBe('Renamed')
        ->and($workspace->country)->toBe('BD')
        ->and($workspace->hasMailingAddress())->toBeTrue()
        ->and($workspace->mailingAddress())->toBe('Hostorio Ltd, House 12, Road 5, Dhaka 1207, BD');
})->with([WorkspaceRole::Owner, WorkspaceRole::Admin]);

it('hides workspace settings from members and clients', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, $role)->create();

    $this->actingAs($user)
        ->get("/app/{$workspace->slug}/settings")
        ->assertNotFound();
})->with([WorkspaceRole::Member, WorkspaceRole::Client]);

it('requires a street, city and country for a mailing address', function () {
    $workspace = Workspace::factory()->create(['address_line1' => '1 Main St', 'city' => 'Dhaka']);

    expect($workspace->hasMailingAddress())->toBeFalse()
        ->and($workspace->mailingAddress())->toBeNull();
});
