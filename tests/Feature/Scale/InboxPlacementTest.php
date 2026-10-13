<?php

use App\Enums\MailProvider;
use App\Filament\App\Pages\InboxPlacement;
use App\Jobs\CheckPlacementSeed;
use App\Jobs\SendPlacementTest;
use App\Models\EmailAccount;
use App\Models\PlacementResult;
use App\Models\PlacementTest;
use App\Models\Workspace;
use App\Services\Mail\Imap\ImapConnector;
use App\Services\Placement\PlacementException;
use App\Services\Placement\PlacementTester;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\ScriptedImapStream;

beforeEach(function () {
    config(['modules.inbox_placement.enabled' => true]);
    $this->seeds = Workspace::factory()->create(['slug' => 'seed-accounts']);
    config(['outreach.placement.seed_workspace' => 'seed-accounts']);
});

function placementHeaderResponse(string $tag, int $uid, string $token): array
{
    $literal = "X-Placement: {$token}\r\n\r\n";

    return [
        '* 1 FETCH (UID '.$uid.' BODY[HEADER.FIELDS (X-PLACEMENT)] {'.strlen($literal).'}',
        ['literal' => $literal],
        ')',
        "{$tag} OK FETCH completed",
    ];
}

it('sends the test email to every seed with its own token', function () {
    $transport = fakeTransport();
    $workspace = Workspace::factory()->create();
    $sender = EmailAccount::factory()->for($workspace)->create(['email' => 'arif@softorio.com']);
    EmailAccount::factory()->for($this->seeds)->create(['email' => 'seed1@gmail.com', 'provider' => MailProvider::Google]);
    EmailAccount::factory()->for($this->seeds)->create(['email' => 'seed2@outlook.com']);
    EmailAccount::factory()->for($workspace)->create(['email' => 'other@hostorio.com']);

    $test = app(PlacementTester::class)->start($workspace, $sender, null, 'Quick question', 'Hi there');

    $results = PlacementResult::query()->orderBy('id')->get();

    expect($results->pluck('provider')->all())->toBe(['Gmail', 'Outlook'])
        ->and($transport->sent)->toHaveCount(2)
        ->and($transport->sent[0]->getHeaders()->get('X-Placement')->getBodyAsString())->toBe($results[0]->token)
        ->and($transport->sent[0]->getSubject())->toBe('Quick question')
        ->and($test->refresh())->status->toBe('checking')->sent_at->not->toBeNull();
});

it('can include the workspace\'s own mailboxes and refuses without seeds', function () {
    config(['outreach.placement.seed_workspace' => null]);
    Queue::fake();
    $workspace = Workspace::factory()->create();
    $sender = EmailAccount::factory()->for($workspace)->create();

    expect(fn () => app(PlacementTester::class)->start($workspace, $sender, null, 'Hi', 'Hi'))->toThrow(PlacementException::class);

    EmailAccount::factory()->for($workspace)->create();
    app(PlacementTester::class)->start($workspace, $sender, null, 'Hi', 'Hi', includeOwn: true);

    expect(PlacementResult::count())->toBe(1);
    Queue::assertPushed(SendPlacementTest::class);
});

it('records inbox and spam placement from the seed mailbox', function () {
    config(['outreach.mailboxes.allow_private_hosts' => true]);
    $workspace = Workspace::factory()->create();
    $sender = EmailAccount::factory()->for($workspace)->create();
    $seed = EmailAccount::factory()->for($this->seeds)->create();
    $test = PlacementTest::query()->create(['workspace_id' => $workspace->id, 'email_account_id' => $sender->id, 'subject' => 'Hi', 'body' => 'Hi', 'status' => 'checking', 'sent_at' => now()]);
    $inbox = $test->results()->create(['seed_id' => $seed->id, 'provider' => 'Other', 'token' => str_repeat('a', 40)]);
    $spam = $test->results()->create(['seed_id' => $seed->id, 'provider' => 'Other', 'token' => str_repeat('b', 40)]);

    $stream = new ScriptedImapStream([
        '* OK IMAP ready',
        'A001 OK LOGIN completed',
        '* LIST (\HasNoChildren) "/" "INBOX"',
        '* LIST (\HasNoChildren \Junk) "/" "Junk"',
        'A002 OK LIST completed',
        'A003 OK [READ-ONLY] EXAMINE completed',
        '* SEARCH 4',
        'A004 OK SEARCH completed',
        ...placementHeaderResponse('A005', 4, str_repeat('a', 40)),
        'A006 OK [READ-ONLY] EXAMINE completed',
        '* SEARCH 9',
        'A007 OK SEARCH completed',
        ...placementHeaderResponse('A008', 9, str_repeat('b', 40)),
    ]);
    app()->instance(ImapConnector::class, $stream->connector());

    app()->call([new CheckPlacementSeed($seed->id), 'handle']);

    expect($stream->written)->toContain('A003 EXAMINE "INBOX"', 'A006 EXAMINE "Junk"')
        ->and($inbox->refresh()->status)->toBe('inbox')
        ->and($spam->refresh()->status)->toBe('spam');
});

it('marks unfound emails missing and finishes the test', function () {
    Queue::fake();
    $workspace = actingInWorkspace();
    $sender = EmailAccount::factory()->for($workspace)->create();
    $seed = EmailAccount::factory()->for($this->seeds)->create();
    $test = PlacementTest::query()->create(['workspace_id' => $workspace->id, 'email_account_id' => $sender->id, 'created_by' => auth()->id(), 'subject' => 'Hi', 'body' => 'Hi', 'status' => 'checking', 'sent_at' => now()->subMinutes(5)]);
    $test->results()->create(['seed_id' => $seed->id, 'provider' => 'Gmail', 'token' => str_repeat('c', 40), 'status' => 'inbox']);
    $pending = $test->results()->create(['seed_id' => $seed->id, 'provider' => 'Gmail', 'token' => str_repeat('d', 40)]);

    $this->artisan('placement:check')->assertSuccessful();
    Queue::assertPushed(CheckPlacementSeed::class, fn (CheckPlacementSeed $job): bool => $job->seedId === $seed->id);

    $this->travel(40)->minutes();
    $this->artisan('placement:check')->assertSuccessful();

    expect($pending->refresh()->status)->toBe('missing')
        ->and($test->refresh()->status)->toBe('done')
        ->and(app(PlacementTester::class)->summary($test))->toMatchArray(['inbox' => 1, 'missing' => 1, 'rate' => 50]);

    // Filament database notifications are queued.
    Queue::assertPushed(SendQueuedNotifications::class);
});

it('starts a test from the page', function () {
    Queue::fake();
    $workspace = actingInWorkspace();
    $sender = EmailAccount::factory()->for($workspace)->create();
    EmailAccount::factory()->for($this->seeds)->create();

    Livewire::test(InboxPlacement::class)
        ->callAction('newTest', data: ['email_account_id' => $sender->id, 'subject' => 'Hello', 'body' => 'Hi there', 'include_own' => false])
        ->assertNotified('Test started');

    expect(PlacementTest::sole()->email_account_id)->toBe($sender->id);
});

it('is locked when the plan doesn\'t include it', function () {
    $plan = billingOn(['modules' => ['ai']]);
    $workspace = actingInWorkspace();
    subscribe($workspace, $plan);

    Livewire::test(InboxPlacement::class)
        ->assertSee('Inbox placement tests aren\'t included in your plan')
        ->assertActionHidden('newTest');
});
