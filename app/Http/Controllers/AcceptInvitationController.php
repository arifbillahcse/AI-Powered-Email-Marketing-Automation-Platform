<?php

namespace App\Http\Controllers;

use App\Exceptions\TeamActionException;
use App\Filament\App\Auth\Register;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\Workspaces\TeamManager;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AcceptInvitationController extends Controller
{
    public function __invoke(Request $request, string $token, TeamManager $team): RedirectResponse
    {
        $invitation = WorkspaceInvitation::findByToken($token);

        abort_if($invitation === null || $invitation->isAccepted(), 404, 'This invitation link is invalid or was already used.');
        abort_if($invitation->isExpired(), 410, 'This invitation has expired. Ask a workspace admin to send a new one.');

        $panel = Filament::getPanel('app');
        $user = $panel->auth()->user();

        if (! $user) {
            // Come back here after signing in or signing up.
            $request->session()->put(Register::INVITATION_EMAIL_SESSION_KEY, $invitation->email);

            $hasAccount = User::whereRaw('lower(email) = ?', [strtolower($invitation->email)])->exists();

            return redirect()->guest($hasAccount ? $panel->getLoginUrl() : $panel->getRegistrationUrl());
        }

        try {
            $workspace = $team->accept($invitation, $user);
        } catch (TeamActionException $exception) {
            abort(403, $exception->getMessage());
        }

        $request->session()->forget(Register::INVITATION_EMAIL_SESSION_KEY);

        return redirect($panel->getUrl($workspace));
    }
}
