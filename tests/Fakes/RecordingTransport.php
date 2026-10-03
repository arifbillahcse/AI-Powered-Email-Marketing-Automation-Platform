<?php

namespace Tests\Fakes;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class RecordingTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function __construct(
        public ?string $failWith = null,
        public int $failCode = 0,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->failWith !== null) {
            throw new TransportException($this->failWith, $this->failCode);
        }

        $this->sent[] = $message;

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}
