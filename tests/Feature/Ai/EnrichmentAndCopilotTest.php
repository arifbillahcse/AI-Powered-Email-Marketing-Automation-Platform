<?php

use App\Enums\CampaignStatus;
use App\Filament\App\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Models\Campaign;
use App\Models\Lead;
use App\Services\Ai\AiRunner;
use App\Services\Ai\CampaignCopilot;
use App\Services\Ai\WebsiteEnricher;
use App\Services\Mail\HostGuard;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function websiteAi(): void
{
    fakeAi(fn (string $system, string $user): string => str_contains($system, 'You research companies')
        ? json_encode(['company_summary' => 'Builds booking software for clinics.', 'industry' => 'Healthcare software', 'personalization_hook' => 'Just launched an online appointment app.'])
        : 'x');
}

it('enriches a lead from their website', function () {
    websiteAi();
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create(['email' => 'jane@acme.test', 'website' => 'acme.test', 'custom_fields' => ['team_size' => '12']]);
    $this->mock(HostGuard::class)->shouldReceive('assertAllowed'); // no DNS in tests
    Http::fake(['acme.test*' => Http::response('<html><head><title>Acme Clinic Software</title><meta name="description" content="Booking for clinics"></head><body><script>evil()</script><p>We help clinics take bookings online.</p></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

    $fields = app(WebsiteEnricher::class)->enrich($lead);

    expect($fields)->toHaveKeys(['company_summary', 'industry', 'personalization_hook'])
        ->and($lead->refresh()->custom_fields)->toMatchArray(['team_size' => '12', 'industry' => 'Healthcare software'])
        ->and($lead->activities()->where('type', 'enriched')->exists())->toBeTrue();
});

it('uses the business email domain and skips free mail', function () {
    $enricher = app(WebsiteEnricher::class);

    expect($enricher->url(new Lead(['email' => 'jane@acme.test'])))->toBe('https://acme.test')
        ->and($enricher->url(new Lead(['email' => 'jane@gmail.com'])))->toBeNull()
        ->and($enricher->url(new Lead(['email' => 'x@y.test', 'website' => 'http://acme.test/about'])))->toBe('http://acme.test/about');
});

it('never fetches private addresses, even through a redirect', function () {
    Http::fake([
        '8.8.8.8/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        '*' => Http::response('<html>secret</html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $enricher = new WebsiteEnricher(app(AiRunner::class), new HostGuard(allowPrivateHosts: false));

    expect($enricher->fetchText('http://127.0.0.1/'))->toBeNull()
        ->and($enricher->fetchText('http://8.8.8.8/'))->toBeNull();

    Http::assertSentCount(1); // only the public first hop
});

it('queues enrichment from the leads table', function () {
    websiteAi();
    Bus::fake();
    $workspace = actingInWorkspace();
    $leads = Lead::factory()->for($workspace)->count(2)->create();

    Livewire::test(ListLeads::class)
        ->selectTableRecords($leads)
        ->callAction(TestAction::make('enrichFromWebsite')->table()->bulk())
        ->assertNotified('Enriching 2 leads');

    Bus::assertBatchCount(1);
});

function copilotAi(): void
{
    fakeAi(fn (): string => 'Here you go: '.json_encode(['emails' => [
        ['subject' => 'clinic bookings', 'body' => "Hi {{first_name|there}},\n\nClinics lose <b>20%</b> of bookings by phone.\n\nWorth a chat?"],
        ['subject' => '', 'body' => "Hi {{first_name|there}},\n\nOne more idea."],
        ['subject' => 'ignored?', 'body' => 'Last one.'],
    ]]));
}

it('writes a sequence into a draft campaign', function () {
    copilotAi();
    ['campaign' => $campaign] = readyCampaign(1, launch: false);

    $count = app(CampaignCopilot::class)->write($campaign, ['offer' => 'Booking software for clinics', 'emails' => 2]);
    $steps = $campaign->steps()->get();

    expect($count)->toBe(2)
        ->and($steps)->toHaveCount(2)
        ->and($steps[0]->subject)->toBe('clinic bookings')
        ->and($steps[0]->body)->toBe('<p>Hi {{first_name|there}},</p><p>Clinics lose &lt;b&gt;20%&lt;/b&gt; of bookings by phone.</p><p>Worth a chat?</p>')
        ->and($steps[1]->isReplyInThread())->toBeTrue()
        ->and($steps[1]->delay_days)->toBe(3);
});

it('only rewrites draft campaigns', function () {
    copilotAi();
    ['campaign' => $campaign] = readyCampaign(1);

    expect(fn () => app(CampaignCopilot::class)->write($campaign, ['offer' => 'x', 'emails' => 2]))
        ->toThrow(InvalidArgumentException::class);
});

it('runs from the campaign page and notifies when done', function () {
    copilotAi();
    ['workspace' => $workspace, 'campaign' => $campaign] = readyCampaign(1, launch: false);
    actingInWorkspace(workspace: $workspace);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('copilot', data: ['offer' => 'Booking software for clinics', 'audience' => 'Clinic owners', 'emails' => 3, 'tone' => 'friendly'])
        ->assertNotified('Writing your sequence');

    expect(Campaign::sole()->steps()->count())->toBe(3)
        ->and(Campaign::sole()->status)->toBe(CampaignStatus::Draft)
        ->and(auth()->user()->notifications()->sole()->data['title'])->toBe('Sequence written: 3 emails');
});

it('locks the copilot until AI is set up', function () {
    ['workspace' => $workspace, 'campaign' => $campaign] = readyCampaign(1, launch: false);
    actingInWorkspace(workspace: $workspace);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertActionDisabled('copilot');
});
