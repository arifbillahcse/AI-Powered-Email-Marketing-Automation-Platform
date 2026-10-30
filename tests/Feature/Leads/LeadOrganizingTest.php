<?php

use App\Enums\LeadActivityType;
use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Filament\App\Resources\Leads\Pages\ViewLead;
use App\Filament\App\Resources\Leads\RelationManagers\NotesRelationManager;
use App\Filament\App\Resources\Tags\Pages\ManageTags;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Compliance\LeadDataExporter;
use App\Services\Leads\FollowUps;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function notedLead(Workspace $workspace, string $body = 'Called, asked to email next week.', ?User $by = null): LeadNote
{
    $lead = Lead::factory()->for($workspace)->create();
    $note = new LeadNote(['body' => $body]);
    $note->workspace_id = $workspace->id;
    $note->lead_id = $lead->id;
    $note->user_id = $by?->id;
    $note->save();

    return $note;
}

// Notes

it('adds a note from the lead page and logs it on the timeline', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('addNote', data: ['body' => "Visited their site.\nContact form only."])
        ->assertHasNoActionErrors()
        ->assertNotified('Note saved');

    $note = LeadNote::sole();

    expect($note->body)->toBe("Visited their site.\nContact form only.")
        ->and($note->lead_id)->toBe($lead->id)
        ->and($note->workspace_id)->toBe($workspace->id)
        ->and($note->user_id)->toBe(auth()->id())
        ->and($lead->activities()->where('type', LeadActivityType::NoteAdded->value)->exists())->toBeTrue();

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->assertSee('Note: Visited their site. Contact form only.')
        ->assertSee('Latest note');
});

it('adds a note straight from the leads table', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();

    Livewire::test(ListLeads::class)
        ->callAction(TestAction::make('addNote')->table($lead), data: ['body' => 'Looks like a good fit.'])
        ->assertNotified('Note saved');

    expect(LeadNote::sole()->body)->toBe('Looks like a good fit.');
});

it('requires a note and keeps it within the limit', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('addNote', data: ['body' => ''])
        ->assertHasActionErrors(['body' => 'required']);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('addNote', data: ['body' => str_repeat('a', LeadNote::MAX_LENGTH + 1)])
        ->assertHasActionErrors(['body' => 'max']);

    expect(LeadNote::count())->toBe(0);
});

it('lists, edits and deletes notes from the lead page', function () {
    $workspace = actingInWorkspace();
    $mine = notedLead($workspace, 'First note', auth()->user());
    $lead = $mine->lead;

    Livewire::test(NotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->assertCanSeeTableRecords([$mine])
        ->callAction(TestAction::make('create')->table(), data: ['body' => 'Second note'])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('edit')->table($mine), data: ['body' => 'First note, edited'])
        ->assertHasNoActionErrors();

    expect($mine->refresh()->body)->toBe('First note, edited')
        ->and($lead->notes()->count())->toBe(2);

    Livewire::test(NotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->callAction(TestAction::make('delete')->table($mine));

    expect($lead->notes()->pluck('body')->all())->toBe(['Second note']);
});

it('lets members change only their own notes, and admins remove any', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Member);
    $author = User::factory()->create();
    $workspace->addMember($author, WorkspaceRole::Member);
    $theirs = notedLead($workspace, 'Written by someone else', $author);
    $ownNote = new LeadNote(['body' => 'Mine']);
    $ownNote->workspace_id = $workspace->id;
    $ownNote->lead_id = $theirs->lead_id;
    $ownNote->save();

    $member = auth()->user();

    expect($member->can('view', $theirs))->toBeTrue()
        ->and($member->can('update', $theirs))->toBeFalse()
        ->and($member->can('delete', $theirs))->toBeFalse()
        ->and($member->can('update', $ownNote))->toBeTrue()
        ->and($member->can('delete', $ownNote))->toBeTrue();

    $admin = User::factory()->create();
    $workspace->addMember($admin, WorkspaceRole::Admin);

    expect($admin->can('update', $theirs))->toBeFalse()
        ->and($admin->can('delete', $theirs))->toBeTrue();
});

it('keeps notes read-only for clients', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    $note = notedLead($workspace, 'Internal only');

    Livewire::test(ViewLead::class, ['record' => $note->lead->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('addNote');

    Livewire::test(NotesRelationManager::class, ['ownerRecord' => $note->lead, 'pageClass' => ViewLead::class])
        ->assertCanSeeTableRecords([$note])
        ->assertActionHidden(TestAction::make('create')->table());

    expect(auth()->user()->can('create', LeadNote::class))->toBeFalse();
});

it('never shows another workspace\'s notes', function () {
    $other = notedLead(Workspace::factory()->create(), 'Secret of another workspace');
    $workspace = actingInWorkspace();
    $mine = notedLead($workspace, 'Visible');

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$mine->lead])
        ->assertCanNotSeeTableRecords([$other->lead]);
});

it('finds leads by what their notes say', function () {
    $workspace = actingInWorkspace();
    $called = notedLead($workspace, 'Called the CEO. Interested in Q1.');
    $other = notedLead($workspace, 'No careers page');
    $none = Lead::factory()->for($workspace)->create();

    Livewire::test(ListLeads::class)
        ->filterTable('note', ['note' => 'ceo'])
        ->assertCanSeeTableRecords([$called->lead])
        ->assertCanNotSeeTableRecords([$other->lead, $none]);
});

it('deletes notes with the lead and includes them in the data export', function () {
    $workspace = actingInWorkspace();
    $note = notedLead($workspace, 'Prefers calls', auth()->user());
    $lead = $note->lead;

    $export = app(LeadDataExporter::class)->export($lead);

    expect($export['notes'])->toHaveCount(1)
        ->and($export['notes'][0]['note'])->toBe('Prefers calls');

    $lead->delete();

    expect(LeadNote::count())->toBe(0);
});

// Tags

it('gives tags a color and shows it on the lead', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();
    $lead->syncTagNames(['hot']);

    Livewire::test(ManageTags::class)
        ->callAction(TestAction::make('create'), data: ['name' => 'Agency', 'color' => 'success'])
        ->assertHasNoActionErrors();

    $tag = Tag::query()->where('name', 'agency')->sole();
    expect($tag->color)->toBe('success')->and($tag->workspace_id)->toBe($workspace->id);

    $hot = Tag::query()->where('name', 'hot')->sole();
    expect($hot->color)->toBe('gray');

    Livewire::test(ManageTags::class)
        ->callAction(TestAction::make('edit')->table($hot), data: ['name' => 'hot', 'color' => 'danger'])
        ->assertHasNoActionErrors();

    expect($hot->refresh()->color)->toBe('danger');

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])->assertSee('hot');
});

it('rejects duplicate and unknown tag colors', function () {
    $workspace = actingInWorkspace();
    Tag::idsForNames($workspace->id, ['hot']);

    Livewire::test(ManageTags::class)
        ->callAction(TestAction::make('create'), data: ['name' => ' HOT ', 'color' => 'gray'])
        ->assertHasActionErrors(['name']);

    Livewire::test(ManageTags::class)
        ->callAction(TestAction::make('create'), data: ['name' => 'new', 'color' => 'hotpink'])
        ->assertHasActionErrors(['color']);

    expect(Tag::count())->toBe(1);
});

it('keeps tags private to the workspace and read-only for clients', function () {
    Tag::idsForNames(Workspace::factory()->create()->id, ['theirs']);
    $other = Tag::query()->where('name', 'theirs')->sole();
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    $mine = Tag::idsForNames($workspace->id, ['mine']);

    Livewire::test(ManageTags::class)
        ->assertCanSeeTableRecords(Tag::query()->whereKey($mine)->get())
        ->assertCanNotSeeTableRecords([$other])
        ->assertActionHidden(TestAction::make('create'));
});

it('sets a lead\'s tags in one step', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();
    $lead->syncTagNames(['old']);

    Livewire::test(ListLeads::class)
        ->callAction(TestAction::make('tagLead')->table($lead), data: ['tags' => ['hot', 'agency']])
        ->assertNotified('Tags saved');

    expect($lead->tags()->orderBy('name')->pluck('name')->all())->toBe(['agency', 'hot']);
});

// Star

it('stars a lead from the table and the lead page', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();
    $quiet = Lead::factory()->for($workspace)->create();

    Livewire::test(ListLeads::class)->callTableColumnAction('starred', $lead);
    expect($lead->refresh()->starred)->toBeTrue();

    Livewire::test(ListLeads::class)
        ->filterTable('starred', true)
        ->assertCanSeeTableRecords([$lead])
        ->assertCanNotSeeTableRecords([$quiet]);

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])->callAction('toggleStar');
    expect($lead->refresh()->starred)->toBeFalse();
});

it('does not let clients star leads', function () {
    $workspace = actingInWorkspace(role: WorkspaceRole::Client);
    $lead = Lead::factory()->for($workspace)->create();

    Livewire::test(ListLeads::class)->callTableColumnAction('starred', $lead);

    expect($lead->refresh()->starred)->toBeFalse();
});

// Follow-ups

it('sets, finds and clears a follow-up', function () {
    $workspace = actingInWorkspace();
    $workspace->forceFill(['timezone' => 'Asia/Dhaka'])->save();
    $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();
    $due = Lead::factory()->for($workspace)->create();
    $later = Lead::factory()->for($workspace)->create();

    Livewire::test(ViewLead::class, ['record' => $due->getRouteKey()])
        ->callAction('setFollowUp', data: ['on' => $today->toDateString()])
        ->assertHasNoActionErrors();
    Livewire::test(ViewLead::class, ['record' => $later->getRouteKey()])
        ->callAction('setFollowUp', data: ['on' => $today->addDays(5)->toDateString()]);

    expect($due->refresh()->follow_up_on->toDateString())->toBe($today->toDateString())
        ->and($due->follow_up_by)->toBe(auth()->id())
        ->and($due->activities()->where('type', LeadActivityType::FollowUpSet->value)->exists())->toBeTrue();

    Livewire::test(ListLeads::class)
        ->filterTable('follow_up_due', true)
        ->assertCanSeeTableRecords([$due])
        ->assertCanNotSeeTableRecords([$later]);

    Livewire::test(ViewLead::class, ['record' => $due->getRouteKey()])->callAction('followUpDone');

    expect($due->refresh()->follow_up_on)->toBeNull()
        ->and($due->activities()->where('type', LeadActivityType::FollowUpDone->value)->exists())->toBeTrue();
});

it('refuses a follow-up date in the past', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();

    Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
        ->callAction('setFollowUp', data: ['on' => now()->subDays(3)->toDateString()])
        ->assertHasActionErrors(['on']);

    expect($lead->refresh()->follow_up_on)->toBeNull();
});

it('counts due follow-ups on the Leads menu', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create();
    app(FollowUps::class)->set($lead, app(FollowUps::class)->today($workspace), auth()->user());
    app(FollowUps::class)->set(Lead::factory()->for($workspace)->create(), CarbonImmutable::now()->addWeek(), auth()->user());

    expect(LeadResource::getNavigationBadge())->toBe('1');
});

it('announces due follow-ups once, in the workspace morning, to the person who set them', function () {
    $workspace = actingInWorkspace();
    $workspace->forceFill(['timezone' => 'Asia/Dhaka'])->save();
    $user = auth()->user();
    $lead = Lead::factory()->for($workspace)->create(['first_name' => 'Jane', 'last_name' => 'Roe']);
    $followUps = app(FollowUps::class);
    $followUps->set($lead, CarbonImmutable::now('Asia/Dhaka')->toDateString(), $user);

    // 03:00 in Dhaka: too early.
    $this->travelTo(CarbonImmutable::now('Asia/Dhaka')->startOfDay()->addHours(3));
    expect($followUps->notifyDue())->toBe(0);

    // 09:00 in Dhaka.
    $this->travelTo(CarbonImmutable::now('Asia/Dhaka')->startOfDay()->addHours(9));
    expect($followUps->notifyDue())->toBe(1)
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toBe('1 follow-up due')
        ->and($user->notifications()->first()->data['body'])->toContain('Jane Roe');

    // Not announced twice.
    expect($followUps->notifyDue())->toBe(0)
        ->and($user->notifications()->count())->toBe(1);

    // Setting a new date announces again when it comes.
    $followUps->set($lead->refresh(), CarbonImmutable::now('Asia/Dhaka')->toDateString(), $user);
    expect($followUps->notifyDue())->toBe(1);
});

it('sends follow-ups nobody owns to the owners and admins', function () {
    $workspace = actingInWorkspace();
    $owner = auth()->user();
    $lead = Lead::factory()->for($workspace)->create();
    app(FollowUps::class)->set($lead, app(FollowUps::class)->today($workspace), null);
    DB::table('workspaces')->where('id', $workspace->id)->update(['timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::now('UTC')->startOfDay()->addHours(10));

    $this->artisan('leads:follow-ups')->assertSuccessful();

    expect($owner->notifications()->count())->toBe(1);
});

// Custom fields

it('shows links in custom fields as clickable, new-tab links, and never unsafe ones', function () {
    $workspace = actingInWorkspace();
    $lead = Lead::factory()->for($workspace)->create(['custom_fields' => [
        'facebook' => 'https://facebook.com/mainteractive1/',
        'sneaky' => 'javascript:alert(1)',
        'is_wordpress' => 'yes',
    ]]);

    $html = Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])->html();

    expect($html)
        ->toContain('href="https://facebook.com/mainteractive1/"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->not->toContain('href="javascript:')
        ->toContain('javascript:alert(1)')
        ->toContain('yes');
});
