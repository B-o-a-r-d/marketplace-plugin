<?php

namespace Board\Marketplace;

use Board\Marketplace\Concerns\TalksToGitHub;
use Board\Marketplace\Models\PluginPackage;
use Board\Marketplace\Models\PluginRepository;
use Board\Marketplace\Support\ComposerProject;
use Board\Marketplace\Support\HostPackages;
use Board\PluginSdk\Sdk;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Installs / updates / removes plugin *packages* at runtime — no image rebuild.
 *
 * Two sources:
 * - **composer** (catalog entries with a `package` name, or a custom source):
 *   `composer require` inside the dedicated plugins project on the persistent
 *   volume ({@see ComposerProject}) — real dependency resolution, Packagist or
 *   any configured repository.
 * - **archive** (legacy): the GitHub release zipball extracted to
 *   `storage/app/plugins/<key>/` — kept for catalog entries without a package.
 *
 * Both land on the persistent volume and are booted by the PluginLoader.
 */
class PluginInstaller
{
    use TalksToGitHub;

    private const API = 'https://api.github.com';

    /** Zip-bomb / archive sanity caps. */
    private const MAX_ARCHIVE_ENTRIES = 5000;

    private const MAX_ARCHIVE_BYTES = 100 * 1024 * 1024;

    public function __construct(private readonly ComposerProject $project) {}

    /**
     * Install (or reinstall) the latest release of a catalog entry.
     *
     * @param  array{key: string, name: string, repo: string, package?: string}  $entry
     */
    public function install(array $entry): PluginPackage
    {
        $this->assertValidRepo($entry['repo']);
        $this->assertValidKey($entry['key']);

        if (! empty($entry['package'])) {
            return $this->installComposer($entry);
        }

        return $this->installTag($entry, $this->latestReleaseTag($entry['repo']));
    }

    /**
     * Update an installed package to its latest release. A major (or, on 0.x, a
     * minor) bump is "breaking" and requires explicit confirmation.
     */
    public function update(string $key, bool $confirmBreaking = false): PluginPackage
    {
        $package = PluginPackage::where('key', $key)->firstOrFail();

        if ($package->available_version !== null
            && $this->isBreaking($package->version, $package->available_version)
            && ! $confirmBreaking) {
            throw new PluginInstallException(__('Mise à jour majeure — confirmez pour continuer.'));
        }

        if ($package->isComposer()) {
            return $this->installComposer([
                'key' => $package->key,
                'name' => $package->name,
                'repo' => $package->repo,
                'package' => $package->package_name,
            ], $package->available_version);
        }

        $this->assertValidRepo($package->repo);
        $this->assertValidKey($package->key);
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

        // Removing a plugin from the UI must always succeed: a composer hiccup,
        // a missing manifest or a dev-symlinked project can never leave the row
        // (and the loader booting it) behind — best-effort composer cleanup,
        // then reclaim the on-disk install, then drop the record.
        if ($package->isComposer()) {
            rescue(fn () => $this->project->remove($package->package_name));
        }

        $this->deleteInstallDirectory(storage_path('app/'.$package->path));

        $package->delete();
    }

    /**
     * Reclaim a plugin's on-disk install. A dev install is a SYMLINK — unlink
     * the link itself, never recurse into (and wipe) the real source repo.
     */
    private function deleteInstallDirectory(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

            return;
        }

        File::deleteDirectory($path);
    }

    /**
     * Refresh the "latest available release" + breaking flag for each installed
     * package (scheduled). Never throws — network hiccups are swallowed per package.
     */
    public function checkUpdates(): void
    {
        // One `composer outdated` run resolves every composer-sourced plugin at
        // once — uniformly across Packagist and custom repositories. Skipped
        // entirely while nothing is composer-installed.
        $latestByName = PluginPackage::where('source', 'composer')->exists()
            ? rescue(fn () => $this->project->outdated(), [], report: true)
            : [];

        foreach (PluginPackage::all() as $package) {
            try {
                // Reconcile the recorded version with what composer actually has
                // on disk: a manual `composer update` (or any drift between the
                // DB and composer.lock) would otherwise leave the card showing a
                // stale version and hide — or falsely advertise — an update.
                $version = $package->isComposer()
                    ? ($this->project->installedVersion($package->package_name) ?? $package->version)
                    : $package->version;

                $available = $package->isComposer()
                    ? ($latestByName[$package->package_name] ?? $version)
                    : $this->normalize($this->latestReleaseTag($package->repo));

                $package->update([
                    'version' => $version,
                    'available_version' => $available,
                    'breaking_update' => $this->isBreaking($version, $available),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Composer path: require the package inside the plugins project (resolution
     * happens against the host's `replace` map, so an SDK-incompatible plugin
     * fails to resolve), then re-check the SDK contract on the installed code —
     * rolling the install back if the gate refuses it.
     *
     * @param  array{key: string, name: string, repo: string, package?: string}  $entry
     */
    private function installComposer(array $entry, ?string $version = null): PluginPackage
    {
        $name = (string) $entry['package'];
        $this->assertValidPackageName($name);

        $this->project->sync($this->customRepositories());
        $this->project->require($name, $version !== null ? '^'.ltrim($version, 'vV') : null);

        $manifest = $this->project->installedManifest($name);

        if ($manifest === null) {
            rescue(fn () => $this->project->remove($name));
            throw new PluginInstallException(__('La release n\'a pas de composer.json lisible.'));
        }

        $contract = Sdk::pluginContract($manifest);

        if (! Sdk::supportsContract($contract)) {
            rescue(fn () => $this->project->remove($name));
            throw new PluginInstallException(__('Ce plugin cible un contrat SDK incompatible (:built) — l\'hôte supporte :host.', [
                'built' => $contract === null ? 'non déclaré' : (string) $contract,
                'host' => implode(', ', Sdk::SUPPORTED_CONTRACTS),
            ]));
        }

        $installed = $this->project->installedVersion($name) ?? '0.0.0';

        try {
            $this->runPluginMigrations($this->project->path().'/vendor/'.$name);
        } catch (\Throwable $e) {
            rescue(fn () => $this->project->remove($name));
            throw new PluginInstallException(__('Échec des migrations du plugin :message', ['message' => mb_substr($e->getMessage(), 0, 300)]));
        }

        return PluginPackage::updateOrCreate(
            ['key' => $entry['key']],
            [
                'name' => $entry['name'],
                'repo' => $entry['repo'],
                'package_name' => $name,
                'source' => 'composer',
                'version' => $installed,
                'sdk_constraint' => $manifest['require']['board/plugin-sdk'] ?? null,
                'contract_version' => $contract,
                'path' => 'plugins/vendor/'.$name,
                'enabled' => true,
                'installed_by' => Auth::id(),
                'available_version' => $installed,
                'breaking_update' => false,
                'load_error' => null,
            ],
        );
    }

    /**
     * Install a plugin straight from a composer package name — Packagist or any
     * of the instance's custom repositories. Nothing is read from the catalog:
     * the key and display name derive from the package name.
     */
    public function installFromSource(string $package): PluginPackage
    {
        $this->assertValidPackageName($package);

        return $this->installComposer([
            'key' => str_replace('/', '-', $package),
            'name' => Str::headline(Str::after($package, '/')),
            'repo' => $package,
            'package' => $package,
        ]);
    }

    /**
     * Extra composer repositories for the plugins project, managed from the
     * marketplace UI (the composer.json `repositories` equivalent).
     *
     * @return array<int, array{type: string, url: string}>
     */
    protected function customRepositories(): array
    {
        return PluginRepository::query()
            ->orderBy('id')
            ->get()
            ->map(fn (PluginRepository $repository): array => [
                'type' => $repository->type,
                'url' => $repository->url,
            ])
            ->all();
    }

    /**
     * Run the package's own migrations (database/migrations) at install/update,
     * so a plugin can ship schema (e.g. a workspace-type plugin's tables). No-op
     * when the package ships none. Uninstall keeps the tables — data is never
     * dropped by removing a plugin.
     */
    private function runPluginMigrations(string $packageDir): void
    {
        $migrations = $packageDir.'/database/migrations';

        if (! is_dir($migrations)) {
            return;
        }

        Artisan::call('migrate', [
            '--realpath' => true,
            '--path' => $migrations,
            '--force' => true,
        ]);
    }

    private function assertValidPackageName(string $name): void
    {
        // Composer's own package-name rule (vendor/name, lowercase).
        if (! preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$#', $name)) {
            throw new PluginInstallException(__('Nom de package composer invalide.'));
        }
    }

    /**
     * @param  array{key: string, name: string, repo: string}  $entry
     */
    private function installTag(array $entry, string $tag): PluginPackage
    {
        $repo = $entry['repo'];
        $this->assertValidRepo($repo);
        $this->assertValidKey($entry['key']);
        $this->assertSafeRef($tag);
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

        // Contract gate: refuse a plugin built against an SDK contract the host
        // cannot run. This is stricter than the SemVer check above (a breaking
        // contract change can slip into a patch that still satisfies `^0.2`) and
        // is what the loader re-checks at boot to stay crash-safe.
        $contract = Sdk::pluginContract($manifest);

        if (! Sdk::supportsContract($contract)) {
            throw new PluginInstallException(__('Ce plugin cible un contrat SDK incompatible (:built) — l\'hôte supporte :host.', [
                'built' => $contract === null ? 'non déclaré' : (string) $contract,
                'host' => implode(', ', Sdk::SUPPORTED_CONTRACTS),
            ]));
        }

        $this->downloadAndExtract($repo, $tag, $entry['key']);

        try {
            $this->runPluginMigrations(storage_path('app/plugins/'.$entry['key']));
        } catch (\Throwable $e) {
            File::deleteDirectory(storage_path('app/plugins/'.$entry['key']));
            throw new PluginInstallException(__('Échec des migrations du plugin :message', ['message' => mb_substr($e->getMessage(), 0, 300)]));
        }

        return PluginPackage::updateOrCreate(
            ['key' => $entry['key']],
            [
                'name' => $entry['name'],
                'repo' => $repo,
                'version' => $this->normalize($tag),
                'sdk_constraint' => $constraint,
                'contract_version' => $contract,
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

        try {
            $this->assertArchiveIsSafe($zip);
        } catch (PluginInstallException $e) {
            $zip->close();
            @unlink($tmpZip);
            File::deleteDirectory($extractDir);

            throw $e;
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
     * Reject an archive before extraction if any entry could escape the target
     * directory (zip-slip): absolute paths, `..` traversal, symlinks — plus
     * entry-count and total-size caps against zip bombs.
     */
    private function assertArchiveIsSafe(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new PluginInstallException(__('L\'archive contient trop de fichiers.'));
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw new PluginInstallException(__('Entrée d\'archive illisible.'));
            }

            $name = (string) $stat['name'];

            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
                throw new PluginInstallException(__('Nom d\'entrée d\'archive invalide.'));
            }

            // Absolute path, Windows drive, or a `..` traversal segment = escape.
            if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) || in_array('..', explode('/', $name), true)) {
                throw new PluginInstallException(__('Chemin d\'archive non autorisé (traversée).'));
            }

            // Symlink entries could redirect a later write outside the sandbox.
            if (((($stat['external_attr'] ?? 0) >> 16) & 0xF000) === 0xA000) {
                throw new PluginInstallException(__('Lien symbolique interdit dans l\'archive.'));
            }

            $total += (int) ($stat['size'] ?? 0);

            if ($total > self::MAX_ARCHIVE_BYTES) {
                throw new PluginInstallException(__('L\'archive décompressée est trop volumineuse.'));
            }
        }
    }

    private function assertValidRepo(string $repo): void
    {
        if (str_contains($repo, '..') || ! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new PluginInstallException(__('Dépôt de plugin invalide.'));
        }
    }

    private function assertValidKey(string $key): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $key)) {
            throw new PluginInstallException(__('Clé de plugin invalide.'));
        }
    }

    private function assertSafeRef(string $tag): void
    {
        if ($tag === '' || str_contains($tag, '..') || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $tag)) {
            throw new PluginInstallException(__('Tag de release invalide.'));
        }
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
        // Read from the host's installed.php ({@see HostPackages}), never from
        // InstalledVersions: once the plugins-project autoloader is live, the
        // SDK resolves as a "replaced" entry of that project (no pretty
        // version) and the gate would see 0.0.0 — refusing every install.
        return $this->normalize(HostPackages::prettyVersion('board/plugin-sdk') ?? '0.0.0');
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
