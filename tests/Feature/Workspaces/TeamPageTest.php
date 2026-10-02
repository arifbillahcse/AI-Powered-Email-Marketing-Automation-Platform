<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Pages\Team;
use App\Filament\App\Widgets\PendingInvitations;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('lists only this workspace\'s members', function () {
    $workspace = actingInWorkspace();
    $teammate = User::factory()->create();
    $workspace->addMember($teammate, WorkspaceRole::Member);

    $stranger = User::factory()->create();
    Workspace::factory()->withMember($stranger)->create();

    Livewire::test(Team::class)
        ->assertOk()
        ->assertCanSeeTableRecords($workspace->memberships()->get())
        ->assertSee($teammate->email)
        ->assertDontSee($stranger->email);
});

it('lets admins invite from the page', function () {
    Notification::fake();
    actingInWorkspace(role: WorkspaceRole::Admin);

    Livewire::test(Team::class)
        ->callAction('invite', data: ['email' => 'new@example.com', 'role' => 'client'])
        ->assertHasNoActionErrors()
        ->assertNotified('Invitation sent to new@example.com');

    expect(WorkspaceInvitation::sole()->role)->toBe(WorkspaceRole::Client);
    Notification::assertSentOnDemandTimes(WorkspaceInvitationNotification::class, 1);
});

it('hides team management from members and clients', function (WorkspaceRole $role) {
    $workspace = actingInWorkspace(role: $role);
    $other = User::factory()->create();
    $workspace->addMember($other, WorkspaceRole::Member);
    $membership = $workspace->memberships()->where('user_id', $other->id)->sole();

    Livewire::test(Team::class)
        ->assertActionHidden('invite')
        ->assertActionHidden(TestAction::make('remove')->table($membership))
        ->assertActionHidden(TestAction::make('changeRole')->table($membership))
        ->assertActionVisible('leave');

    expect(PendingInvitations::canView())->toBeFalse();
})->with([WorkspaceRole::Member, WorkspaceRole::Client]);

it('never offers to remove the owner or leave as owner', function () {
    $owner = User::factory()->create();
    $workspace = actingInWorkspace($owner);
    $membership = $workspace->memberships()->where('user_id', $owner->id)->sole();

    Livewire::test(Team::class)
        ->assertActionHidden(TestAction::make('remove')->table($membership))
        ->assertActionHidden('leave');
});

it('removes a member from the table', function () {
    $workspace = actingInWorkspace();
    $member = User::factory()->create();
    $workspace->addMember($member, WorkspaceRole::Member);
    $membership = $workspace->memberships()->where('user_id', $member->id)->sole();

    Livewire::test(Team::class)
        ->callAction(TestAction::make('remove')->table($membership))
        ->assertNotified('Member removed');

    expect($workspace->hasMember($member))->toBeFalse();
});

it('shows and revokes pending invitations', function () {
    $workspace = actingInWorkspace();
    $pending = WorkspaceInvitation::factory()->for($workspace)->create();
    $expired = WorkspaceInvitation::factory()->for($workspace)->expired()->create();

    Livewire::test(PendingInvitations::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$expired])
        ->callAction(TestAction::make('revoke')->table($pending))
        ->assertNotified('Invitation revoked');

    expect(WorkspaceInvitation::find($pending->id))->toBeNull();
});
