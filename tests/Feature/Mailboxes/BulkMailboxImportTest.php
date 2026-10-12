<?php

use App\Enums\MailEncryption;
use App\Enums\MailProvider;
use App\Filament\App\Resources\EmailAccounts\Pages\ListEmailAccounts;
use App\Jobs\TestMailboxConnection;
use App\Models\EmailAccount;
use App\Services\Mail\MailboxProvisioner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function mailboxCsv(): string
{
    return implode("\n", [
        'email,from_name,smtp_host,smtp_port,smtp_encryption,smtp_password,imap_host,imap_port,daily_limit,provider',
        'one@softorio.com,Arif,mail.softorio.com,465,ssl,secret-1,mail.softorio.com,993,40,',
        'two@gmail.com,,,,,app-password,,,,google',
        'broken@softorio.com,Broken,mail.softorio.com,587,tls,,mail.softorio.com,993,,',
    ]);
}

it('imports many mailboxes from CSV', function () {
    $workspace = actingInWorkspace();

    $result = app(MailboxProvisioner::class)->import($workspace, mailboxCsv());

    $one = EmailAccount::query()->where('email', 'one@softorio.com')->sole();
    $two = EmailAccount::query()->where('email', 'two@gmail.com')->sole();

    expect(count($result['created']))->toBe(2)
        ->and($result['errors'])->toBe(['Row 4 (broken@softorio.com): The smtp password field is required.'])
        ->and($one->smtp_encryption)->toBe(MailEncryption::Ssl)
        ->and($one->daily_limit)->toBe(40)
        ->and($one->smtp_password)->toBe('secret-1')
        ->and($two->provider)->toBe(MailProvider::Google)
        ->and($two->smtp_host)->toBe('smtp.gmail.com')
        ->and($two->from_name)->toBe('Two')
        ->and($two->daily_limit)->toBe(config('outreach.mailboxes.default_daily_limit'));

    // Importing again updates instead of duplicating.
    $again = app(MailboxProvisioner::class)->import($workspace, mailboxCsv());
    expect(count($again['updated']))->toBe(2)->and(EmailAccount::count())->toBe(2);
});

it('respects the plan mailbox limit', function () {
    $plan = billingOn(['limits' => ['mailboxes' => 1]]);
    $workspace = actingInWorkspace();
    subscribe($workspace, $plan);

    $result = app(MailboxProvisioner::class)->import($workspace, mailboxCsv());

    expect(count($result['created']))->toBe(1)
        ->and($result['errors'])->toContain('Row 3 (two@gmail.com): your plan\'s mailbox limit is reached.');
});

it('imports from the mailboxes page and tests each one in the background', function () {
    Queue::fake();
    actingInWorkspace();

    Livewire::test(ListEmailAccounts::class)
        ->callAction('bulkImport', data: ['file' => UploadedFile::fake()->createWithContent('mailboxes.csv', mailboxCsv())])
        ->assertNotified('2 added, 0 updated');

    Queue::assertPushed(TestMailboxConnection::class, 2);
});
