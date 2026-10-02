<?php

namespace App\Services\Mail;

use App\Models\EmailAccount;
use App\Services\Mail\Imap\ImapProbe;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

/**
 * Logs in to a mailbox's SMTP and IMAP servers without sending anything.
 */
class MailboxConnectionTester
{
    public function __construct(
        protected MailboxTransportFactory $transports,
        protected ImapProbe $imap,
        protected HostGuard $guard,
    ) {}

    public function test(EmailAccount $account): ConnectionTestResult
    {
        return new ConnectionTestResult(
            smtpError: $this->testSmtp($account),
            imapError: $this->testImap($account),
        );
    }

    /**
     * Test and store the outcome on the account (status, last_error).
     */
    public function testAndRecord(EmailAccount $account): ConnectionTestResult
    {
        $result = $this->test($account);

        $account->markTested($result->error());

        return $result;
    }

    protected function testSmtp(EmailAccount $account): ?string
    {
        try {
            $transport = $this->transports->make($account);

            if ($transport instanceof SmtpTransport) {
                $transport->start();
                $transport->stop();
            }
        } catch (MailboxConnectionException $exception) {
            return $exception->getMessage();
        } catch (TransportExceptionInterface $exception) {
            return $this->describeSmtpFailure($exception);
        }

        return null;
    }

    protected function testImap(EmailAccount $account): ?string
    {
        try {
            $this->guard->assertAllowed($account->imap_host);

            $this->imap->check(
                $account->imap_host,
                $account->imap_port,
                $account->imap_encryption,
                $account->imapUsername(),
                $account->imapPassword(),
            );
        } catch (MailboxConnectionException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    protected function describeSmtpFailure(TransportExceptionInterface $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, '535') || str_contains($message, 'authenticate') => 'SMTP login failed. Check the username and password (many providers need an app password).',
            str_contains($message, 'Connection could not be established') => 'Couldn\'t connect to the SMTP server. Check the host, port and encryption.',
            default => 'SMTP error: '.strtok($message, "\n"),
        };
    }
}
