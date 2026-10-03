<?php

use App\Enums\WorkspaceRole;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Mail\MailboxTransportFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Fakes\RecordingTransport;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Create a workspace with the given user as a member and sign them in
 * to it, ready for Livewire tests of tenant pages.
 */
function actingInWorkspace(?User $user = null, WorkspaceRole $role = WorkspaceRole::Owner, ?Workspace $workspace = null): Workspace
{
    $user ??= User::factory()->create();
    $workspace ??= Workspace::factory()->create();
    $workspace->addMember($user, $role);

    test()->actingAs($user);
    Filament::setCurrentPanel('app');
    Filament::setTenant($workspace);
    // Real requests boot the panel in middleware; this registers the tenancy
    // scopes and observers that attach new records to the workspace.
    Filament::bootCurrentPanel();

    return $workspace;
}

/**
 * Replace SMTP with a transport that records messages (or fails with the
 * given SMTP error and code).
 */
function fakeTransport(?string $failWith = null, int $failCode = 0): RecordingTransport
{
    $transport = new RecordingTransport($failWith, $failCode);

    app()->instance(MailboxTransportFactory::class, new class($transport) extends MailboxTransportFactory
    {
        public function __construct(public RecordingTransport $transport) {}

        public function make(EmailAccount $account): RecordingTransport
        {
            return $this->transport;
        }
    });

    return $transport;
}

/**
 * A launched campaign that may send right now: UTC workspace with a mailing
 * address, one active mailbox and a campaign open every day, all day.
 *
 * @return array{workspace: Workspace, campaign: Campaign, mailbox: EmailAccount, leads: Collection<int, Lead>}
 */
function readyCampaign(int $leads = 1, array $campaign = [], array $mailbox = []): array
{
    $workspace = Workspace::factory()->withMailingAddress()->create(['timezone' => 'UTC']);
    $list = LeadList::factory()->for($workspace)->create();
    $leadModels = Lead::factory()->for($workspace)->count($leads)->create();
    $leadModels->each(fn (Lead $lead) => $lead->lists()->attach($list));

    $allWeek = [1, 2, 3, 4, 5, 6, 7];

    $mailboxModel = EmailAccount::factory()->for($workspace)->create(array_merge([
        'email' => 'arif@softorio.com',
        'from_name' => 'Arif',
        'daily_limit' => 30,
        'min_delay_seconds' => 60,
        'max_delay_seconds' => 60,
        'send_window_start' => '00:00',
        'send_window_end' => '23:59',
        'send_days' => $allWeek,
    ], $mailbox));

    $campaignModel = Campaign::factory()->for($workspace)->withSteps()->create(array_merge([
        'send_window_start' => '00:00',
        'send_window_end' => '23:59',
        'send_days' => $allWeek,
        'daily_limit' => 100,
    ], $campaign));
    $campaignModel->leadLists()->attach($list);
    $campaignModel->emailAccounts()->attach($mailboxModel);

    app(CampaignLauncher::class)->launch($campaignModel);

    return ['workspace' => $workspace, 'campaign' => $campaignModel->refresh(), 'mailbox' => $mailboxModel, 'leads' => $leadModels];
}
