<?php

namespace Board\Marketplace;

use Board\Marketplace\Models\PluginPackage;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * Boots plugin *packages* installed at runtime from the marketplace — so a
 * plugin installed from the UI is live on the next request, with no image
 * rebuild. Composer-sourced packages resolve through the plugins project's own
 * vendor/autoload.php; legacy archive packages get a manual PSR-4 autoloader.
 *
 * Each package is isolated: a broken one is flagged (`load_error`) and skipped,
 * never crashing the app boot.
 */
class PluginLoader
{
    /** @var array<string, string> PSR-4 prefix (no leading slash) => base directory */
    private array $psr4 = [];

    public function __construct(private readonly Application $app) {}

    public function boot(): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $packages = PluginPackage::where('enabled', true)->get();

        if ($packages->isEmpty()) {
            return;
        }

        // The plugins project autoloader (composer-sourced packages + their own
        // dependencies). Registered before the contract gate below so it never
        // loads a class by itself — classes only resolve when a provider boots.
        $autoload = storage_path('app/plugins/vendor/autoload.php');

        if ($packages->contains(fn (PluginPackage $p) => $p->isComposer()) && is_file($autoload)) {
            try {
                require_once $autoload;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        /** @var array<int, array{package: PluginPackage, class: string}> $providers */
        $providers = [];

        foreach ($packages as $package) {
            // Compatibility gate FIRST: an incompatible plugin's class would raise
            // an UNCATCHABLE fatal the moment it is declared, so it must never be
            // loaded. Flag it and skip — the app boots, the marketplace shows it as
            // "update required". This is the safety net that keeps a stale plugin
            // from taking down the whole host.
            if (! $package->isCompatible()) {
                rescue(fn () => $package->update(['load_error' => $package->incompatibilityReason()]));

                continue;
            }

            try {
                $providers = array_merge($providers, $this->collect($package));
            } catch (\Throwable $e) {
                report($e);
                rescue(fn () => $package->update(['load_error' => $e->getMessage()]));
            }
        }

        // Register the autoloader before booting providers so their classes resolve.
        if ($this->psr4 !== []) {
            spl_autoload_register($this->autoload(...));
        }

        foreach ($providers as $entry) {
            try {
                $this->app->register($entry['class']);
            } catch (\Throwable $e) {
                report($e);
                rescue(fn () => $entry['package']->update(['load_error' => $e->getMessage()]));
            }
        }
    }

    /**
     * @return array<int, array{package: PluginPackage, class: string}>
     */
    private function collect(PluginPackage $package): array
    {
        $base = storage_path('app/'.$package->path);
        $manifest = json_decode((string) @file_get_contents($base.'/composer.json'), true);

        if (! is_array($manifest)) {
            throw new \RuntimeException("Missing composer.json for plugin [{$package->key}].");
        }

        // Composer-sourced packages are autoloaded by the plugins project's own
        // vendor/autoload.php — only legacy archives need the manual PSR-4 map.
        if (! $package->isComposer()) {
            foreach (($manifest['autoload']['psr-4'] ?? []) as $prefix => $dir) {
                $this->psr4[ltrim($prefix, '\\')] = rtrim($base.'/'.ltrim((string) $dir, '/'), '/');
            }
        }

        return array_map(
            fn (string $class): array => ['package' => $package, 'class' => $class],
            $manifest['extra']['laravel']['providers'] ?? [],
        );
    }

    private function autoload(string $class): void
    {
        foreach ($this->psr4 as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        }
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('plugin_packages');
        } catch (\Throwable) {
            return false;
        }
    }
}
