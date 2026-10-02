<?php

namespace App\Filament\App\Concerns;

use App\Exceptions\TeamActionException;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\TeamManager;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

trait HandlesTeamActions
{
    protected function teamManager(): TeamManager
    {
        return app(TeamManager::class);
    }

    protected function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }

    protected function actor(): User
    {
        /** @var User */
        return Auth::user();
    }

    protected function canManageTeam(): bool
    {
        return $this->actor()->can('manageTeam', $this->workspace());
    }

    /**
     * Run a team operation, turning rule violations into a danger toast.
     */
    protected function attempt(Closure $operation, string $successTitle): bool
    {
        try {
            $operation();
        } catch (TeamActionException|AuthorizationException $exception) {
            Notification::make()
                ->title($exception instanceof AuthorizationException ? 'You don\'t have permission to do that.' : $exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()->title($successTitle)->success()->send();

        return true;
    }
}
