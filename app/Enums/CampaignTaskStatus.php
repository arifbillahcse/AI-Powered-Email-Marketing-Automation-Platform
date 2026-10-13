<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CampaignTaskStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Done = 'done';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'To do',
            self::Done => 'Done',
            self::Skipped => 'Skipped',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Done => 'success',
            self::Skipped => 'gray',
        };
    }
}
