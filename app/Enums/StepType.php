<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What a sequence step does. Email steps are sent automatically; the others
 * become tasks for the team and the lead waits until the task is done.
 */
enum StepType: string implements HasColor, HasIcon, HasLabel
{
    case Email = 'email';
    case LinkedinVisit = 'linkedin_visit';
    case LinkedinConnect = 'linkedin_connect';
    case LinkedinMessage = 'linkedin_message';
    case Call = 'call';
    case Task = 'task';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::LinkedinVisit => 'LinkedIn: view profile',
            self::LinkedinConnect => 'LinkedIn: connection request',
            self::LinkedinMessage => 'LinkedIn: message',
            self::Call => 'Phone call',
            self::Task => 'Other task',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Email => Heroicon::OutlinedEnvelope,
            self::LinkedinVisit => Heroicon::OutlinedEye,
            self::LinkedinConnect => Heroicon::OutlinedUserPlus,
            self::LinkedinMessage => Heroicon::OutlinedChatBubbleLeftRight,
            self::Call => Heroicon::OutlinedPhone,
            self::Task => Heroicon::OutlinedClipboardDocumentCheck,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Email => 'primary',
            self::Call => 'success',
            self::Task => 'gray',
            default => 'info',
        };
    }

    public function isManual(): bool
    {
        return $this !== self::Email;
    }
}
