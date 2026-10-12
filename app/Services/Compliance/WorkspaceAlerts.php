<?php

namespace App\Services\Compliance;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Database notifications for things the workspace's owners and admins must
 * act on (auto-paused sending, blacklist listings). Works from queued jobs.
 */
class WorkspaceAlerts
{
    public function send(int $workspaceId, string $title, string $body, ?string $url = null, ?string $linkLabel = null): void
    {
        $userIds = Membership::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->pluck('user_id');

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->danger();

        if ($url) {
            $notification->actions([Action::make('open')->label($linkLabel ?? 'Open')->url($url)]);
        }

        User::query()->whereIn('id', $userIds)->each(fn (User $user) => $notification->sendToDatabase($user));
    }
}
