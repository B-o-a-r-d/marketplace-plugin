<?php

namespace Board\Marketplace\Livewire;

use Board\Marketplace\MarketplaceClient;
use Board\Marketplace\Models\PluginPackage;
use Board\Marketplace\PluginInstaller;
use Board\Marketplace\PluginInstallException;
use Board\Marketplace\Support\Settings;
use Board\PluginSdk\Contracts\ProvidesSettings;
use Board\PluginSdk\PluginRegistry;
use Board\PluginSdk\Support\PluginSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin-only plugin marketplace: browse the curated catalog and install / update
 * / uninstall plugin *packages* at runtime — no redeploy. The page is always
 * reachable by admins (it hosts the master switch); install actions are guarded
 * by that switch, since installing runs third-party PHP in-process.
 */
#[Layout('components.layouts.app')]
class Marketplace extends Component
{
    public bool $enabled = false;

    /** The plugin key whose instance settings are being edited, or null. */
    public ?string $configuringKey = null;

    /** @var array<string, mixed> */
    public array $settingsDraft = [];

    public function mount(): void
    {
        abort_unless(Gate::allows('admin'), 403);

        $this->enabled = Settings::enabled();
    }

    public function toggleEnabled(): void
    {
        abort_unless(Gate::allows('admin'), 403);

        Settings::setEnabled(! Settings::enabled());
        $this->enabled = Settings::enabled();
        $this->dispatch('toast', message: $this->enabled ? __('Marketplace activée') : __('Marketplace désactivée'), type: 'success');
    }

    public function install(string $key): void
    {
        $this->guard();

        $entry = app(MarketplaceClient::class)->entry($key);

        if ($entry === null) {
            return;
        }

        $this->run(fn () => app(PluginInstaller::class)->install($entry), __('Plugin installé'));
    }

    public function update(string $key, bool $confirmBreaking = false): void
    {
        $this->guard();

        $this->run(fn () => app(PluginInstaller::class)->update($key, $confirmBreaking), __('Plugin mis à jour'));
    }

    public function uninstall(string $key): void
    {
        $this->guard();

        app(PluginInstaller::class)->uninstall($key);
        $this->dispatch('toast', message: __('Plugin désinstallé'), type: 'success');
    }

    public function togglePackage(string $key): void
    {
        $this->guard();

        $package = PluginPackage::where('key', $key)->first();
        $package?->update(['enabled' => ! $package->enabled]);
    }

    /**
     * Open the instance-settings form for an installed plugin that declares them.
     * Secrets are never sent to the client — password fields start blank and a
     * blank save keeps the stored value.
     */
    public function startSettings(string $key): void
    {
        $this->guard();

        $plugin = $this->settingsPlugin($key);

        if ($plugin === null) {
            return;
        }

        $stored = PluginSettings::for($key)->all();
        $draft = [];

        foreach ($plugin->settings() as $field) {
            $type = $field['type'] ?? 'text';
            $draft[$field['key']] = $type === 'password'
                ? ''
                : ($stored[$field['key']] ?? ($field['default'] ?? ''));
        }

        $this->resetErrorBag();
        $this->settingsDraft = $draft;
        $this->configuringKey = $key;
    }

    public function saveSettings(): void
    {
        $this->guard();

        if ($this->configuringKey === null) {
            return;
        }

        $plugin = $this->settingsPlugin($this->configuringKey);

        if ($plugin === null) {
            return;
        }

        $values = PluginSettings::for($this->configuringKey)->all();

        foreach ($plugin->settings() as $field) {
            $key = $field['key'];
            $type = $field['type'] ?? 'text';
            $raw = $this->settingsDraft[$key] ?? '';
            $value = is_string($raw) ? trim($raw) : $raw;

            // A blank secret keeps the stored value (never wiped on edit).
            if ($type === 'password' && $value === '') {
                continue;
            }

            if (($field['required'] ?? false) && ($value === '' || $value === null)) {
                $this->addError('settingsDraft.'.$key, __('Ce champ est requis.'));

                return;
            }

            // Instance settings are set by a trusted platform admin, so a URL only
            // needs to be well-formed http(s) here — the SSRF host check applies to
            // the (less trusted) per-board URL fields, not to this default.
            if ($type === 'url' && $value !== '' && ! $this->looksLikeHttpUrl((string) $value)) {
                $this->addError('settingsDraft.'.$key, __('URL invalide (http/https requis).'));

                return;
            }

            $values[$key] = $type === 'boolean' ? (bool) $raw : $value;
        }

        PluginSettings::for($this->configuringKey)->put($values);

        $this->dispatch('toast', message: __('Réglages enregistrés'), type: 'success');
        $this->cancelSettings();
    }

    public function cancelSettings(): void
    {
        $this->configuringKey = null;
        $this->settingsDraft = [];
        $this->resetErrorBag();
    }

    public function refreshCatalog(): void
    {
        abort_unless(Gate::allows('admin'), 403);

        app(MarketplaceClient::class)->catalog(fresh: true);
        app(PluginInstaller::class)->checkUpdates();

        $this->dispatch('toast', message: __('Catalogue et versions actualisés'), type: 'success');
    }

    private function guard(): void
    {
        abort_unless(Gate::allows('admin') && Settings::enabled(), 403);
    }

    /**
     * The installed, settings-declaring plugin behind a key, or null when it is
     * not installed or does not expose instance settings.
     */
    private function settingsPlugin(string $key): ?ProvidesSettings
    {
        if (PluginPackage::where('key', $key)->doesntExist()) {
            return null;
        }

        $plugin = app(PluginRegistry::class)->get($key);

        return $plugin instanceof ProvidesSettings ? $plugin : null;
    }

    private function looksLikeHttpUrl(string $url): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && parse_url($url, PHP_URL_HOST) !== null;
    }

    private function run(callable $action, string $success): void
    {
        try {
            $action();
            $this->dispatch('toast', message: $success, type: 'success');
        } catch (PluginInstallException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function render(): View
    {
        $installed = PluginPackage::all()->keyBy('key');

        $registry = app(PluginRegistry::class);
        $configurableKeys = $installed->keys()
            ->filter(fn (string $key): bool => $registry->get($key) instanceof ProvidesSettings)
            ->values()
            ->all();

        return view('board-marketplace::marketplace', [
            'catalog' => app(MarketplaceClient::class)->catalog(),
            'installed' => $installed,
            'configurableKeys' => $configurableKeys,
            'settingsFields' => $this->configuringKey !== null
                ? ($this->settingsPlugin($this->configuringKey)?->settings() ?? [])
                : [],
        ]);
    }
}
