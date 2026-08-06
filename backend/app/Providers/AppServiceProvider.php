<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Constructed with the metrics path rather than reaching for config()
        // inside the service, so a test can point it at a fixture without
        // touching global state.
        $this->app->singleton(
            \App\Services\DeliveryHealth::class,
            fn () => new \App\Services\DeliveryHealth(config('appliance.forwarder_metrics')),
        );
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
