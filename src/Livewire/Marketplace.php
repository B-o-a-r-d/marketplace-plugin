<?php

namespace Board\Marketplace\Livewire;

use Board\Marketplace\MarketplaceClient;
use Board\Marketplace\Models\PluginPackage;
use Board\Marketplace\PluginInstaller;
use Board\Marketplace\PluginInstallException;
use Board\Marketplace\Support\Settings;
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

    public function refreshCatalog(): void
    {
        abort_unless(Gate::allows('admin'), 403);

        app(MarketplaceClient::class)->catalog(fresh: true);
        $this->dispatch('toast', message: __('Catalogue actualisé'), type: 'success');
    }

    private function guard(): void
    {
        abort_unless(Gate::allows('admin') && Settings::enabled(), 403);
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
        return view('board-marketplace::marketplace', [
            'catalog' => app(MarketplaceClient::class)->catalog(),
            'installed' => PluginPackage::all()->keyBy('key'),
        ]);
    }
}
