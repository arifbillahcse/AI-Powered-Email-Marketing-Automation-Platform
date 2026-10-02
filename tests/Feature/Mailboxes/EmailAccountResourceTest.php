<?php

use App\Enums\EmailAccountStatus;
use App\Enums\MailEncryption;
use App\Enums\WorkspaceRole;
use App\Filament\App\Resources\EmailAccounts\Pages\CreateEmailAccount;
use App\Filament\App\Resources\EmailAccounts\Pages\EditEmailAccount;
use App\Filament\App\Resources\EmailAccounts\Pages\ListEmailAccounts;
use App\Jobs\SendTestEmail;
use App\Models\EmailAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Mail\ConnectionTestResult;
use App\Services\Mail\MailboxConnectionTester;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeMailboxConnectionTester;

function fakeConnectionTester(?string $smtpError = null, ?string $imapError = null): FakeMailboxConnectionTester
{
    $tester = new FakeMailboxConnectionTester(new ConnectionTestResult($smtpError, $imapError));
    app()->instance(MailboxConnectionTester::class, $tester);

    return $tester;
}

function mailboxFormData(array $overrides = []): array
{
    return array_merge([
        'provider' => 'custom',
        'email' => 'arif@softorio.com',
        'from_name' => 'Arif from Softorio',
        'smtp_host' => 'smtp.softorio.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_password' => 'super-secret',
        'imap_host' => 'imap.softorio.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'daily_limit' => 40,
        'min_delay_seconds' => 60,
        'max_delay_seconds' => 240,
        'send_window_start' => '08:00',
        'send_window_end' => '18:00',
        'send_days' => ['1', '2', '3'],
    ], $overrides);
}

it('connects a mailbox, tests it and links its domain', function () {
    $tester = fakeConnectionTester();
    $workspace = actingInWorkspace();

    Livewire::test(CreateEmailAccount::class)
        ->fillForm(mailboxFormData())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Connected');

    $account = EmailAccount::sole();

    expect($account->workspace_id)->toBe($workspace->id)
        ->and($account->status)->toBe(EmailAccountStatus::Active)
        ->and($account->send_days)->toBe([1, 2, 3])
        ->and($account->smtp_encryption)->toBe(MailEncryption::Tls)
        ->and($account->last_tested_at)->not->toBeNull()
        ->and($account->sendingDomain->name)->toBe('softorio.com')
        ->and($tester->tested)->toBe(['arif@softorio.com']);
});

it('marks a mailbox as failing when the connection test fails', function () {
    fakeConnectionTester(imapError: 'IMAP login failed.');
    actingInWorkspace();

    Livewire::test(CreateEmailAccount::class)
        ->fillForm(mailboxFormData())
        ->call('create')
        ->assertNotified('Connection failed');

    $account = EmailAccount::sole();

    expect($account->status)->toBe(EmailAccountStatus::Error)
        ->and($account->last_error)->toBe('IMAP: IMAP login failed.');
});

it('encrypts credentials at rest and never sends them to the browser', function () {
    fakeConnectionTester();
    actingInWorkspace();

    Livewire::test(CreateEmailAccount::class)->fillForm(mailboxFormData())->call('create');

    $raw = DB::table('email_accounts')->value('smtp_password');
    $account = EmailAccount::sole();

    expect($raw)->not->toContain('super-secret')
        ->and($account->smtp_password)->toBe('super-secret')
        ->and($account->toArray())->not->toHaveKey('smtp_password');

    Livewire::test(EditEmailAccount::class, ['record' => $account->getRouteKey()])
        ->assertSchemaStateSet(['smtp_password' => null])
        ->assertDontSee('super-secret');
});

it('keeps the saved password when the field is left blank', function () {
    fakeConnectionTester();
    $workspace = actingInWorkspace();
    $account = EmailAccount::factory()->for($workspace)->create(['smtp_password' => 'original']);

    Livewire::test(EditEmailAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['from_name' => 'New Name', 'smtp_password' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->refresh()->from_name)->toBe('New Name')
        ->and($account->smtp_password)->toBe('original');
});

it('validates limits and the send window', function () {
    fakeConnectionTester();
    actingInWorkspace();

    Livewire::test(CreateEmailAccount::class)
        ->fillForm(mailboxFormData([
            'daily_limit' => 5000,
            'min_delay_seconds' => 5,
            'max_delay_seconds' => 1,
            'send_window_start' => '18:00',
            'send_window_end' => '09:00',
        ]))
        ->call('create')
        ->assertHasFormErrors(['daily_limit', 'min_delay_seconds', 'max_delay_seconds', 'send_window_end']);
});

it('does not allow the same mailbox twice in a workspace', function () {
    fakeConnectionTester();
    $workspace = actingInWorkspace();
    EmailAccount::factory()->for($workspace)->create(['email' => 'arif@softorio.com']);

    Livewire::test(CreateEmailAccount::class)
        ->fillForm(mailboxFormData())
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);
});

it('fills server settings from a provider preset', function () {
    actingInWorkspace();

    Livewire::test(CreateEmailAccount::class)
        ->fillForm(['provider' => 'microsoft'])
        ->assertSchemaStateSet([
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'imap_host' => 'outlook.office365.com',
        ]);
});

it('only lists the current workspace\'s mailboxes', function () {
    // Created before entering the workspace: inside it, Filament attaches
    // every new record to the current workspace.
    $theirs = EmailAccount::factory()->create();
    $workspace = actingInWorkspace();
    $mine = EmailAccount::factory()->for($workspace)->create();

    expect($theirs->refresh()->workspace_id)->not->toBe($workspace->id);

    Livewire::test(ListEmailAccounts::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('blocks access to another workspace\'s mailbox', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user)->create();
    $theirs = EmailAccount::factory()->create();

    $this->actingAs($user)
        ->get("/app/{$workspace->slug}/email-accounts/{$theirs->id}/edit")
        ->assertNotFound();
});

it('makes mailboxes read-only for clients', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->withMember($user, WorkspaceRole::Client)->create();
    $account = EmailAccount::factory()->for($workspace)->create();

    $this->actingAs($user)->get("/app/{$workspace->slug}/email-accounts")->assertOk();
    $this->actingAs($user)->get("/app/{$workspace->slug}/email-accounts/create")->assertForbidden();
    $this->actingAs($user)->get("/app/{$workspace->slug}/email-accounts/{$account->id}/edit")->assertForbidden();

    actingInWorkspace($user, workspace: $workspace, role: WorkspaceRole::Client);

    Livewire::test(ListEmailAccounts::class)
        ->assertActionHidden(TestAction::make('testConnection')->table($account))
        ->assertActionHidden(TestAction::make('sendTestEmail')->table($account));
});

it('queues a test email from the table', function () {
    Queue::fake();
    $workspace = actingInWorkspace();
    $account = EmailAccount::factory()->for($workspace)->create();

    Livewire::test(ListEmailAccounts::class)
        ->callAction(TestAction::make('sendTestEmail')->table($account), data: ['to' => 'inbox@example.com'])
        ->assertNotified('Test email queued');

    Queue::assertPushedOn('sending', SendTestEmail::class, fn (SendTestEmail $job) => $job->to === 'inbox@example.com' && $job->account->is($account));
});

it('pauses and resumes a mailbox', function () {
    fakeConnectionTester();
    $workspace = actingInWorkspace();
    $account = EmailAccount::factory()->for($workspace)->create();

    Livewire::test(ListEmailAccounts::class)
        ->callAction(TestAction::make('pause')->table($account));
    expect($account->refresh()->status)->toBe(EmailAccountStatus::Paused);

    Livewire::test(ListEmailAccounts::class)
        ->callAction(TestAction::make('resume')->table($account));
    expect($account->refresh()->status)->toBe(EmailAccountStatus::Active);
});

it('shows the warmup tab as locked while the module is off', function () {
    config(['modules.warmup.enabled' => false]);
    actingInWorkspace();

    Livewire::test(CreateEmailAccount::class)
        ->assertSee('Inbox warmup is coming soon');
});
