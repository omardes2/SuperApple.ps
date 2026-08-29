<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Services\Settings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Settings is a cached, app-wide singleton.
        $this->app->singleton(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super Admin bypasses every ability check. Returning null lets other
        // checks run normally for non-super-admins.
        Gate::before(function ($user, string $ability) {
            return $user->hasRole(RoleName::SuperAdmin->value) ? true : null;
        });
    }
}
