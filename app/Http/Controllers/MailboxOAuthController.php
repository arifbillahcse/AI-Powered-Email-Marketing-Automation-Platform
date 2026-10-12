<?php

namespace App\Http\Controllers;

use App\Enums\MailProvider;
use App\Filament\App\Resources\EmailAccounts\EmailAccountActions;
use App\Filament\App\Resources\EmailAccounts\EmailAccountResource;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Mail\MailboxConnectionException;
use App\Services\Mail\MailboxProvisioner;
use App\Services\Mail\OAuth\MailboxOAuth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Connect with Google / Microsoft 365": sends the user to the provider's
 * consent screen and stores the returned tokens on a mailbox. The state
 * parameter ties the callback to this session and workspace (CSRF).
 */
class MailboxOAuthController extends Controller
{
    protected const SESSION_KEY = 'mailbox_oauth';

    public function __construct(
        protected MailboxOAuth $oauth,
        protected MailboxProvisioner $provisioner,
    ) {}

    public function connect(Request $request, string $provider, Workspace $workspace): RedirectResponse
    {
        $this->authorizeWorkspace($request->user(), $workspace);
        abort_unless($this->oauth->configured($provider), 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'provider' => $provider, 'workspace_id' => $workspace->getKey()]);

        return redirect()->away($this->oauth->authorizeUrl($provider, $state, $request->query('email')));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($pending) || $pending['provider'] !== $provider || ! hash_equals($pending['state'], (string) $request->query('state'))) {
            abort(403, 'This sign-in link has expired. Start again from Email accounts.');
        }

        $workspace = Workspace::query()->findOrFail($pending['workspace_id']);
        $this->authorizeWorkspace($request->user(), $workspace);
        $back = EmailAccountResource::getUrl('index', panel: 'app', tenant: $workspace);

        if ($request->filled('error')) {
            Notification::make()->title('Not connected')->body('The sign-in was canceled or refused.')->warning()->send();

            return redirect()->to($back);
        }

        try {
            $tokens = $this->oauth->exchange($provider, (string) $request->query('code'));
            $account = $this->provisioner->connectOAuth($workspace, $provider === 'google' ? MailProvider::Google : MailProvider::Microsoft, $tokens);
        } catch (MailboxConnectionException|InvalidArgumentException $exception) {
            Notification::make()->title('Not connected')->body($exception->getMessage())->danger()->persistent()->send();

            return redirect()->to($back);
        }

        // Same check as the form: logs in to SMTP and IMAP and reports back.
        EmailAccountActions::runConnectionTest($account);

        return redirect()->to($back);
    }

    protected function authorizeWorkspace(?User $user, Workspace $workspace): void
    {
        abort_unless($user && $user->roleIn($workspace)?->canWrite(), 403);
    }
}
