<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Create a workspace with the given user as a member and sign them in
 * to it, ready for Livewire tests of tenant pages.
 */
function actingInWorkspace(?User $user = null, WorkspaceRole $role = WorkspaceRole::Owner, ?Workspace $workspace = null): Workspace
{
    $user ??= User::factory()->create();
    $workspace ??= Workspace::factory()->create();
    $workspace->addMember($user, $role);

    test()->actingAs($user);
    Filament::setCurrentPanel('app');
    Filament::setTenant($workspace);
    // Real requests boot the panel in middleware; this registers the tenancy
    // scopes and observers that attach new records to the workspace.
    Filament::bootCurrentPanel();

    return $workspace;
}
