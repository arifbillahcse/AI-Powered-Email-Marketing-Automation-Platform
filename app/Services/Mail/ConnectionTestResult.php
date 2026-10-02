<?php

namespace App\Services\Mail;

final readonly class ConnectionTestResult
{
    public function __construct(
        public ?string $smtpError = null,
        public ?string $imapError = null,
    ) {}

    public function passed(): bool
    {
        return $this->smtpError === null && $this->imapError === null;
    }

    public function error(): ?string
    {
        $errors = array_filter([
            $this->smtpError ? "SMTP: {$this->smtpError}" : null,
            $this->imapError ? "IMAP: {$this->imapError}" : null,
        ]);

        return $errors === [] ? null : implode(' ', $errors);
    }
}
