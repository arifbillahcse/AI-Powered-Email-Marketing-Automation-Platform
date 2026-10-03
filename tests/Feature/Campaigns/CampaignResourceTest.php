<?php

use App\Enums\CampaignStatus;
use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\App\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\App\Resources\Campaigns\Pages\ListCampaigns;
use App\Models\Campaign;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

it('creates a campaign with a sequence, audience and mailboxes', function () {
    $undoRepeaterFake = Repeater::fake();
    $workspace = actingInWorkspace();
    $list = LeadList::factory()->for($workspace)->create();
    $mailbox = EmailAccount::factory()->for($workspace)->create();

    Livewire::test(CreateCampaign::class)
        ->fillForm([
            'name' => 'October founders',
            'steps' => [
                ['delay_days' => 0, 'subject' => 'Hi {{first_name|there}}', 'body' => '<p>Hello</p>'],
                ['delay_days' => 3, 'subject' => null, 'body' => '<p>Bump</p>'],
            ],
            'leadLists' => [$list->id],
            'emailAccounts' => [$mailbox->id],
            'timezone' => 'Asia/Dhaka',
            'send_window_start' => '09:00',
            'send_window_end' => '17:00',
            'send_days' => ['1', '2', '3'],
            'daily_limit' => 50,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $campaign = Campaign::sole();

    expect($campaign->workspace_id)->toBe($workspace->id)
        ->and($campaign->status)->toBe(CampaignStatus::Draft)
        ->and($campaign->send_days)->toBe([1, 2, 3])
        ->and($campaign->steps()->pluck('position')->all())->toBe([1, 2])
        ->and($campaign->steps()->pluck('subject')->all())->toBe(['Hi {{first_name|there}}', null])
        ->and($campaign->leadLists()->pluck('lead_lists.id')->all())->toBe([$list->id])
        ->and($campaign->emailAccounts()->pluck('email_accounts.id')->all())->toBe([$mailbox->id]);
});

it('previews the email for a lead in the audience', function () {
    $workspace = actingInWorkspace();
    $workspace->update(['address_line1' => '1 Main St', 'city' => 'Dhaka', 'country' => 'BD']);
    $list = LeadList::factory()->for($workspace)->create();
    $lead = Lead::factory()->for($workspace)->create(['first_name' => 'Rahim']);
    $lead->lists()->attach($list);
    $campaign = Campaign::factory()->for($workspace)->withSteps()->create();
    $campaign->leadLists()->attach($list);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->mountAction('preview')
        ->assertSee('Quick question, Rahim')
        ->assertSee($lead->email);
});

it('shows why a campaign cannot launch', function () {
    $workspace = actingInWorkspace();
    $campaign = Campaign::factory()->for($workspace)->withSteps()->create();

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('launch')
        ->assertNotified('This campaign can\'t launch yet');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft);
});

it('launches from the edit page', function () {
    $workspace = actingInWorkspace();
    $workspace->update(['address_line1' => '1 Main St', 'city' => 'Dhaka', 'country' => 'BD']);
    $list = LeadList::factory()->for($workspace)->create();
    Lead::factory()->for($workspace)->count(2)->create()->each(fn (Lead $lead) => $lead->lists()->attach($list));
    $campaign = Campaign::factory()->for($workspace)->withSteps()->create();
    $campaign->leadLists()->attach($list);
    $campaign->emailAccounts()->attach(EmailAccount::factory()->for($workspace)->create());

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->callAction('launch')
        ->assertNotified('Campaign launched')
        ->assertActionVisible('pause')
        ->assertActionHidden('launch');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Active)
        ->and($campaign->campaignLeads()->count())->toBe(2);
});

it('separates templates and creates campaigns from them', function () {
    $workspace = actingInWorkspace();
    $campaign = Campaign::factory()->for($workspace)->create();
    $template = Campaign::factory()->for($workspace)->template()->withSteps()->create(['name' => 'Cold intro']);

    Livewire::test(ListCampaigns::class)
        ->assertCanSeeTableRecords([$campaign])
        ->assertCanNotSeeTableRecords([$template])
        ->set('activeTab', 'templates')
        ->assertCanSeeTableRecords([$template])
        ->callAction('fromTemplate', data: ['template_id' => $template->id, 'name' => 'From template'])
        ->assertHasNoActionErrors();

    $created = Campaign::where('name', 'From template')->sole();

    expect($created->is_template)->toBeFalse()
        ->and($created->steps()->count())->toBe(2);
});

it('keeps completed campaigns read-only', function () {
    $workspace = actingInWorkspace();
    $campaign = Campaign::factory()->for($workspace)->withSteps()->create(['status' => CampaignStatus::Completed]);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertOk()
        ->assertFormFieldDisabled('name')
        ->assertActionVisible('duplicate');
});

it('makes campaigns read-only for clients', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    $campaign = Campaign::factory()->for($workspace)->withSteps()->create();

    Livewire::test(ListCampaigns::class)
        ->assertActionHidden('create')
        ->assertCanSeeTableRecords([$campaign])
        ->assertActionHidden(TestAction::make('duplicate')->table($campaign));
});

it('blocks another workspace\'s campaign', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user)->create();
    $theirs = Campaign::factory()->create();

    $this->actingAs($user)->get("/app/{$workspace->slug}/campaigns/{$theirs->id}/edit")->assertNotFound();
});
