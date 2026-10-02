<?php

namespace App\Services\Mail;

use App\Enums\MailEncryption;
use App\Models\EmailAccount;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the SMTP transport for a mailbox. The sending engine (Phase 5)
 * uses this too, so connection tests and real sends behave identically.
 */
class MailboxTransportFactory
{
    public function __construct(
        protected HostGuard $guard,
        protected int $timeout = 10,
    ) {}

    public function make(EmailAccount $account): TransportInterface
    {
        $this->guard->assertAllowed($account->smtp_host);

        $transport = new EsmtpTransport(
            $account->smtp_host,
            $account->smtp_port,
            $account->smtp_encryption === MailEncryption::Ssl,
        );

        // STARTTLS is used automatically when offered; "none" turns it off.
        $transport->setAutoTls($account->smtp_encryption !== MailEncryption::None);
        $transport->setUsername($account->smtpUsername());
        $transport->setPassword($account->smtp_password);

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout($this->timeout);
        }

        return $transport;
    }
}
