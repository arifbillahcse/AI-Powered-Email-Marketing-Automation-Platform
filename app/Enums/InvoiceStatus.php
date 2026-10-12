<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoiceStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Due',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Paid => 'success',
            self::Void => 'gray',
        };
    }
}
