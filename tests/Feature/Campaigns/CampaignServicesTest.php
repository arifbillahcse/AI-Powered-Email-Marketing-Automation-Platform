<?php

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignStatus;
use App\Enums\LeadActivityType;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Segment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignCloner;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Campaigns\CampaignLaunchException;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\Leads\SuppressionList;

beforeEach(function () {
    $this->workspace = Workspace::factory()->withMailingAddress()->create();
    $this->list = LeadList::factory()->for($this->workspace)->create();
    $this->mailbox = EmailAccount::factory()->for($this->workspace)->create(['from_name' => 'Arif Billah', 'signature' => '<p>Arif | Softorio</p>']);
    $this->campaign = Campaign::factory()->for($this->workspace)->withSteps()->create();
    $this->campaign->leadLists()->attach($this->list);
    $this->campaign->emailAccounts()->attach($this->mailbox);
    $this->launcher = app(CampaignLauncher::class);
});

function leadInList(LeadList $list, array $attributes = []): Lead
{
    $lead = Lead::factory()->for($list->workspace)->create($attributes);
    $lead->lists()->attach($list);

    return $lead;
}

it('builds the personalised email with signature and compliance footer', function () {
    $lead = leadInList($this->list, ['first_name' => 'Jane', 'company' => '<Acme>']);
    $steps = $this->campaign->steps()->get();
    $builder = app(CampaignMessageBuilder::class);

    $first = $builder->build($this->campaign, $steps[0], $lead, $this->mailbox, 'https://x.test/u/1');
    $followUp = $builder->build($this->campaign, $steps[1], $lead, $this->mailbox, 'https://x.test/u/1');

    expect($first['subject'])->toBe('Quick question, Jane')
        ->and($first['html'])->toContain('Jane,')->toContain('&lt;Acme&gt;')->toContain('Arif | Softorio')
        ->toContain('https://x.test/u/1')->toContain(e($this->workspace->mailingAddress()))
        ->and($first['text'])->toContain('Unsubscribe (https://x.test/u/1)')
        ->and($followUp['subject'])->toBe('Re: Quick question, Jane')
        ->and($followUp['reply_in_thread'])->toBeTrue();
});

it('builds plain-text emails without HTML', function () {
    $this->campaign->update(['plain_text' => true]);
    $lead = leadInList($this->list, ['first_name' => 'Jane']);

    $message = app(CampaignMessageBuilder::class)->build($this->campaign, $this->campaign->steps()->first(), $lead, $this->mailbox, 'https://x.test/u');

    expect($message['html'])->toBeNull()
        ->and($message['text'])->toContain('Jane,')->toContain('Arif | Softorio')->toContain('Unsubscribe: https://x.test/u')
        ->not->toContain('<p>');
});

it('lists every problem that blocks a launch', function () {
    $empty = Campaign::factory()->for(Workspace::factory()->create())->create(['send_days' => []]);

    expect($this->launcher->problems($empty))->toHaveCount(5)
        ->and(fn () => $this->launcher->launch($empty))->toThrow(CampaignLaunchException::class);
});

it('launches and enrolls lists and segments once, without suppressed leads', function () {
    $inList = leadInList($this->list);
    $inBoth = leadInList($this->list, ['company' => 'Target Co']);
    $inSegment = Lead::factory()->for($this->workspace)->create(['company' => 'Target Co']);
    $suppressed = leadInList($this->list, ['email' => 'no@thanks.com']);
    Lead::factory()->for($this->workspace)->create(['company' => 'Other']);
    app(SuppressionList::class)->add($this->workspace->id, 'no@thanks.com');

    $segment = Segment::factory()->for($this->workspace)->create(['rules' => [
        ['field' => 'company', 'operator' => 'equals', 'value' => 'target co'],
    ]]);
    $this->campaign->segments()->attach($segment);

    $user = User::factory()->create();
    $enrolled = $this->launcher->launch($this->campaign, $user);

    $leadIds = CampaignLead::where('campaign_id', $this->campaign->id)->orderBy('lead_id')->pluck('lead_id')->all();

    expect($enrolled)->toBe(3)
        ->and($leadIds)->toBe([$inList->id, $inBoth->id, $inSegment->id])
        ->and($this->campaign->refresh()->status)->toBe(CampaignStatus::Active)
        ->and(CampaignLead::first()->status)->toBe(CampaignLeadStatus::Active)
        ->and($inList->activities()->where('type', LeadActivityType::AddedToCampaign)->value('user_id'))->toBe($user->id)
        ->and($suppressed->activities()->where('type', LeadActivityType::AddedToCampaign)->exists())->toBeFalse();
});

it('enrolls only new leads when re-synced', function () {
    leadInList($this->list);
    $this->launcher->launch($this->campaign);

    $newcomer = leadInList($this->list);
    $added = $this->launcher->enroll($this->campaign);

    expect($added)->toBe(1)
        ->and(CampaignLead::count())->toBe(2)
        ->and($newcomer->activities()->where('type', LeadActivityType::AddedToCampaign)->count())->toBe(1);
});

it('requires the workspace mailing address', function () {
    leadInList($this->list);
    $this->workspace->update(['address_line1' => null]);

    expect($this->launcher->problems($this->campaign->refresh()))
        ->toContain('Add your mailing address in Workspace settings. Anti-spam law requires it in every email.');
});

it('pauses, resumes and completes', function () {
    leadInList($this->list);
    $this->launcher->launch($this->campaign);

    $this->launcher->pause($this->campaign);
    expect($this->campaign->status)->toBe(CampaignStatus::Paused);

    $this->launcher->resume($this->campaign);
    expect($this->campaign->status)->toBe(CampaignStatus::Active);

    $this->launcher->complete($this->campaign);
    expect($this->campaign->status)->toBe(CampaignStatus::Completed)
        ->and($this->campaign->isEditable())->toBeFalse();
});

it('clones campaigns and templates', function () {
    $copy = app(CampaignCloner::class)->clone($this->campaign, 'Copy');
    $template = app(CampaignCloner::class)->clone($this->campaign, 'Template', asTemplate: true);

    expect($copy->status)->toBe(CampaignStatus::Draft)
        ->and($copy->steps()->pluck('subject')->all())->toBe($this->campaign->steps()->pluck('subject')->all())
        ->and($copy->leadLists()->count())->toBe(1)
        ->and($copy->emailAccounts()->count())->toBe(1)
        ->and($template->is_template)->toBeTrue()
        ->and($template->steps()->count())->toBe(2)
        ->and($template->leadLists()->count())->toBe(0)
        ->and(app(CampaignLauncher::class)->problems($template))->toContain('Templates can\'t be launched. Create a campaign from it first.');
});
