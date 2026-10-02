<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Mail\MailboxConnectionException;
use App\Services\Mail\MailboxTransportFactory;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends one real email through a mailbox so the user can confirm delivery
 * end to end. The result arrives as an in-app notification.
 */
class SendTestEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public EmailAccount $account,
        public string $to,
        public User $requestedBy,
    ) {
        $this->onQueue('sending');
    }

    public function handle(MailboxTransportFactory $transports): void
    {
        try {
            $transports->make($this->account)->send($this->message());
        } catch (MailboxConnectionException|TransportExceptionInterface $exception) {
            $error = $exception instanceof MailboxConnectionException
                ? $exception->getMessage()
                : 'SMTP error: '.strtok($exception->getMessage(), "\n");

            $this->account->markTested($error);

            Notification::make()
                ->title("Test email from {$this->account->email} failed")
                ->body($error)
                ->danger()
                ->sendToDatabase($this->requestedBy);

            return;
        }

        $this->account->markTested(null);

        Notification::make()
            ->title("Test email sent from {$this->account->email}")
            ->body("Check {$this->to}, including the spam folder.")
            ->success()
            ->sendToDatabase($this->requestedBy);
    }

    protected function message(): Email
    {
        $app = config('app.name');
        $text = "This is a test email from {$app}.\n\nIf you can read this, {$this->account->email} is connected and can send.";
        $html = '<p>'.nl2br(e($text)).'</p>';

        if (filled($this->account->signature)) {
            $html .= '<br>'.$this->account->signature;
        }

        return (new Email)
            ->from(new Address($this->account->email, $this->account->from_name))
            ->to($this->to)
            ->subject("Test email from {$app}")
            ->text($text)
            ->html($html);
    }
}
