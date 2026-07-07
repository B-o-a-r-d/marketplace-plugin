<?php

namespace Board\Marketplace;

use Board\Marketplace\Concerns\TalksToGitHub;
use Board\Marketplace\Models\PluginPackage;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Installs / updates / removes plugin *packages* at runtime from their GitHub
 * releases — no Composer, no image rebuild. Packages land on a persistent volume
 * (`storage/app/plugins/<key>/`) and are booted by the marketplace's PluginLoader.
 *
 * A plugin depends on the SDK only, so there is nothing to resolve: we verify the
 * release is compatible with the host SDK, download its zipball and extract it.
 */
class PluginInstaller
{
    use TalksToGitHub;

    private const API = 'https://api.github.com';

    /**
     * Install (or reinstall) the latest release of a catalog entry.
     *
     * @param  array{key: string, name: string, repo: string}  $entry
     */
    public function install(array $entry): PluginPackage
    {
        return $this->installTag($entry, $this->latestReleaseTag($entry['repo']));
    }

    /**
     * Update an installed package to its latest release. A major (or, on 0.x, a
     * minor) bump is "breaking" and requires explicit confirmation.
     */
    public function update(string $key, bool $confirmBreaking = false): PluginPackage
    {
        $package = PluginPackage::where('key', $key)->firstOrFail();
        $tag = $this->latestReleaseTag($package->repo);

        if ($this->isBreaking($package->version, $this->normalize($tag)) && ! $confirmBreaking) {
            throw new PluginInstallException(__('Mise à jour majeure — confirmez pour continuer.'));
        }

        return $this->installTag([
            'key' => $package->key,
            'name' => $package->name,
            'repo' => $package->repo,
        ], $tag);
    }

    public function uninstall(string $key): void
    {
        $package = PluginPackage::where('key', $key)->first();

        if ($package === null) {
            return;
        }

        File::deleteDirectory(storage_path('app/plugins/'.$package->key));
        $package->delete();
    }

    /**
     * Refresh the "latest available release" + breaking flag for each installed
     * package (scheduled). Never throws — network hiccups are swallowed per package.
     */
    public function checkUpdates(): void
    {
        foreach (PluginPackage::all() as $package) {
            try {
                $tag = $this->latestReleaseTag($package->repo);
                $available = $this->normalize($tag);

                $package->update([
                    'available_version' => $available,
                    'breaking_update' => $this->isBreaking($package->version, $available),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @param  array{key: string, name: string, repo: string}  $entry
     */
    private function installTag(array $entry, string $tag): PluginPackage
    {
        $repo = $entry['repo'];
        $manifest = $this->composerJsonAt($repo, $tag);

        if ($manifest === null) {
            throw new PluginInstallException(__('La release n\'a pas de composer.json lisible.'));
        }

        $constraint = $manifest['require']['board/plugin-sdk'] ?? null;

        if ($constraint !== null && ! Semver::satisfies($this->hostSdkVersion(), $constraint)) {
            throw new PluginInstallException(__('Incompatible avec le SDK de l\'hôte (:host) — requiert :need.', [
                'host' => $this->hostSdkVersion(),
                'need' => $constraint,
            ]));
        }

        $this->downloadAndExtract($repo, $tag, $entry['key']);

        return PluginPackage::updateOrCreate(
            ['key' => $entry['key']],
            [
                'name' => $entry['name'],
                'repo' => $repo,
                'version' => $this->normalize($tag),
                'sdk_constraint' => $constraint,
                'path' => 'plugins/'.$entry['key'],
                'enabled' => true,
                'installed_by' => Auth::id(),
                'available_version' => $this->normalize($tag),
                'breaking_update' => false,
                'load_error' => null,
            ],
        );
    }

    private function downloadAndExtract(string $repo, string $tag, string $key): void
    {
        $response = $this->github()->get(self::API."/repos/{$repo}/zipball/{$tag}");

        if (! $response->successful()) {
            throw new PluginInstallException(__('Impossible de télécharger l\'archive de la release.'));
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'plugin').'.zip';
        file_put_contents($tmpZip, $response->body());

        $extractDir = storage_path('app/plugins/.extract-'.$key);
        File::deleteDirectory($extractDir);

        $zip = new ZipArchive;

        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            throw new PluginInstallException(__('L\'archive de la release n\'est pas un zip valide.'));
        }

        $zip->extractTo($extractDir);
        $zip->close();
        @unlink($tmpZip);

        // GitHub zipballs wrap everything in a single "<owner>-<repo>-<sha>/" folder.
        $inner = collect(File::directories($extractDir))->first();

        if ($inner === null || ! File::exists($inner.'/composer.json')) {
            File::deleteDirectory($extractDir);
            throw new PluginInstallException(__('L\'archive n\'est pas un package de plugin valide.'));
        }

        $target = storage_path('app/plugins/'.$key);
        File::deleteDirectory($target);
        File::ensureDirectoryExists(dirname($target));
        File::moveDirectory($inner, $target);
        File::deleteDirectory($extractDir);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function composerJsonAt(string $repo, string $tag): ?array
    {
        $response = $this->github()->get("https://raw.githubusercontent.com/{$repo}/{$tag}/composer.json");

        return $response->successful() && is_array($response->json())
            ? $response->json()
            : null;
    }

    private function latestReleaseTag(string $repo): string
    {
        $response = $this->github()->get(self::API."/repos/{$repo}/releases/latest");

        if (! $response->successful() || empty($response->json('tag_name'))) {
            throw new PluginInstallException(__('Aucune release publiée pour ce plugin.'));
        }

        return (string) $response->json('tag_name');
    }

    private function hostSdkVersion(): string
    {
        $pretty = $this->normalize(InstalledVersions::getPrettyVersion('board/plugin-sdk') ?? '0.0.0');

        // A dev/branch build (e.g. "dev-master") is not SemVer-comparable; prefer
        // its numeric branch-alias ("0.2.x-dev") so the compatibility gate still
        // works when the host runs the SDK from a path/VCS branch.
        if (! preg_match('/^\d/', $pretty)) {
            $aliases = InstalledVersions::getRawData()['versions']['board/plugin-sdk']['aliases'] ?? [];

            foreach ($aliases as $alias) {
                if (preg_match('/^\d/', $alias)) {
                    return $alias;
                }
            }
        }

        return $pretty;
    }

    private function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    /**
     * A major bump is breaking; on 0.x, a minor bump is breaking too (SemVer 0.y.z).
     */
    private function isBreaking(string $from, string $to): bool
    {
        [$fMaj, $fMin] = $this->parts($from);
        [$tMaj, $tMin] = $this->parts($to);

        if ($tMaj !== $fMaj) {
            return true;
        }

        return $tMaj === 0 && $tMin !== $fMin;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parts(string $version): array
    {
        $bits = explode('.', $this->normalize($version));

        return [(int) ($bits[0] ?? 0), (int) ($bits[1] ?? 0)];
    }
}
