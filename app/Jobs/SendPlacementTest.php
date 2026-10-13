<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\PlacementResult;
use App\Models\PlacementTest;
use App\Services\Mail\MailboxConnectionException;
use App\Services\Mail\MailboxTransportFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a placement test's email to each of its seed mailboxes, over one
 * SMTP connection. Not retried (a retry could send twice).
 */
class SendPlacementTest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(
        public int $placementTestId,
    ) {
        $this->onQueue('sending');
    }

    public function handle(MailboxTransportFactory $transports): void
    {
        $test = PlacementTest::query()->find($this->placementTestId);

        if (! $test || $test->status !== 'sending') {
            return;
        }

        $sender = EmailAccount::query()->find($test->email_account_id);

        if (! $sender || ! $sender->isActive()) {
            $this->markFailed($test, 'The mailbox is no longer active.');

            return;
        }

        try {
            $transport = $transports->make($sender);
        } catch (MailboxConnectionException $exception) {
            $this->markFailed($test, $exception->getMessage());

            return;
        }

        $started = microtime(true);
        $results = PlacementResult::query()->where('placement_test_id', $test->getKey())->where('status', 'pending')->with('seed')->get();

        foreach ($results as $result) {
            if (! $result->seed || microtime(true) - $started > 40) {
                $result->forceFill(['status' => 'failed', 'checked_at' => now()])->save();

                continue;
            }

            $email = (new Email)
                ->from(new Address($sender->email, $sender->from_name))
                ->to(new Address($result->seed->email))
                ->subject($test->subject)
                ->text($test->body)
                ->html(nl2br(e($test->body), false));

            $email->getHeaders()->addIdHeader('Message-ID', Str::uuid()->toString().'@'.$sender->domain());
            $email->getHeaders()->addTextHeader((string) config('outreach.placement.header'), $result->token);

            try {
                $transport->send($email);
            } catch (MailboxConnectionException|TransportExceptionInterface) {
                $result->forceFill(['status' => 'failed', 'checked_at' => now()])->save();
            }
        }

        $test->forceFill(['status' => 'checking', 'sent_at' => now()])->save();
    }

    protected function markFailed(PlacementTest $test, string $error): void
    {
        $test->forceFill(['status' => 'failed', 'error' => mb_substr(strtok($error, "\n") ?: 'Sending failed.', 0, 500), 'finished_at' => now()])->save();
        PlacementResult::query()->where('placement_test_id', $test->getKey())->update(['status' => 'failed', 'checked_at' => now()]);
    }
}
