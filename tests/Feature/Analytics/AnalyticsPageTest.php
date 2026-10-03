<?php

use App\Enums\DnsCheckStatus;
use App\Enums\EmailAccountStatus;
use App\Enums\WorkspaceRole;
use App\Filament\App\Pages\Analytics;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Widgets\Analytics\ActivityChart;
use App\Filament\App\Widgets\Analytics\AnalyticsStats;
use App\Filament\App\Widgets\Analytics\CampaignBreakdown;
use App\Filament\App\Widgets\Analytics\MailboxBreakdown;
use App\Filament\App\Widgets\Analytics\StepBreakdown;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\SendingDomain;
use App\Services\Analytics\AnalyticsCsv;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\MailboxHealth;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Sent emails in the current workspace, without going through SMTP.
 */
function seedSentEmails(Campaign $campaign, EmailAccount $mailbox, int $count, array $state = []): void
{
    for ($i = 0; $i < $count; $i++) {
        $lead = Lead::factory()->create(['workspace_id' => $campaign->workspace_id]);
        $campaignLead = new CampaignLead;
        $campaignLead->forceFill(['campaign_id' => $campaign->id, 'lead_id' => $lead->id, 'email_account_id' => $mailbox->id, 'steps_sent' => 1])->save();

        $message = new EmailMessage;
        $message->forceFill(array_merge([
            'workspace_id' => $campaign->workspace_id,
            'campaign_id' => $campaign->id,
            'campaign_lead_id' => $campaignLead->id,
            'lead_id' => $lead->id,
            'email_account_id' => $mailbox->id,
            'step_position' => 1,
            'token' => Str::random(40),
            'status' => 'sent',
            'sent_at' => now(),
        ], $state))->save();
    }
}

it('shows KPIs, trend and breakdowns for the workspace', function () {
    $workspace = actingInWorkspace();
    $mailbox = EmailAccount::factory()->for($workspace)->create(['email' => 'arif@softorio.com']);
    $campaign = Campaign::factory()->for($workspace)->withSteps()->create(['name' => 'October founders']);
    seedSentEmails($campaign, $mailbox, 3);
    seedSentEmails($campaign, $mailbox, 1, ['replied_at' => now()]);

    $this->get(Analytics::getUrl())->assertOk()->assertSee('Analytics');

    Livewire::test(AnalyticsStats::class, ['pageFilters' => ['range' => '7d']])
        ->assertSee('Reply rate')
        ->assertSee('25%');

    Livewire::test(ActivityChart::class, ['pageFilters' => ['range' => '7d']])->assertOk();

    Livewire::test(CampaignBreakdown::class, ['pageFilters' => ['range' => '7d']])
        ->assertSee('October founders')
        ->assertSee('25%');

    Livewire::test(StepBreakdown::class, ['pageFilters' => ['range' => '7d']])
        ->assertSee('Pick a campaign');

    Livewire::test(StepBreakdown::class, ['pageFilters' => ['range' => '7d', 'campaign_id' => $campaign->id]])
        ->assertSee('Email 1: Quick question');

    Livewire::test(MailboxBreakdown::class, ['pageFilters' => ['range' => '7d']])
        ->assertSee('arif@softorio.com')
        ->assertSee('Healthy');
});

it('shows the last 30 days on the dashboard', function () {
    actingInWorkspace();

    $this->get(Dashboard::getUrl())->assertOk()->assertSeeLivewire(AnalyticsStats::class);
});

it('lets clients see analytics', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);

    $this->get(Analytics::getUrl(tenant: $workspace))->assertOk();
});

it('exports the filtered breakdown as CSV', function () {
    $workspace = actingInWorkspace();
    $mailbox = EmailAccount::factory()->for($workspace)->create();
    $campaign = Campaign::factory()->for($workspace)->withSteps()->create(['name' => 'Agencies']);
    seedSentEmails($campaign, $mailbox, 2);

    $filters = AnalyticsFilters::fromState($workspace, ['range' => '30d']);
    $expected = app(AnalyticsCsv::class)->toString('campaigns', $filters);

    Livewire::test(Analytics::class)
        ->set('filters', ['range' => '30d'])
        ->callAction('exportCsv', data: ['report' => 'campaigns'])
        ->assertFileDownloaded('analytics-campaigns-'.$filters->from->format('Ymd').'-'.$filters->to->format('Ymd').'.csv', $expected);

    expect($expected)->toContain('Agencies,2,');
});

it('scores mailbox health from bounces, DNS and connection state', function () {
    $workspace = actingInWorkspace();
    $healthy = EmailAccount::factory()->for($workspace)->create(['email' => 'arif@good.test', 'daily_limit' => 30]);
    SendingDomain::query()->whereKey($healthy->refresh()->sending_domain_id)->sole()->forceFill(['status' => DnsCheckStatus::Pass])->save();

    // Unchecked domain, failing connection, unreadable inbox, high limit.
    $risky = EmailAccount::factory()->for($workspace)->create(['email' => 'arif@bad.test', 'daily_limit' => 200]);
    $risky->forceFill(['status' => EmailAccountStatus::Error, 'imap_error' => 'Login failed'])->save();

    $campaign = Campaign::factory()->for($workspace)->withSteps()->create();
    seedSentEmails($campaign, $healthy, 20);
    seedSentEmails($campaign, $risky, 18);
    seedSentEmails($campaign, $risky, 2, ['bounced_at' => now(), 'status' => 'bounced']);

    $scores = app(MailboxHealth::class)->forWorkspace($workspace->id);

    expect($scores[$healthy->id])->toMatchArray(['score' => 100, 'grade' => 'Healthy', 'issues' => []])
        ->and($scores[$risky->id]['score'])->toBe(100 - 40 - 30 - 10 - 5 - 10)
        ->and($scores[$risky->id]['grade'])->toBe('At risk')
        ->and($scores[$risky->id]['issues'][0])->toBe('Bounce rate 10.0% (keep it under 2%: verify your list).');
});
