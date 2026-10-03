<?php

use App\Enums\EmailEventType;
use App\Jobs\SendCampaignEmail;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsCsv;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsReport;
use App\Services\Inbox\InboundMailProcessor;
use App\Services\Sending\EngagementRecorder;
use Illuminate\Support\Collection;

/**
 * A campaign that sent its first email to $leads leads, today.
 *
 * @return array{workspace: Workspace, campaign: Campaign, mailbox: EmailAccount, messages: Collection<int, EmailMessage>}
 */
function sentCampaign(int $leads): array
{
    fakeTransport();
    $setup = readyCampaign($leads);

    CampaignLead::query()->each(fn (CampaignLead $lead) => SendCampaignEmail::dispatchSync($lead->id, $setup['mailbox']->id, 1));

    return [...$setup, 'messages' => EmailMessage::query()->orderBy('id')->get()];
}

function analyticsFor(Workspace $workspace, array $state = []): AnalyticsFilters
{
    return AnalyticsFilters::fromState($workspace->refresh(), ['range' => '7d', ...$state]);
}

it('matches the event log exactly', function () {
    ['workspace' => $workspace, 'mailbox' => $mailbox, 'messages' => $messages] = sentCampaign(20);
    $engagement = app(EngagementRecorder::class);

    // Repeated opens and clicks count once per email.
    foreach ($messages->take(12) as $message) {
        $engagement->open($message->refresh());
        $engagement->open($message->refresh());
    }

    foreach ($messages->slice(2, 5) as $message) {
        $engagement->click($message->refresh(), 'https://softorio.com');
        $engagement->click($message->refresh(), 'https://softorio.com/pricing');
    }

    foreach ($messages->slice(10, 3) as $message) {
        app(InboundMailProcessor::class)->process($mailbox, rawEmail([
            'From' => $message->lead()->value('email'),
            'In-Reply-To' => "<{$message->message_id}>",
        ]));
    }

    $engagement->hardBounce($messages[15]->refresh(), '550 User unknown');
    $engagement->hardBounce($messages[16]->refresh(), '550 User unknown');
    $engagement->unsubscribe($messages[17]->refresh());
    $engagement->unsubscribe($messages[17]->refresh());

    $totals = app(AnalyticsReport::class)->totals(analyticsFor($workspace));
    $fromEvents = fn (EmailEventType $type): int => EmailEvent::query()->where('type', $type->value)->distinct()->count('email_message_id');

    expect($totals)->toBe([
        'sent' => 20,
        'opened' => 12,
        'clicked' => 5,
        'replied' => 3,
        'bounced' => 2,
        'unsubscribed' => 1,
    ])
        ->and($totals['sent'])->toBe($fromEvents(EmailEventType::Sent))
        ->and($totals['opened'])->toBe($fromEvents(EmailEventType::Open))
        ->and($totals['clicked'])->toBe($fromEvents(EmailEventType::Click))
        ->and($totals['replied'])->toBe($fromEvents(EmailEventType::Reply))
        ->and($totals['bounced'])->toBe($fromEvents(EmailEventType::Bounce))
        ->and($totals['unsubscribed'])->toBe($fromEvents(EmailEventType::Unsubscribe))
        ->and(AnalyticsReport::rates($totals))->toBe([
            'open' => 60.0,
            'click' => 25.0,
            'reply' => 15.0,
            'bounce' => 10.0,
            'unsubscribe' => 5.0,
        ]);
});

it('counts only emails sent in the period, in the workspace\'s days', function () {
    ['workspace' => $workspace, 'messages' => $messages] = sentCampaign(3);
    $workspace->update(['timezone' => 'Asia/Dhaka']);

    // Sent 10 days ago: outside "last 7 days", inside "last 30 days".
    $messages[0]->forceFill(['sent_at' => now()->subDays(10)])->save();

    $report = app(AnalyticsReport::class);

    expect($report->totals(analyticsFor($workspace))['sent'])->toBe(2)
        ->and($report->totals(analyticsFor($workspace, ['range' => '30d']))['sent'])->toBe(3)
        ->and($report->totals(analyticsFor($workspace, [
            'range' => 'custom',
            'from' => now('Asia/Dhaka')->subDays(10)->toDateString(),
            'to' => now('Asia/Dhaka')->subDays(10)->toDateString(),
        ]))['sent'])->toBe(1);

    $filters = analyticsFor($workspace);
    expect($filters->from->toIso8601String())->toBe(now('Asia/Dhaka')->startOfDay()->subDays(6)->utc()->toIso8601String());
});

it('never mixes up workspaces', function () {
    ['workspace' => $workspace] = sentCampaign(2);
    $other = Workspace::factory()->create();

    expect(app(AnalyticsReport::class)->totals(analyticsFor($other))['sent'])->toBe(0)
        ->and(app(AnalyticsReport::class)->totals(analyticsFor($workspace))['sent'])->toBe(2);
});

it('breaks results down by campaign, email and mailbox', function () {
    ['workspace' => $workspace, 'campaign' => $campaign, 'mailbox' => $mailbox, 'messages' => $messages] = sentCampaign(4);
    app(EngagementRecorder::class)->open($messages[0]->refresh());

    // The follow-up for one lead.
    $this->travel(4)->days();
    SendCampaignEmail::dispatchSync($messages[1]->campaign_lead_id, $mailbox->id, 2);

    $report = app(AnalyticsReport::class);
    $filters = analyticsFor($workspace, ['campaign_id' => $campaign->id]);

    expect($report->byCampaign($filters)[$campaign->id])
        ->toMatchArray(['name' => $campaign->name, 'sent' => 5, 'opened' => 1, 'open_rate' => 20.0])
        ->and($report->byStep($filters)->map(fn (array $row) => [$row['name'], $row['sent']])->all())->toBe([
            1 => ['Email 1: Quick question, {{first_name|there}}', 4],
            2 => ['Email 2 (same thread)', 1],
        ])
        ->and($report->byMailbox($filters)[$mailbox->id])->toMatchArray(['name' => $mailbox->email, 'sent' => 5])
        ->and($report->byStep(analyticsFor($workspace)))->toBeEmpty();
});

it('builds a day-by-day series with every day present', function () {
    ['workspace' => $workspace, 'messages' => $messages] = sentCampaign(2);
    $messages[0]->forceFill(['sent_at' => now()->subDays(3)])->save();

    $daily = app(AnalyticsReport::class)->daily(analyticsFor($workspace));

    expect($daily)->toHaveCount(7)
        ->and($daily[now()->toDateString()]['sent'])->toBe(1)
        ->and($daily[now()->subDays(3)->toDateString()]['sent'])->toBe(1)
        ->and(array_sum(array_column($daily, 'sent')))->toBe(2);
});

it('exports CSV that spreadsheets cannot run as formulas', function () {
    ['workspace' => $workspace, 'campaign' => $campaign] = sentCampaign(2);
    $campaign->update(['name' => '=HYPERLINK("http://evil.test")']);

    $csv = app(AnalyticsCsv::class)->toString('campaigns', analyticsFor($workspace));

    expect($csv)->toStartWith('Campaign,Sent,Opened,"Open %"')
        ->toContain("\"'=HYPERLINK(\"\"http://evil.test\"\")\",2,0,0,")
        ->and(app(AnalyticsCsv::class)->rows('daily', analyticsFor($workspace)))->toHaveCount(8);
});
