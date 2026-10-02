<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MailEncryption: string implements HasLabel
{
    /** Implicit TLS from the first byte (SMTP 465, IMAP 993). */
    case Ssl = 'ssl';

    /** Plain connection upgraded with STARTTLS (SMTP 587, IMAP 143). */
    case Tls = 'tls';

    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ssl => 'SSL/TLS',
            self::Tls => 'STARTTLS',
            self::None => 'None (insecure)',
        };
    }
}
