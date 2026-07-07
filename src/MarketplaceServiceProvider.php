<?php

namespace Board\Marketplace;

use Board\Marketplace\Console\CheckPluginUpdates;
use Board\Marketplace\Livewire\Marketplace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Optional runtime plugin marketplace for Board. Auto-discovered when the host
 * `composer require board/marketplace`s it. Ships its own migration, view, route,
 * config, scheduled command — and the runtime plugin loader. Removing the package
 * removes the feature entirely; the host's plugin *system* keeps working with
 * plugins installed the classic way.
 */
class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/board-marketplace.php', 'board-marketplace');

        // Boot installed plugin packages once the app is booted (DB ready).
        $this->app->booted(fn () => (new PluginLoader($this->app))->boot());
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'board-marketplace');

        Route::middleware(['web', 'auth', 'verified'])->group(function () {
            Route::get('/marketplace', Marketplace::class)->name('marketplace');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([CheckPluginUpdates::class]);

            $this->publishes([
                __DIR__.'/../config/board-marketplace.php' => config_path('board-marketplace.php'),
            ], 'board-marketplace-config');
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('plugins:check-updates')->daily();
        });
    }
}
