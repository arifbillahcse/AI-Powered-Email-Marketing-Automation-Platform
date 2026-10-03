<?php

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\Enums\LeadStatus;
use App\Enums\SuppressionReason;
use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Filament\App\Resources\Leads\Pages\CreateLead;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Filament\App\Resources\Leads\Pages\ViewLead;
use App\Filament\App\Resources\Suppressions\Pages\ManageSuppressions;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Leads\SuppressionList;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('creates a lead with lists, tags and custom fields', function () {
    $workspace = actingInWorkspace();
    $list = LeadList::factory()->for($workspace)->create();

    Livewire::test(CreateLead::class)
        ->fillForm([
            'email' => 'Jane@Acme.com',
            'first_name' => 'Jane',
            'status' => 'new',
            'lists' => [$list->id],
            'tag_names' => ['hot'],
            'custom_fields' => ['Company Size' => '11-50'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lead = Lead::sole();

    expect($lead->workspace_id)->toBe($workspace->id)
        ->and($lead->email)->toBe('jane@acme.com')
        ->and($lead->lists->pluck('id')->all())->toBe([$list->id])
        ->and($lead->tags->pluck('name')->all())->toBe(['hot'])
        ->and($lead->custom_fields)->toBe(['company_size' => '11-50']);
});

it('only lists and filters this workspace\'s leads', function () {
    $other = Lead::factory()->create();
    $workspace = actingInWorkspace();
    $mine = Lead::factory()->for($workspace)->create();
    $suppressed = Lead::factory()->for($workspace)->create(['email' => 'gone@acme.com']);
    app(SuppressionList::class)->add($workspace->id, 'gone@acme.com');

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$mine, $suppressed])
        ->assertCanNotSeeTableRecords([$other])
        ->filterTable('suppressed', true)
        ->assertCanSeeTableRecords([$suppressed])
        ->assertCanNotSeeTableRecords([$mine]);
});

it('bulk adds the selection to a list and tags it', function () {
    $workspace = actingInWorkspace();
    $leads = Lead::factory()->for($workspace)->count(3)->create();
    $list = LeadList::factory()->for($workspace)->create();

    Livewire::test(ListLeads::class)
        ->selectTableRecords($leads->take(2))
        ->callAction(TestAction::make('addToList')->table()->bulk(), data: ['list_id' => $list->id])
        ->assertNotified();

    Livewire::test(ListLeads::class)
        ->selectTableRecords($leads->take(2))
        ->callAction(TestAction::make('addTags')->table()->bulk(), data: ['tags' => ['vip']]);

    expect($list->leads()->pluck('leads.id')->sort()->values()->all())->toBe($leads->take(2)->pluck('id')->sort()->values()->all())
        ->and($leads[2]->tags()->count())->toBe(0)
        ->and($leads[0]->tags()->pluck('name')->all())->toBe(['vip']);
});

it('bulk changes status and suppresses', function () {
    $workspace = actingInWorkspace();
    $leads = Lead::factory()->for($workspace)->count(2)->create();

    Livewire::test(ListLeads::class)
        ->selectTableRecords($leads)
        ->callAction(TestAction::make('setStatus')->table()->bulk(), data: ['status' => 'interested']);

    Livewire::test(ListLeads::class)
        ->selectTableRecords($leads->take(1))
        ->callAction(TestAction::make('suppress')->table()->bulk());

    expect($leads[0]->refresh()->status)->toBe(LeadStatus::Interested)
        ->and(Suppression::sole()->value)->toBe($leads[0]->email);
});

it('makes leads read-only for clients', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    $lead = Lead::factory()->for($workspace)->create();

    Livewire::test(ListLeads::class)
        ->assertActionHidden('import')
        ->assertActionHidden('create')
        ->assertActionVisible('export')
        ->assertActionHidden(TestAction::make('addToList')->table()->bulk())
        ->assertActionHidden(TestAction::make('delete')->table()->bulk());

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])->assertOk();
});

it('shows the lead with its activity timeline', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();
    $lead->syncTagNames(['hot']);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->assertOk()
        ->assertSee('Lead created')
        ->assertSee('Tagged hot')
        ->assertSee('Can be emailed');
});

it('keeps the workspace filter in queued export queries', function () {
    $other = Lead::factory()->create();
    $workspace = actingInWorkspace();
    $mine = Lead::factory()->for($workspace)->create();

    // Exports serialize the query and run it in a queue worker, where there
    // is no current panel or workspace.
    $serialized = EloquentSerializeFacade::serialize(LeadResource::getEloquentQuery());
    Filament::setTenant(null, isQuiet: true);
    Filament::setCurrentPanel(null);

    $ids = EloquentSerializeFacade::unserialize($serialized)->pluck('id')->all();

    expect($ids)->toBe([$mine->id])->not->toContain($other->id);
});

it('lets admins lift manual suppressions but never unsubscribes', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Admin);
    $manual = app(SuppressionList::class)->add($workspace->id, 'manual@acme.com');
    $unsubscribed = app(SuppressionList::class)->add($workspace->id, 'unsub@acme.com', SuppressionReason::Unsubscribed);

    Livewire::test(ManageSuppressions::class)
        ->assertActionVisible(TestAction::make('delete')->table($manual))
        ->assertActionHidden(TestAction::make('delete')->table($unsubscribed))
        ->callAction('add', data: ['entries' => "a@x.com\nbad entry\nx.org"])
        ->assertNotified('2 added to the suppression list');

    expect(Suppression::count())->toBe(4);
});

it('does not let members lift suppressions', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Member);
    $manual = app(SuppressionList::class)->add($workspace->id, 'manual@acme.com');

    Livewire::test(ManageSuppressions::class)
        ->assertActionHidden(TestAction::make('delete')->table($manual));
});

it('blocks another workspace\'s lead by URL', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user)->create();
    $theirs = Lead::factory()->create();

    $this->actingAs($user)->get("/app/{$workspace->slug}/leads/{$theirs->id}")->assertNotFound();
});
