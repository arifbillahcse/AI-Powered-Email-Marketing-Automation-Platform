<?php

namespace App\Services\Integrations\Drivers;

use App\Models\Integration;
use App\Services\Integrations\IntegrationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Appends one row per event to a Google Sheet, as a service account (share
 * the sheet with the service account's email as an editor).
 */
class GoogleSheetsDriver extends BaseDriver
{
    public function deliver(Integration $integration, string $event, array $payload): ?int
    {
        $sheetId = (string) $integration->setting('spreadsheet_id');
        $range = (string) ($integration->setting('sheet_name') ?: 'Sheet1');

        if (! preg_match('/^[A-Za-z0-9_-]{20,}$/', $sheetId)) {
            throw new IntegrationException('Paste the spreadsheet ID (the long part of the sheet\'s URL).', retryable: false);
        }

        $lead = $payload['lead'] ?? [];
        $row = [
            now()->toDateTimeString(),
            $event,
            $lead['email'] ?? '',
            trim(($lead['first_name'] ?? '').' '.($lead['last_name'] ?? '')),
            $lead['company'] ?? '',
            $payload['campaign']['name'] ?? '',
            $payload['reply']['category'] ?? '',
            $payload['reply']['summary'] ?? ($payload['reply']['text'] ?? ''),
        ];

        $url = 'https://sheets.googleapis.com/v4/spreadsheets/'.$sheetId.'/values/'.rawurlencode($range).':append?'
            .http_build_query(['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS']);

        return $this->send(fn () => $this->http()->withToken($this->accessToken($integration))->post($url, ['values' => [$row]]))->status();
    }

    /**
     * OAuth access token for the service account (JWT bearer grant), cached
     * for 50 minutes.
     */
    protected function accessToken(Integration $integration): string
    {
        $account = json_decode((string) $integration->setting('service_account'), true);

        if (! is_array($account) || blank($account['client_email'] ?? null) || blank($account['private_key'] ?? null)) {
            throw new IntegrationException('Paste the service account\'s JSON key.', retryable: false);
        }

        return Cache::remember('integrations:google:'.$integration->getKey().':'.md5($account['client_email']), now()->addMinutes(50), function () use ($account): string {
            $now = time();
            $encode = fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
            $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/spreadsheets',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

            if (! openssl_sign($unsigned, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new IntegrationException('The service account\'s private key can\'t be read.', retryable: false);
            }

            $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            $response = $this->send(fn () => Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]));

            return (string) $response->json('access_token');
        });
    }
}
