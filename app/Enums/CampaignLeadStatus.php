<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A lead's progress through one campaign's sequence. The sending engine
 * (Phase 5) and reply detection (Phase 7) move it along.
 */
enum CampaignLeadStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Completed = 'completed';
    case Replied = 'replied';
    case Bounced = 'bounced';
    case Unsubscribed = 'unsubscribed';
    case Stopped = 'stopped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'In sequence',
            self::Completed => 'Sequence finished',
            self::Replied => 'Replied',
            self::Bounced => 'Bounced',
            self::Unsubscribed => 'Unsubscribed',
            self::Stopped => 'Stopped',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Completed => 'gray',
            self::Replied => 'success',
            self::Bounced => 'danger',
            self::Unsubscribed => 'warning',
            self::Stopped => 'gray',
        };
    }
}
