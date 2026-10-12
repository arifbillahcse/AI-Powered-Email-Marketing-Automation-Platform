<?php

use App\Enums\MailEncryption;
use App\Enums\MailProvider;
use App\Filament\App\Resources\EmailAccounts\Pages\EditEmailAccount;
use App\Filament\App\Resources\EmailAccounts\Pages\ListEmailAccounts;
use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Mail\ConnectionTestResult;
use App\Services\Mail\HostGuard;
use App\Services\Mail\Imap\ImapClient;
use App\Services\Mail\MailboxConnectionTester;
use App\Services\Mail\MailboxTransportFactory;
use App\Services\Mail\OAuth\MailboxOAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fakes\FakeMailboxConnectionTester;
use Tests\Fakes\ScriptedImapStream;

beforeEach(function () {
    config([
        'services.google_mail' => ['client_id' => 'google-client', 'client_secret' => 'google-secret'],
        'services.microsoft_mail' => ['client_id' => 'ms-client', 'client_secret' => 'ms-secret', 'tenant' => 'common'],
    ]);
    $this->tester = new FakeMailboxConnectionTester(new ConnectionTestResult);
    app()->instance(MailboxConnectionTester::class, $this->tester);
});

function idToken(array $claims): string
{
    $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $encode(['alg' => 'RS256']).'.'.$encode($claims).'.signature';
}

it('offers Connect Google and Microsoft 365 when configured', function () {
    actingInWorkspace();

    Livewire::test(ListEmailAccounts::class)
        ->assertActionVisible('connectGoogle')
        ->assertActionVisible('connectMicrosoft');

    config(['services.microsoft_mail.client_id' => null]);

    Livewire::test(ListEmailAccounts::class)->assertActionHidden('connectMicrosoft');
});

it('connects a Google mailbox with OAuth', function () {
    $workspace = actingInWorkspace();
    Http::fake(['oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'ya29.access',
        'refresh_token' => '1//refresh',
        'expires_in' => 3599,
        'id_token' => idToken(['email' => 'Arif@Softorio.com', 'name' => 'Arif Billah']),
    ])]);

    $redirect = $this->get(route('mailboxes.oauth.connect', ['provider' => 'google', 'workspace' => $workspace]));
    $location = $redirect->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['scope'])->toContain('https://mail.google.com/')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['redirect_uri'])->toBe(route('mailboxes.oauth.callback', ['provider' => 'google']));

    $this->get(route('mailboxes.oauth.callback', ['provider' => 'google', 'code' => 'auth-code', 'state' => $query['state']]))
        ->assertRedirect();

    $account = EmailAccount::sole();

    expect($account->email)->toBe('arif@softorio.com')
        ->and($account->from_name)->toBe('Arif Billah')
        ->and($account->usesOAuth())->toBeTrue()
        ->and($account->provider)->toBe(MailProvider::Google)
        ->and($account->smtp_host)->toBe('smtp.gmail.com')
        ->and($account->oauth_access_token)->toBe('ya29.access')
        ->and($account->smtp_password)->toBeNull()
        ->and(DB::table('email_accounts')->value('oauth_refresh_token'))->not->toContain('1//refresh') // encrypted
        ->and($this->tester->tested)->toBe(['arif@softorio.com']);

    Http::assertSent(fn (Request $request): bool => $request['code'] === 'auth-code' && $request['client_secret'] === 'google-secret');
});

it('rejects a callback whose state does not match the session', function () {
    $workspace = actingInWorkspace();
    $this->get(route('mailboxes.oauth.connect', ['provider' => 'google', 'workspace' => $workspace]));

    $this->get(route('mailboxes.oauth.callback', ['provider' => 'google', 'code' => 'x', 'state' => 'forged']))
        ->assertForbidden();

    expect(EmailAccount::count())->toBe(0);
});

it('only lets workspace members start a connection', function () {
    $workspace = actingInWorkspace();
    $this->actingAs(User::factory()->create());

    $this->get(route('mailboxes.oauth.connect', ['provider' => 'google', 'workspace' => $workspace]))->assertForbidden();
});

it('refreshes expired access tokens', function () {
    $workspace = actingInWorkspace();
    $account = EmailAccount::factory()->for($workspace)->create(['provider' => MailProvider::Microsoft]);
    $account->forceFill(['auth_type' => 'oauth', 'oauth_access_token' => 'old', 'oauth_refresh_token' => 'refresh-1', 'oauth_expires_at' => now()->subMinute()])->save();
    Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'refresh-2', 'expires_in' => 3600])]);

    expect(app(MailboxOAuth::class)->accessToken($account))->toBe('new-token')
        ->and($account->refresh()->oauth_refresh_token)->toBe('refresh-2')
        // Still fresh: no second request.
        ->and(app(MailboxOAuth::class)->accessToken($account))->toBe('new-token');

    Http::assertSentCount(1);
});

it('signs in to SMTP and IMAP with XOAUTH2', function () {
    $workspace = actingInWorkspace();
    $account = EmailAccount::factory()->for($workspace)->create(['email' => 'arif@softorio.com', 'provider' => MailProvider::Google, 'smtp_host' => 'smtp.gmail.com']);
    $account->forceFill(['auth_type' => 'oauth', 'oauth_access_token' => 'token-123', 'oauth_expires_at' => now()->addHour()])->save();

    $this->mock(HostGuard::class)->shouldReceive('assertAllowed'); // no DNS in tests
    $transport = app(MailboxTransportFactory::class)->make($account);
    expect($transport->getPassword())->toBe('token-123');

    $stream = new ScriptedImapStream(['* OK Gimap ready', 'A001 OK Thats all she wrote']);
    (new ImapClient($stream))->loginWithToken(MailEncryption::Ssl, 'arif@softorio.com', 'token-123');

    expect($stream->written[0])->toBe('A001 AUTHENTICATE XOAUTH2 '.base64_encode("user=arif@softorio.com\x01auth=Bearer token-123\x01\x01"));
});

it('hides passwords and servers for OAuth mailboxes', function () {
    $workspace = actingInWorkspace();
    $account = EmailAccount::factory()->for($workspace)->create(['provider' => MailProvider::Google]);
    $account->forceFill(['auth_type' => 'oauth'])->save();

    Livewire::test(EditEmailAccount::class, ['record' => $account->getRouteKey()])
        ->assertSee('Connected with Google (OAuth)')
        ->assertDontSee('SMTP host');
});
