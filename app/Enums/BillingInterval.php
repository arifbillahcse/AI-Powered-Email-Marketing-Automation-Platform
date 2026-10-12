<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

enum BillingInterval: string implements HasLabel
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }

    public function addTo(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $date->copy()->addMonthNoOverflow(),
            self::Yearly => $date->copy()->addYearNoOverflow(),
        };
    }
}
