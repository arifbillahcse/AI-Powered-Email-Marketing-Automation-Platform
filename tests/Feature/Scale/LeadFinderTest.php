<?php

use App\Filament\App\Pages\LeadFinder as LeadFinderPage;
use App\Jobs\FindLeads;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Workspace;
use App\Services\LeadFinder\LeadFinder;
use App\Services\LeadFinder\LeadFinderException;
use App\Services\Leads\SuppressionList;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config(['modules.lead_finder.enabled' => true, 'outreach.lead_finder.api_key' => 'test-key']);
});

function fakeHunter(): void
{
    Http::fake([
        'api.hunter.io/*' => Http::response(['data' => [
            'domain' => 'acme.test',
            'organization' => 'Acme Inc',
            'emails' => [
                ['value' => 'Jane@Acme.test', 'confidence' => 95, 'first_name' => 'Jane', 'last_name' => 'Doe', 'position' => 'CEO', 'linkedin' => 'janedoe', 'phone_number' => null],
                ['value' => 'guess@acme.test', 'confidence' => 20, 'first_name' => 'Low', 'last_name' => 'Confidence'],
                ['value' => 'old@acme.test', 'confidence' => 90, 'first_name' => 'Old'],
                ['value' => 'blocked@acme.test', 'confidence' => 90],
            ],
        ]]),
    ]);
}

it('normalizes company domains', function (string $input, ?string $domain) {
    expect(LeadFinder::normalizeDomain($input))->toBe($domain);
})->with([
    ['https://www.Acme.com/about?x=1', 'acme.com'],
    ['sales@acme.co.uk', 'acme.co.uk'],
    ['not a domain', null],
]);

it('returns confident people from the domain search', function () {
    fakeHunter();

    $result = app(LeadFinder::class)->search('acme.test', ['department' => 'executive', 'limit' => 10]);

    expect($result['company'])->toBe('Acme Inc')
        ->and(collect($result['people'])->pluck('email')->all())->toBe(['jane@acme.test', 'old@acme.test', 'blocked@acme.test'])
        ->and($result['people'][0]['linkedin_url'])->toBe('https://www.linkedin.com/in/janedoe');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'domain=acme.test') && str_contains($request->url(), 'department=executive'));
});

it('explains a missing key', function () {
    config(['outreach.lead_finder.api_key' => null]);

    app(LeadFinder::class)->search('acme.test');
})->throws(LeadFinderException::class, 'HUNTER_API_KEY');

it('adds found people to a list, skipping suppressed and existing leads', function () {
    fakeHunter();
    $workspace = Workspace::factory()->create();
    $list = LeadList::factory()->for($workspace)->create();
    $existing = Lead::factory()->for($workspace)->create(['email' => 'old@acme.test', 'first_name' => 'Kept']);
    app(SuppressionList::class)->add($workspace->id, 'blocked@acme.test');

    app()->call([new FindLeads($workspace->id, $list->id, 'acme.test'), 'handle']);

    $jane = Lead::query()->where('email', 'jane@acme.test')->sole();

    expect($jane)
        ->workspace_id->toBe($workspace->id)
        ->source->toBe('lead_finder')
        ->company->toBe('Acme Inc')
        ->title->toBe('CEO')
        ->and($existing->refresh()->first_name)->toBe('Kept')
        ->and($list->leads()->pluck('email')->sort()->values()->all())->toBe(['jane@acme.test', 'old@acme.test'])
        ->and(Lead::query()->where('email', 'blocked@acme.test')->exists())->toBeFalse();
});

it('queues one search per domain from the page', function () {
    Bus::fake();
    actingInWorkspace();

    Livewire::test(LeadFinderPage::class)
        ->callAction('findLeads', data: [
            'domains' => "acme.test\nhttps://www.example.org/team\nnope",
            'limit' => 10,
            'new_list' => 'Targets',
        ])
        ->assertNotified('Searching 2 companies');

    Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 2 && $batch->jobs->first()->domain === 'acme.test');
    expect(LeadList::query()->where('name', 'Targets')->exists())->toBeTrue();
});
