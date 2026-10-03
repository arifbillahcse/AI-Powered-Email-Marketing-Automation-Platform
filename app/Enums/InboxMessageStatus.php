<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InboxMessageStatus: string implements HasColor, HasLabel
{
    case Received = 'received';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Received => 'gray',
            self::Sending => 'info',
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }
}
