<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailVerificationStatus: string implements HasColor, HasLabel
{
    case Valid = 'valid';
    case Risky = 'risky';
    case Invalid = 'invalid';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Risky => 'Risky',
            self::Invalid => 'Invalid',
            self::Unknown => 'Unknown',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Valid => 'success',
            self::Risky => 'warning',
            self::Invalid => 'danger',
            self::Unknown => 'gray',
        };
    }
}
