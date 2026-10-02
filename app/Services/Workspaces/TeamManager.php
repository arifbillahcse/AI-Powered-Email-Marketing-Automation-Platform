<?php

namespace App\Services\Workspaces;

use App\Enums\WorkspaceRole;
use App\Exceptions\TeamActionException;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * All team membership rules live here so the UI, API (Phase 13) and tests
 * share one implementation.
 */
class TeamManager
{
    public function invite(Workspace $workspace, User $actor, string $email, WorkspaceRole $role): WorkspaceInvitation
    {
        Gate::forUser($actor)->authorize('manageTeam', $workspace);

        $email = Str::lower(trim($email));
        $this->guardAssignable($role);

        if ($workspace->members()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw new TeamActionException("{$email} is already a member of this workspace.");
        }

        return DB::transaction(function () use ($workspace, $actor, $email, $role): WorkspaceInvitation {
            // One live invitation per email: re-inviting replaces the old link.
            $workspace->invitations()->where('email', $email)->whereNull('accepted_at')->delete();

            $invitation = $workspace->invitations()->make([
                'email' => $email,
                'role' => $role,
            ]);
            $invitation->inviter()->associate($actor);
            $token = $invitation->issueToken();
            $invitation->save();

            $this->send($invitation, $token);

            return $invitation;
        });
    }

    public function resend(WorkspaceInvitation $invitation, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageTeam', $invitation->workspace);

        if ($invitation->isAccepted()) {
            throw new TeamActionException('This invitation has already been accepted.');
        }

        $token = $invitation->issueToken();
        $invitation->save();

        $this->send($invitation, $token);
    }

    public function revoke(WorkspaceInvitation $invitation, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageTeam', $invitation->workspace);

        $invitation->delete();
    }

    public function changeRole(Membership $membership, WorkspaceRole $role, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageTeam', $membership->workspace);

        $this->guardAssignable($role);
        $this->guardNotOwner($membership, 'The owner\'s role can\'t be changed.');

        if ($membership->user_id === $actor->getKey()) {
            throw new TeamActionException('You can\'t change your own role.');
        }

        $membership->update(['role' => $role]);
    }

    public function remove(Membership $membership, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageTeam', $membership->workspace);

        $this->guardNotOwner($membership, 'The workspace owner can\'t be removed.');

        if ($membership->user_id === $actor->getKey()) {
            throw new TeamActionException('Use "Leave workspace" to remove yourself.');
        }

        $membership->delete();
    }

    public function leave(Workspace $workspace, User $user): void
    {
        $membership = $workspace->memberships()->where('user_id', $user->getKey())->first();

        if (! $membership) {
            return;
        }

        $this->guardNotOwner($membership, 'The owner can\'t leave the workspace.');

        $membership->delete();
    }

    /**
     * Join the workspace from an invitation. The caller must have checked
     * that the invitation is pending and addressed to this user.
     */
    public function accept(WorkspaceInvitation $invitation, User $user): Workspace
    {
        if (! $invitation->isFor($user)) {
            throw new TeamActionException('This invitation was sent to a different email address.');
        }

        if ($invitation->isAccepted() || $invitation->isExpired()) {
            throw new TeamActionException('This invitation is no longer valid.');
        }

        return DB::transaction(function () use ($invitation, $user): Workspace {
            $workspace = $invitation->workspace;

            // Clicking the emailed link proves the user owns this address.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            if (! $workspace->hasMember($user)) {
                $workspace->addMember($user, $invitation->role);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $workspace;
        });
    }

    protected function send(WorkspaceInvitation $invitation, string $token): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new WorkspaceInvitationNotification($invitation, $token));
    }

    protected function guardAssignable(WorkspaceRole $role): void
    {
        if ($role === WorkspaceRole::Owner) {
            throw new TeamActionException('Ownership can\'t be assigned this way.');
        }
    }

    protected function guardNotOwner(Membership $membership, string $message): void
    {
        if ($membership->isOwner()) {
            throw new TeamActionException($message);
        }
    }
}
