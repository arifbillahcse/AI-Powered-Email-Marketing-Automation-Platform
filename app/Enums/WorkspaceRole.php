<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum WorkspaceRole: string implements HasColor, HasDescription, HasLabel
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Client = 'client';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Client => 'Client',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Owner => 'Full access, including billing and deleting the workspace.',
            self::Admin => 'Manage campaigns, mailboxes, team members and settings.',
            self::Member => 'Run campaigns, manage leads and reply from the Unibox.',
            self::Client => 'View-only access to campaigns and reports.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Owner => 'primary',
            self::Admin => 'info',
            self::Member => 'gray',
            self::Client => 'warning',
        };
    }

    /**
     * Invite settings, roles and members.
     */
    public function canManageTeam(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /**
     * Create and change workspace data (campaigns, leads, mailboxes...).
     */
    public function canWrite(): bool
    {
        return $this !== self::Client;
    }

    /**
     * Roles that can be given through invitations or role changes.
     * Ownership is never handed out this way.
     *
     * @return array<string, string>
     */
    public static function assignableOptions(): array
    {
        return collect([self::Admin, self::Member, self::Client])
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->getLabel()])
            ->all();
    }
}
