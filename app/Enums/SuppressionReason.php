<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SuppressionReason: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case Complaint = 'complaint';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Added manually',
            self::Unsubscribed => 'Unsubscribed',
            self::Bounced => 'Hard bounce',
            self::Complaint => 'Spam complaint',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Unsubscribed => 'warning',
            self::Bounced, self::Complaint => 'danger',
        };
    }
}
