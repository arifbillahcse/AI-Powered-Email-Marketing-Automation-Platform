<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SuppressionType: string implements HasLabel
{
    case Email = 'email';
    case Domain = 'domain';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'Email address',
            self::Domain => 'Whole domain',
        };
    }
}
