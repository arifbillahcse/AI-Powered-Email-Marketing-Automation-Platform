<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Auth\Register;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Services\Workspaces\TeamManager;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->withMember($this->owner)->create();
});

function inviteTo(Workspace $workspace, User $owner, string $email, WorkspaceRole $role = WorkspaceRole::Member): string
{
    $token = null;
    app(TeamManager::class)->invite($workspace, $owner, $email, $role);

    Notification::assertSentOnDemand(
        WorkspaceInvitationNotification::class,
        function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        },
    );

    return $token;
}

it('adds a signed-in invitee with the invited role', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $token = inviteTo($this->workspace, $this->owner, 'invitee@example.com', WorkspaceRole::Client);

    $this->actingAs($invitee)
        ->get(route('invitations.accept', $token))
        ->assertRedirect("/app/{$this->workspace->slug}");

    expect($invitee->roleIn($this->workspace))->toBe(WorkspaceRole::Client)
        ->and(WorkspaceInvitation::sole()->isAccepted())->toBeTrue();
});

it('verifies the invitee\'s email on accept', function () {
    $invitee = User::factory()->unverified()->create(['email' => 'invitee@example.com']);
    $token = inviteTo($this->workspace, $this->owner, 'invitee@example.com');

    $this->actingAs($invitee)->get(route('invitations.accept', $token));

    expect($invitee->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('refuses invitations addressed to someone else', function () {
    $token = inviteTo($this->workspace, $this->owner, 'invitee@example.com');

    $this->actingAs(User::factory()->create(['email' => 'intruder@example.com']))
        ->get(route('invitations.accept', $token))
        ->assertForbidden();

    expect($this->workspace->members()->count())->toBe(1);
});

it('sends guests to sign up, then back to the invitation', function () {
    $token = inviteTo($this->workspace, $this->owner, 'newbie@example.com');

    $this->get(route('invitations.accept', $token))
        ->assertRedirect('/app/register')
        ->assertSessionHas(Register::INVITATION_EMAIL_SESSION_KEY, 'newbie@example.com')
        ->assertSessionHas('url.intended', route('invitations.accept', $token));
});

it('sends guests with an account to log in', function () {
    User::factory()->create(['email' => 'existing@example.com']);
    $token = inviteTo($this->workspace, $this->owner, 'existing@example.com');

    $this->get(route('invitations.accept', $token))->assertRedirect('/app/login');
});

it('rejects unknown, used and expired links', function () {
    $this->get(route('invitations.accept', 'not-a-real-token'))->assertNotFound();

    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $token = inviteTo($this->workspace, $this->owner, 'invitee@example.com');
    $this->actingAs($invitee)->get(route('invitations.accept', $token))->assertRedirect();
    $this->actingAs($invitee)->get(route('invitations.accept', $token))->assertNotFound();

    WorkspaceInvitation::query()->delete();
    $token = inviteTo($this->workspace, $this->owner, 'late@example.com');
    WorkspaceInvitation::query()->update(['expires_at' => now()->subMinute()]);
    $this->get(route('invitations.accept', $token))->assertStatus(410);
});
