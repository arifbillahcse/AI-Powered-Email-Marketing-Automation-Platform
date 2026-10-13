<?php

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignTaskStatus;
use App\Enums\LeadActivityType;
use App\Enums\StepType;
use App\Enums\WorkspaceRole;
use App\Filament\App\Pages\Tasks;
use App\Jobs\SendCampaignEmail;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\CampaignTask;
use App\Models\LeadActivity;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Campaigns\CampaignTasks;
use App\Services\Sending\SendScheduler;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * A launched campaign: LinkedIn connection request first, then an email.
 *
 * @return array{campaign: Campaign, campaignLead: CampaignLead, workspace: Workspace}
 */
function multichannelCampaign(array $steps = []): array
{
    ['campaign' => $campaign, 'leads' => $leads, 'workspace' => $workspace] = readyCampaign(1, launch: false);
    $leads[0]->update(['first_name' => 'Jane', 'linkedin_url' => 'https://www.linkedin.com/in/jane']);

    $campaign->steps()->delete();
    $campaign->steps()->createMany($steps ?: [
        ['position' => 1, 'type' => 'linkedin_connect', 'delay_days' => 0, 'subject' => 'Connect with {{first_name}}', 'body' => '<p>Mention their launch, {{first_name}}.</p>'],
        ['position' => 2, 'type' => 'email', 'delay_days' => 0, 'subject' => 'Hello {{first_name}}', 'body' => '<p>Hi there</p>'],
    ]);

    app(CampaignLauncher::class)->launch($campaign);

    return ['campaign' => $campaign->refresh(), 'campaignLead' => CampaignLead::sole(), 'workspace' => $workspace];
}

it('turns a manual step into a task and waits for it', function () {
    $transport = fakeTransport();
    ['campaignLead' => $campaignLead] = multichannelCampaign();

    expect(app(SendScheduler::class)->tick())->toBe(0);

    $task = CampaignTask::sole();

    expect($task)
        ->type->toBe(StepType::LinkedinConnect)
        ->status->toBe(CampaignTaskStatus::Open)
        ->title->toBe('Connect with Jane')
        ->instructions->toBe('Mention their launch, Jane.')
        ->and($campaignLead->refresh())
        ->next_send_at->toBeNull()
        ->status->toBe(CampaignLeadStatus::Active)
        ->and($transport->sent)->toBeEmpty();

    // Nothing more happens until the task is done.
    app(SendScheduler::class)->tick();
    expect(CampaignTask::count())->toBe(1)->and($transport->sent)->toBeEmpty();

    app(CampaignTasks::class)->complete($task);

    expect($campaignLead->refresh())->steps_sent->toBe(1)->next_send_at->not->toBeNull()
        ->and(LeadActivity::query()->where('type', LeadActivityType::TaskCompleted->value)->exists())->toBeTrue();

    app(SendScheduler::class)->tick();

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]->getSubject())->toBe('Hello Jane')
        ->and($campaignLead->refresh())->steps_sent->toBe(2)->status->toBe(CampaignLeadStatus::Completed);
});

it('creates the task instead of sending when the send job reaches a manual step', function () {
    $transport = fakeTransport();
    ['campaignLead' => $campaignLead, 'campaign' => $campaign] = multichannelCampaign();

    app()->call([new SendCampaignEmail($campaignLead->id, $campaign->emailAccounts()->first()->id, 1), 'handle']);

    expect(CampaignTask::count())->toBe(1)->and($transport->sent)->toBeEmpty();
});

it('completes the sequence when the last step is skipped', function () {
    ['campaignLead' => $campaignLead] = multichannelCampaign([
        ['position' => 1, 'type' => 'email', 'delay_days' => 0, 'subject' => 'Hi', 'body' => '<p>Hi</p>'],
        ['position' => 2, 'type' => 'call', 'delay_days' => 0, 'subject' => null, 'body' => null],
    ]);
    $campaignLead->forceFill(['steps_sent' => 1, 'next_send_at' => now()->subMinute()])->save();

    $task = app(CampaignTasks::class)->open($campaignLead, 2);
    expect($task->title)->toBe('Phone call');

    app(CampaignTasks::class)->skip($task);
    app(CampaignTasks::class)->skip($task); // twice is harmless

    expect($task->refresh()->status)->toBe(CampaignTaskStatus::Skipped)
        ->and($campaignLead->refresh())->status->toBe(CampaignLeadStatus::Completed)->steps_sent->toBe(2);
});

it('launches campaigns whose manual steps have no subject or body', function () {
    ['campaign' => $campaign] = readyCampaign(1, launch: false);
    $campaign->steps()->delete();
    $campaign->steps()->createMany([
        ['position' => 1, 'type' => 'linkedin_visit', 'delay_days' => 0],
        ['position' => 2, 'type' => 'email', 'delay_days' => 1, 'subject' => null, 'body' => '<p>Hi</p>'],
    ]);

    expect(app(CampaignLauncher::class)->problems($campaign))->toContain('The first email needs a subject.');

    $campaign->steps()->where('position', 2)->update(['subject' => 'Hello']);

    expect(app(CampaignLauncher::class)->problems($campaign))->toBe([]);
});

it('lists tasks and completes them from the tasks page', function () {
    ['workspace' => $workspace] = multichannelCampaign();
    app(SendScheduler::class)->tick();
    $task = CampaignTask::sole();
    actingInWorkspace(workspace: $workspace);

    Livewire::test(Tasks::class)
        ->assertCanSeeTableRecords([$task])
        ->assertActionVisible(TestAction::make('openLinkedin')->table($task))
        ->callAction(TestAction::make('complete')->table($task))
        ->assertNotified('Task done');

    expect($task->refresh()->status)->toBe(CampaignTaskStatus::Done)
        ->and($task->completed_by)->not->toBeNull();
});

it('keeps tasks read-only for clients', function () {
    ['workspace' => $workspace] = multichannelCampaign();
    app(SendScheduler::class)->tick();
    actingInWorkspace(role: WorkspaceRole::Client, workspace: $workspace);

    Livewire::test(Tasks::class)
        ->assertActionHidden(TestAction::make('complete')->table(CampaignTask::sole()));
});
