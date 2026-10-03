<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailMessageStatus: string implements HasColor, HasLabel
{
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Bounced = 'bounced';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Bounced => 'Bounced',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sending => 'gray',
            self::Sent => 'success',
            self::Failed => 'warning',
            self::Bounced => 'danger',
        };
    }
}
