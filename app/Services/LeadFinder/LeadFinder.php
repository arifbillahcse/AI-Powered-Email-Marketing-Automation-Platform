<?php

namespace App\Services\LeadFinder;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Finds people and their work emails at a company domain (Hunter.io
 * domain search). Only addresses Hunter is reasonably confident about
 * (`min_confidence`) are returned.
 */
class LeadFinder
{
    public const DEPARTMENTS = [
        'executive' => 'Executive', 'sales' => 'Sales', 'marketing' => 'Marketing', 'it' => 'IT',
        'finance' => 'Finance', 'management' => 'Management', 'operations' => 'Operations',
        'hr' => 'HR', 'support' => 'Support', 'communication' => 'Communication', 'legal' => 'Legal',
    ];

    public const SENIORITIES = ['junior' => 'Junior', 'senior' => 'Senior', 'executive' => 'Executive'];

    public function configured(): bool
    {
        return filled(config('outreach.lead_finder.api_key'));
    }

    /**
     * "https://www.Acme.com/about" → "acme.com"; null when it isn't a domain.
     */
    public static function normalizeDomain(string $input): ?string
    {
        $domain = Str::lower(trim($input));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = Str::before(Str::before(Str::before($domain, '/'), '?'), '#');
        $domain = Str::after($domain, '@');
        $domain = (string) preg_replace('/^www\./', '', $domain);

        return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) ? $domain : null;
    }

    /**
     * @param  array{department?: ?string, seniority?: ?string, limit?: int}  $filters
     * @return array{company: ?string, people: list<array{email: string, first_name: ?string, last_name: ?string, title: ?string, linkedin_url: ?string, phone: ?string}>}
     *
     * @throws LeadFinderException
     */
    public function search(string $domain, array $filters = []): array
    {
        if (! $this->configured()) {
            throw new LeadFinderException('The lead finder isn\'t set up yet (HUNTER_API_KEY).');
        }

        $query = array_filter([
            'domain' => $domain,
            'type' => 'personal',
            'limit' => min((int) ($filters['limit'] ?? 25), (int) config('outreach.lead_finder.max_per_domain')),
            'department' => array_key_exists((string) ($filters['department'] ?? ''), self::DEPARTMENTS) ? $filters['department'] : null,
            'seniority' => array_key_exists((string) ($filters['seniority'] ?? ''), self::SENIORITIES) ? $filters['seniority'] : null,
            'api_key' => config('outreach.lead_finder.api_key'),
        ]);

        try {
            $response = Http::timeout(20)->acceptJson()->get('https://api.hunter.io/v2/domain-search', $query);
        } catch (ConnectionException) {
            throw new LeadFinderException('The lead finder service didn\'t respond. Try again later.');
        }

        if ($response->status() === 429) {
            throw new LeadFinderException('The lead finder is rate limited right now. Try again in a minute.');
        }

        if ($response->failed()) {
            throw new LeadFinderException('The lead finder couldn\'t search '.$domain.' (HTTP '.$response->status().').');
        }

        $minConfidence = (int) config('outreach.lead_finder.min_confidence');
        $people = [];

        foreach ((array) $response->json('data.emails', []) as $row) {
            $email = Str::lower(trim((string) ($row['value'] ?? '')));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || (int) ($row['confidence'] ?? 0) < $minConfidence) {
                continue;
            }

            $people[] = [
                'email' => $email,
                'first_name' => $this->text($row['first_name'] ?? null, 100),
                'last_name' => $this->text($row['last_name'] ?? null, 100),
                'title' => $this->text($row['position'] ?? null, 150),
                'linkedin_url' => $this->linkedin($row['linkedin'] ?? null),
                'phone' => $this->text($row['phone_number'] ?? null, 50),
            ];
        }

        return ['company' => $this->text($response->json('data.organization'), 150), 'people' => $people];
    }

    protected function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    protected function linkedin(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // Hunter returns full URLs or bare handles.
        return mb_substr(preg_match('#^https?://#i', $value) ? $value : 'https://www.linkedin.com/in/'.ltrim($value, '/'), 0, 255);
    }
}
