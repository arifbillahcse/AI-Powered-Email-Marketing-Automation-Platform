<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeMailNotificationsTo('ops@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#queues');
    }

    /**
     * Only platform super admins may view the Horizon dashboard outside local.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            return (bool) $user?->is_super_admin;
        });
    }
}
