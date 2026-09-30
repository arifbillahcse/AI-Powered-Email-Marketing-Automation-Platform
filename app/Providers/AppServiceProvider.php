<?php

namespace App\Providers;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface lazy loading, silently discarded attributes and missing
        // attributes as exceptions outside production.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
