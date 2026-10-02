<?php

use App\Enums\EmailAccountStatus;
use App\Jobs\SendTestEmail;
use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Mail\MailboxTransportFactory;
use Tests\Fakes\RecordingTransport;

function fakeTransport(?string $failWith = null): RecordingTransport
{
    $transport = new RecordingTransport($failWith);

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

it('sends a real message through the mailbox and notifies the user', function () {
    $transport = fakeTransport();
    $user = User::factory()->create();
    $account = EmailAccount::factory()->create([
        'email' => 'arif@softorio.com',
        'from_name' => 'Arif',
        'signature' => '<p>Arif | Softorio</p>',
    ]);

    SendTestEmail::dispatchSync($account, 'inbox@example.com', $user);

    $message = $transport->sent[0];

    expect($message->getFrom()[0]->getAddress())->toBe('arif@softorio.com')
        ->and($message->getFrom()[0]->getName())->toBe('Arif')
        ->and($message->getTo()[0]->getAddress())->toBe('inbox@example.com')
        ->and($message->getHtmlBody())->toContain('Arif | Softorio')
        ->and($account->refresh()->last_tested_at)->not->toBeNull()
        ->and($user->notifications()->sole()->data['title'])->toBe('Test email sent from arif@softorio.com');
});

it('records the error and notifies the user when sending fails', function () {
    fakeTransport('535 5.7.8 Authentication failed');
    $user = User::factory()->create();
    $account = EmailAccount::factory()->create();

    SendTestEmail::dispatchSync($account, 'inbox@example.com', $user);

    expect($account->refresh()->status)->toBe(EmailAccountStatus::Error)
        ->and($account->last_error)->toContain('535')
        ->and($user->notifications()->sole()->data['status'])->toBe('danger');
});
