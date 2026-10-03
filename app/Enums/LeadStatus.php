<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a lead is in the outreach lifecycle. Campaign sending (Phase 5) and
 * reply handling (Phase 7) move leads along; users can also set it by hand.
 */
enum LeadStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Contacted = 'contacted';
    case Replied = 'replied';
    case Interested = 'interested';
    case MeetingBooked = 'meeting_booked';
    case NotInterested = 'not_interested';
    case Closed = 'closed';
    case Bounced = 'bounced';
    case Unsubscribed = 'unsubscribed';

    /**
     * Labels set from the Unibox after a reply.
     *
     * @return list<self>
     */
    public static function replyLabels(): array
    {
        return [self::Interested, self::MeetingBooked, self::NotInterested, self::Closed];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Replied => 'Replied',
            self::Interested => 'Interested',
            self::MeetingBooked => 'Meeting booked',
            self::NotInterested => 'Not interested',
            self::Closed => 'Closed',
            self::Bounced => 'Bounced',
            self::Unsubscribed => 'Unsubscribed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::Contacted => 'info',
            self::Replied, self::Interested, self::MeetingBooked => 'success',
            self::NotInterested => 'gray',
            self::Closed => 'primary',
            self::Bounced => 'danger',
            self::Unsubscribed => 'warning',
        };
    }
}
