<?php

namespace Board\Marketplace\Support;

use Composer\InstalledVersions;

/**
 * The HOST application's installed composer packages, read straight from
 * `vendor/composer/installed.php`.
 *
 * Never use {@see InstalledVersions} for this: once the PluginLoader
 * registers the plugins-project autoloader (any composer-installed plugin),
 * InstalledVersions merges BOTH datasets — the runtime project and its
 * dependency copies leak in, the generated replace map ends up replacing the
 * plugins project itself, and every subsequent composer operation fails with
 * "board/plugins-runtime replaces X and thus cannot coexist with it". Reading
 * the host's installed.php file is immune to whatever autoloaders are live.
 */
final class HostPackages
{
    /** @var array<string, mixed>|null */
    private static ?array $installed = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function versions(): array
    {
        if (self::$installed === null) {
            self::$installed = (static fn (): array => require base_path('vendor/composer/installed.php'))();
        }

        return self::$installed['versions'] ?? [];
    }

    /**
     * The host-installed version of a package, or null. Branch installs
     * ("dev-master") are not SemVer-comparable, so a numeric branch alias
     * ("0.2.x-dev") is preferred when one exists.
     */
    public static function prettyVersion(string $package): ?string
    {
        $info = self::versions()[$package] ?? null;

        if ($info === null) {
            return null;
        }

        $version = $info['pretty_version'] ?? null;

        if ($version !== null && ! preg_match('/^[\dv]/', (string) $version)) {
            foreach ($info['aliases'] ?? [] as $alias) {
                if (preg_match('/^\d/', (string) $alias)) {
                    return (string) $alias;
                }
            }
        }

        return $version !== null ? (string) $version : null;
    }
}
