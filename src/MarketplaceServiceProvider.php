<?php

namespace Board\Marketplace;

use Board\Marketplace\Console\CheckPluginUpdates;
use Board\Marketplace\Livewire\Marketplace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
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
        //
        // Ordering trap on hosts with `route:cache`: the framework loads the
        // compiled routes from its own booted callback — queued AFTER ours —
        // and setCompiledRoutes() REPLACES the whole collection, wiping every
        // route the plugin providers just registered. On cached hosts we
        // therefore take over the cache load itself (the framework's official
        // hook) and boot the plugins right after the compiled collection is
        // in place, so their routes land on top of it.
        if ($this->app->routesAreCached()) {
            RouteServiceProvider::loadCachedRoutesUsing(function () {
                require $this->app->getCachedRoutesPath();

                (new PluginLoader($this->app))->boot();
            });
        } else {
            $this->app->booted(fn () => (new PluginLoader($this->app))->boot());
        }
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
