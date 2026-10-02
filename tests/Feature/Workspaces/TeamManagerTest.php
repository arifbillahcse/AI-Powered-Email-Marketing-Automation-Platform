<?php

use App\Enums\WorkspaceRole;
use App\Exceptions\TeamActionException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Services\Workspaces\TeamManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->team = app(TeamManager::class);
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->withMember($this->owner)->create();
});

function membershipOf(Workspace $workspace, User $user)
{
    return $workspace->memberships()->where('user_id', $user->id)->sole();
}

it('invites by email with a hashed, expiring token', function () {
    $invitation = $this->team->invite($this->workspace, $this->owner, ' New@Example.com ', WorkspaceRole::Admin);

    expect($invitation->email)->toBe('new@example.com')
        ->and($invitation->role)->toBe(WorkspaceRole::Admin)
        ->and($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    Notification::assertSentOnDemand(
        WorkspaceInvitationNotification::class,
        function (WorkspaceInvitationNotification $notification, array $channels, object $notifiable) use ($invitation) {
            return $notifiable->routes['mail'] === 'new@example.com'
                && WorkspaceInvitation::findByToken($notification->token)?->is($invitation);
        },
    );
});

it('replaces an earlier pending invitation for the same email', function () {
    $this->team->invite($this->workspace, $this->owner, 'new@example.com', WorkspaceRole::Member);
    $this->team->invite($this->workspace, $this->owner, 'new@example.com', WorkspaceRole::Client);

    expect($this->workspace->invitations()->count())->toBe(1)
        ->and($this->workspace->invitations()->sole()->role)->toBe(WorkspaceRole::Client);
});

it('does not invite existing members', function () {
    $member = User::factory()->create(['email' => 'member@example.com']);
    $this->workspace->addMember($member, WorkspaceRole::Member);

    $this->team->invite($this->workspace, $this->owner, 'MEMBER@example.com', WorkspaceRole::Member);
})->throws(TeamActionException::class, 'already a member');

it('never hands out ownership', function () {
    $this->team->invite($this->workspace, $this->owner, 'new@example.com', WorkspaceRole::Owner);
})->throws(TeamActionException::class);

it('only lets owners and admins invite', function (WorkspaceRole $role) {
    $user = User::factory()->create();
    $this->workspace->addMember($user, $role);

    $this->team->invite($this->workspace, $user, 'new@example.com', WorkspaceRole::Member);
})->with([WorkspaceRole::Member, WorkspaceRole::Client])->throws(AuthorizationException::class);

it('lets admins invite', function () {
    $admin = User::factory()->create();
    $this->workspace->addMember($admin, WorkspaceRole::Admin);

    expect($this->team->invite($this->workspace, $admin, 'new@example.com', WorkspaceRole::Member))
        ->toBeInstanceOf(WorkspaceInvitation::class);
});

it('changes roles but never the owner\'s or your own', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $this->workspace->addMember($admin, WorkspaceRole::Admin);
    $this->workspace->addMember($member, WorkspaceRole::Member);

    $this->team->changeRole(membershipOf($this->workspace, $member), WorkspaceRole::Client, $admin);
    expect($member->roleIn($this->workspace))->toBe(WorkspaceRole::Client);

    expect(fn () => $this->team->changeRole(membershipOf($this->workspace, $this->owner), WorkspaceRole::Member, $admin))
        ->toThrow(TeamActionException::class);

    expect(fn () => $this->team->changeRole(membershipOf($this->workspace, $admin), WorkspaceRole::Member, $admin))
        ->toThrow(TeamActionException::class);
});

it('removes members but never the owner', function () {
    $member = User::factory()->create();
    $this->workspace->addMember($member, WorkspaceRole::Member);

    $this->team->remove(membershipOf($this->workspace, $member), $this->owner);
    expect($this->workspace->hasMember($member))->toBeFalse();

    $admin = User::factory()->create();
    $this->workspace->addMember($admin, WorkspaceRole::Admin);

    expect(fn () => $this->team->remove(membershipOf($this->workspace, $this->owner), $admin))
        ->toThrow(TeamActionException::class);
});

it('lets members leave but not the owner', function () {
    $member = User::factory()->create();
    $this->workspace->addMember($member, WorkspaceRole::Member);

    $this->team->leave($this->workspace, $member);
    expect($this->workspace->hasMember($member))->toBeFalse();

    expect(fn () => $this->team->leave($this->workspace, $this->owner))
        ->toThrow(TeamActionException::class);
});

it('resends with a fresh token and revokes', function () {
    $invitation = $this->team->invite($this->workspace, $this->owner, 'new@example.com', WorkspaceRole::Member);
    $oldHash = $invitation->token_hash;

    $this->team->resend($invitation, $this->owner);
    expect($invitation->refresh()->token_hash)->not->toBe($oldHash);
    Notification::assertSentOnDemandTimes(WorkspaceInvitationNotification::class, 2);

    $this->team->revoke($invitation, $this->owner);
    expect(WorkspaceInvitation::count())->toBe(0);
});
