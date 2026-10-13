<?php

use App\Enums\WorkspaceRole;
use App\Filament\App\Actions\SendLeadEmailAction;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Filament\App\Resources\Leads\Pages\ViewLead;
use App\Filament\App\Resources\MessageTemplates\Pages\ManageMessageTemplates;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\MessageTemplate;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('opens with the greeting typed and the mailbox the lead already knows', function () {
    $workspace = actingInWorkspace();
    EmailAccount::factory()->for($workspace)->create(['email' => 'a@softorio.com']);
    $known = EmailAccount::factory()->for($workspace)->create(['email' => 'z@softorio.com']);
    $campaign = Campaign::factory()->for($workspace)->create();
    $lead = Lead::factory()->for($workspace)->create(['first_name' => 'Jane']);

    // Not emailed yet: the first active mailbox.
    $action = SendLeadEmailAction::make();
    expect($action->defaultMailbox($lead))->not->toBe($known->id);

    // Emailed by a campaign from the "z" mailbox: that one.
    $campaignLead = new CampaignLead;
    $campaignLead->forceFill(['campaign_id' => $campaign->id, 'lead_id' => $lead->id, 'email_account_id' => $known->id])->save();
    expect($action->defaultMailbox($lead))->toBe($known->id);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->mountAction('sendEmail')
        ->assertSchemaStateSet(['email_account_id' => $known->id, 'body' => SendLeadEmailAction::GREETING]);
});

it('fills the subject and message from a template and counts its use', function () {
    $transport = fakeTransport();
    $workspace = actingInWorkspace();
    $mailbox = EmailAccount::factory()->for($workspace)->create(['email' => 'arif@softorio.com']);
    $lead = Lead::factory()->for($workspace)->create(['email' => 'jane@acme.test', 'first_name' => 'Jane', 'company' => 'Acme']);
    $template = new MessageTemplate(['name' => 'Intro', 'subject' => 'Idea for {{company}}', 'body' => "Hi {{first_name|there}},\n\nWe build fast WordPress sites."]);
    $template->workspace_id = $workspace->id;
    $template->save();

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->mountAction('sendEmail')
        ->fillForm(['template_id' => $template->id])
        ->assertSchemaStateSet(['subject' => 'Idea for {{company}}', 'body' => "Hi {{first_name|there}},\n\nWe build fast WordPress sites."])
        ->fillForm(['email_account_id' => $mailbox->id])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Email queued');

    expect($transport->sent[0]->getSubject())->toBe('Idea for Acme')
        ->and($transport->sent[0]->getTextBody())->toContain('Hi Jane,')
        ->and($template->refresh()->times_used)->toBe(1);
});

it('saves a written email as a new template', function () {
    fakeTransport();
    $workspace = actingInWorkspace();
    $mailbox = EmailAccount::factory()->for($workspace)->create();
    $lead = Lead::factory()->for($workspace)->create();

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('sendEmail', data: [
            'email_account_id' => $mailbox->id,
            'subject' => 'Hosting for {{company|your site}}',
            'body' => "Hi {{first_name|there}},\n\nNeed faster hosting?",
            'save_template' => true,
            'template_name' => 'Hosting offer',
        ])
        ->assertHasNoFormErrors();

    expect(MessageTemplate::query()->where('workspace_id', $workspace->id)->sole())
        ->name->toBe('Hosting offer')
        ->subject->toBe('Hosting for {{company|your site}}')
        ->created_by->toBe(auth()->id());
});

it('shows when the lead was last contacted', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();
    $lead->forceFill(['last_contacted_at' => now()->subDays(12)])->save();

    expect(SendLeadEmailAction::make()->context($lead))->toContain('Last contacted 1 week ago')
        ->and(SendLeadEmailAction::make()->context(Lead::factory()->for($workspace)->create()))->toContain('Not contacted yet');
});

it('has a one-click write button on every lead row', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();

    Livewire::test(ListLeads::class)
        ->assertActionVisible(TestAction::make('sendEmail')->table($lead));
});

it('manages message templates per workspace', function () {
    $workspace = actingInWorkspace();

    Livewire::test(ManageMessageTemplates::class)
        ->callAction('create', data: ['name' => 'Intro', 'subject' => 'Hello', 'body' => 'Hi {{first_name|there}}'])
        ->assertHasNoFormErrors()
        ->callAction('create', data: ['name' => 'Intro', 'body' => 'Again'])
        ->assertHasFormErrors(['name' => 'unique']);

    expect(MessageTemplate::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('keeps templates read-only for clients', function () {
    actingInWorkspace(role: WorkspaceRole::Client);

    Livewire::test(ManageMessageTemplates::class)->assertActionHidden('create');
});
