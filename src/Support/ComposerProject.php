<?php

namespace Board\Marketplace\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;

/**
 * The plugins directory (`storage/app/plugins/`) managed as a dedicated composer
 * project on the persistent volume: its composer.json lists the installed
 * plugins (`require`), the instance's extra sources (`repositories`) and — key
 * trick — a generated `replace` map of every package the HOST app already
 * ships, pinned at the host's installed versions. Composer therefore never
 * installs a second copy of the SDK, Laravel or any shared dependency: plugins
 * resolve against the host's versions (which doubles as a compatibility gate)
 * and only genuinely new dependencies land in the plugins vendor/.
 */
class ComposerProject
{
    public function __construct(private readonly ComposerRunner $runner) {}

    public function path(): string
    {
        return storage_path('app/plugins');
    }

    public function manifestPath(): string
    {
        return $this->path().'/composer.json';
    }

    public function vendorAutoload(): string
    {
        return $this->path().'/vendor/autoload.php';
    }

    /**
     * (Re)write the managed manifest: keeps the current `require` (the installed
     * plugins), refreshes the host `replace` map and the custom repositories.
     *
     * @param  array<int, array{type: string, url: string}>  $repositories
     */
    public function sync(array $repositories = []): void
    {
        File::ensureDirectoryExists($this->path());

        $manifest = $this->readManifest();
        $require = $manifest['require'] ?? [];

        $manifest['name'] = 'board/plugins-runtime';
        $manifest['description'] = 'Runtime-installed Board plugins — managed by the marketplace, do not edit.';
        $manifest['type'] = 'project';
        // composer.json wants an object — an empty PHP array would encode as [].
        $manifest['require'] = $require === [] ? new \stdClass : $require;
        $manifest['repositories'] = $repositories;
        $manifest['replace'] = $this->hostReplaceMap();
        $manifest['minimum-stability'] = 'stable';
        $manifest['prefer-stable'] = true;
        $manifest['config'] = [
            'allow-plugins' => false,
            'sort-packages' => true,
        ];

        File::put($this->manifestPath(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * `composer require` a plugin package, rolling the manifest back on failure.
     */
    public function require(string $package, ?string $constraint = null): void
    {
        $this->transaction(function () use ($package, $constraint) {
            $argument = $constraint === null ? $package : "{$package}:{$constraint}";
            $this->runner->run(['require', $argument, '--update-no-dev'], $this->path());
        });
    }

    /**
     * `composer remove` a plugin package, rolling the manifest back on failure.
     */
    public function remove(string $package): void
    {
        $this->transaction(function () use ($package) {
            $this->runner->run(['remove', $package, '--update-no-dev'], $this->path());
        });
    }

    /**
     * The installed composer.json of a plugin package (autoload, providers,
     * SDK constraint…), or null when it is not installed.
     *
     * @return array<string, mixed>|null
     */
    public function installedManifest(string $package): ?array
    {
        $manifest = json_decode((string) @file_get_contents($this->path()."/vendor/{$package}/composer.json"), true);

        return is_array($manifest) ? $manifest : null;
    }

    public function installedVersion(string $package): ?string
    {
        foreach ($this->installedPackages() as $installed) {
            if (($installed['name'] ?? null) === $package) {
                return ltrim((string) ($installed['version'] ?? ''), 'vV') ?: null;
            }
        }

        return null;
    }

    /**
     * Latest available versions of the direct dependencies (any repository),
     * keyed by package name — `composer outdated` does the resolution for us.
     *
     * @return array<string, string>
     */
    public function outdated(): array
    {
        // Before the first composer install there is no project to inspect —
        // and the nightly checkUpdates must not log a failure for that.
        if (! is_file($this->manifestPath())) {
            return [];
        }

        $output = $this->runner->run(['outdated', '--direct', '--format=json'], $this->path());
        $rows = json_decode($output, true)['installed'] ?? [];

        $latest = [];

        foreach ((array) $rows as $row) {
            if (! empty($row['name']) && ! empty($row['latest'])) {
                $latest[(string) $row['name']] = ltrim((string) $row['latest'], 'vV');
            }
        }

        return $latest;
    }

    /**
     * Every package the host app ships, pinned at its installed version, so the
     * plugins project treats them as already provided.
     *
     * @return array<string, string>
     */
    private function hostReplaceMap(): array
    {
        $replace = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $version = InstalledVersions::getPrettyVersion($name);

            if ($version === null) {
                continue;
            }

            // Branch installs ("dev-master") are not SemVer-comparable; prefer a
            // numeric alias ("0.2.x-dev") so plugin constraints still resolve.
            if (! preg_match('/^[\dv]/', $version)) {
                foreach (InstalledVersions::getRawData()['versions'][$name]['aliases'] ?? [] as $alias) {
                    if (preg_match('/^\d/', $alias)) {
                        $version = $alias;
                        break;
                    }
                }
            }

            $replace[$name] = ltrim($version, 'vV');
        }

        ksort($replace);

        return $replace;
    }

    /**
     * The current managed manifest, or an empty array before the first sync.
     *
     * @return array<string, mixed>
     */
    private function readManifest(): array
    {
        $manifest = json_decode((string) @file_get_contents($this->manifestPath()), true);

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function installedPackages(): array
    {
        $installed = json_decode((string) @file_get_contents($this->path().'/vendor/composer/installed.json'), true);

        // Composer 2 wraps the list in a "packages" key.
        return (array) ($installed['packages'] ?? $installed ?? []);
    }

    /**
     * Snapshot composer.json + composer.lock, run the operation, and restore
     * both (then reconverge vendor/) if it throws.
     */
    private function transaction(callable $operation): void
    {
        $files = [$this->manifestPath(), $this->path().'/composer.lock'];
        $backup = [];

        foreach ($files as $file) {
            $backup[$file] = is_file($file) ? (string) file_get_contents($file) : null;
        }

        try {
            $operation();
        } catch (\Throwable $e) {
            foreach ($backup as $file => $content) {
                $content === null ? @unlink($file) : File::put($file, $content);
            }

            // Converge vendor/ back onto the restored lock; a failure here is
            // secondary to the original error, so it is only reported.
            if ($backup[$this->path().'/composer.lock'] !== null) {
                rescue(fn () => $this->runner->run(['install', '--no-dev'], $this->path()));
            }

            throw $e;
        }
    }
}
