<?php

namespace App\Providers;

use App\Contracts\WhatsAppProvider;
use App\Enums\RoleName;
use App\Services\Settings;
use App\Services\WhatsApp\FakeWhatsAppProvider;
use App\Services\WhatsApp\LogWhatsAppProvider;
use App\Services\WhatsApp\NullWhatsAppProvider;
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

        // The fake provider is a singleton so tests observe the same instance
        // that the service and job send through.
        $this->app->singleton(FakeWhatsAppProvider::class);

        // Resolve the active WhatsApp driver from settings at call time. No
        // credentials live here — a real driver reads its own from Settings/ENV.
        $this->app->bind(WhatsAppProvider::class, function ($app) {
            $key = (string) $app->make(Settings::class)->get('whatsapp', 'provider', 'null');

            return match ($key) {
                'fake' => $app->make(FakeWhatsAppProvider::class),
                'log' => $app->make(LogWhatsAppProvider::class),
                default => $app->make(NullWhatsAppProvider::class), // null | manual | unknown
            };
        });
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
