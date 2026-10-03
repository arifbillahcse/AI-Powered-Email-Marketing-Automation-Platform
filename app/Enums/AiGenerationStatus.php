<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AiGenerationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Generating',
            self::Ready => 'Needs review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Ready => 'info',
            self::Approved => 'success',
            self::Rejected => 'warning',
            self::Failed => 'danger',
        };
    }
}
