<?php

namespace Board\Marketplace;

use Board\Marketplace\Models\PluginPackage;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * Boots plugin *packages* installed at runtime from the marketplace. For each
 * enabled package on the persistent volume it registers a PSR-4 autoloader and
 * the package's Laravel provider(s) — so a plugin installed from the UI is live
 * on the next request, with no Composer step and no image rebuild.
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

        /** @var array<int, array{package: PluginPackage, class: string}> $providers */
        $providers = [];

        foreach ($packages as $package) {
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

        foreach (($manifest['autoload']['psr-4'] ?? []) as $prefix => $dir) {
            $this->psr4[ltrim($prefix, '\\')] = rtrim($base.'/'.ltrim((string) $dir, '/'), '/');
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
