<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Timeline entries on a lead. Later phases add sent/opened/clicked/replied.
 */
enum LeadActivityType: string implements HasColor, HasIcon, HasLabel
{
    case Created = 'created';
    case Imported = 'imported';
    case Updated = 'updated';
    case AddedToList = 'added_to_list';
    case RemovedFromList = 'removed_from_list';
    case Tagged = 'tagged';
    case Untagged = 'untagged';
    case StatusChanged = 'status_changed';
    case Suppressed = 'suppressed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Imported => 'Imported',
            self::Updated => 'Updated',
            self::AddedToList => 'Added to list',
            self::RemovedFromList => 'Removed from list',
            self::Tagged => 'Tagged',
            self::Untagged => 'Tag removed',
            self::StatusChanged => 'Status changed',
            self::Suppressed => 'Suppressed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Created, self::Imported => 'primary',
            self::Suppressed => 'danger',
            self::StatusChanged => 'info',
            default => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Created => Heroicon::OutlinedUserPlus,
            self::Imported => Heroicon::OutlinedArrowUpTray,
            self::Updated => Heroicon::OutlinedPencilSquare,
            self::AddedToList, self::RemovedFromList => Heroicon::OutlinedQueueList,
            self::Tagged, self::Untagged => Heroicon::OutlinedTag,
            self::StatusChanged => Heroicon::OutlinedArrowsRightLeft,
            self::Suppressed => Heroicon::OutlinedNoSymbol,
        };
    }
}
