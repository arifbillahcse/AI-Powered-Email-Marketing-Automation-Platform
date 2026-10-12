<?php

namespace App\Services\Mail\OAuth;

use App\Enums\MailProvider;
use App\Models\EmailAccount;
use App\Services\Mail\MailboxConnectionException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * OAuth 2.0 for Gmail / Google Workspace and Microsoft 365 mailboxes, used
 * for SMTP and IMAP with XOAUTH2 (no app passwords). Tokens are stored
 * encrypted on the mailbox and refreshed shortly before they expire.
 */
class MailboxOAuth
{
    public const PROVIDERS = ['google', 'microsoft'];

    public function configured(string $provider): bool
    {
        $config = $this->config($provider);

        return filled($config['client_id'] ?? null) && filled($config['client_secret'] ?? null);
    }

    public function label(string $provider): string
    {
        return $provider === 'google' ? 'Google' : 'Microsoft 365';
    }

    public function authorizeUrl(string $provider, string $state, ?string $loginHint = null): string
    {
        $config = $this->config($provider);
        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope' => $this->scope($provider),
            'state' => $state,
            'login_hint' => $loginHint,
        ];

        return match ($provider) {
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([...$params, 'access_type' => 'offline', 'prompt' => 'consent']),
            'microsoft' => 'https://login.microsoftonline.com/'.rawurlencode((string) $config['tenant']).'/oauth2/v2.0/authorize?'.http_build_query([...$params, 'response_mode' => 'query', 'prompt' => 'select_account']),
        };
    }

    /**
     * Exchange the authorization code for tokens and the mailbox address.
     *
     * @return array{email: string, name: ?string, access_token: string, refresh_token: ?string, expires_at: CarbonInterface}
     */
    public function exchange(string $provider, string $code): array
    {
        $tokens = $this->tokenRequest($provider, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri($provider),
        ]);

        // The ID token comes straight from the provider's token endpoint over
        // TLS, so its claims can be read without checking the signature.
        $claims = $this->claims((string) ($tokens['id_token'] ?? ''));
        $email = Str::lower((string) ($claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? ''));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MailboxConnectionException('The provider didn\'t tell us the mailbox\'s email address.');
        }

        return [
            'email' => $email,
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
            'access_token' => (string) $tokens['access_token'],
            'refresh_token' => isset($tokens['refresh_token']) ? (string) $tokens['refresh_token'] : null,
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ];
    }

    /**
     * A valid access token for the mailbox, refreshed when it's (nearly) expired.
     *
     * @throws MailboxConnectionException
     */
    public function accessToken(EmailAccount $account): string
    {
        if (filled($account->oauth_access_token) && $account->oauth_expires_at?->isAfter(now()->addMinutes(5))) {
            return (string) $account->oauth_access_token;
        }

        if (blank($account->oauth_refresh_token)) {
            throw new MailboxConnectionException('The mailbox needs to be reconnected (no refresh token). Use "Reconnect".');
        }

        $provider = $account->provider === MailProvider::Microsoft ? 'microsoft' : 'google';
        $tokens = $this->tokenRequest($provider, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->oauth_refresh_token,
        ]);

        $account->forceFill([
            'oauth_access_token' => (string) $tokens['access_token'],
            // Microsoft rotates refresh tokens; Google keeps the old one.
            'oauth_refresh_token' => $tokens['refresh_token'] ?? $account->oauth_refresh_token,
            'oauth_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ])->save();

        return (string) $account->oauth_access_token;
    }

    public function redirectUri(string $provider): string
    {
        return route('mailboxes.oauth.callback', ['provider' => $provider]);
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    protected function tokenRequest(string $provider, array $params): array
    {
        $config = $this->config($provider);

        $url = match ($provider) {
            'google' => 'https://oauth2.googleapis.com/token',
            'microsoft' => 'https://login.microsoftonline.com/'.rawurlencode((string) $config['tenant']).'/oauth2/v2.0/token',
        };

        $response = Http::asForm()->timeout(20)->post($url, [
            ...$params,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            ...($provider === 'microsoft' ? ['scope' => $this->scope($provider)] : []),
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'the provider refused the request';

            throw new MailboxConnectionException('OAuth: '.Str::limit((string) $error, 200));
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    protected function claims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1] ?? '';
        $json = base64_decode(strtr($payload, '-_', '+/'), true);

        return is_string($json) ? (json_decode($json, true) ?: []) : [];
    }

    protected function scope(string $provider): string
    {
        return match ($provider) {
            'google' => 'openid email profile https://mail.google.com/',
            'microsoft' => 'openid email profile offline_access https://outlook.office.com/SMTP.Send https://outlook.office.com/IMAP.AccessAsUser.All',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(string $provider): array
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException("Unknown OAuth provider [{$provider}].");
        }

        return (array) config($provider === 'google' ? 'services.google_mail' : 'services.microsoft_mail');
    }
}
