<?php

namespace Board\Marketplace\Models;

use Board\PluginSdk\Sdk;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A plugin package installed at runtime from the marketplace (distinct from a
 * per-board plugin instance). The extracted code lives on a persistent volume
 * at `storage/app/plugins/<key>/` and is booted by the marketplace's PluginLoader.
 */
#[Fillable(['key', 'name', 'repo', 'package_name', 'source', 'version', 'sdk_constraint', 'contract_version', 'path', 'enabled', 'installed_by', 'available_version', 'breaking_update', 'load_error'])]
class PluginPackage extends Model
{
    /**
     * Whether this package is managed by the plugins composer project
     * (installed via `composer require`) rather than a legacy archive extract.
     */
    public function isComposer(): bool
    {
        return $this->source === 'composer' && $this->package_name !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'breaking_update' => 'boolean',
            'contract_version' => 'integer',
        ];
    }

    /**
     * Whether a newer release is available (set by checkUpdates()).
     */
    public function hasUpdate(): bool
    {
        return $this->available_version !== null && $this->available_version !== $this->version;
    }

    /**
     * Whether the host SDK can load this package. An incompatible package is
     * quarantined by the loader (never loaded) rather than crashing the boot.
     */
    public function isCompatible(): bool
    {
        return Sdk::supportsContract($this->contract_version);
    }

    /**
     * A short, user-facing reason when the package is not loadable, or null.
     */
    public function incompatibilityReason(): ?string
    {
        if ($this->isCompatible()) {
            return null;
        }

        $built = $this->contract_version === null ? 'inconnu' : (string) $this->contract_version;

        return __('Incompatible avec le SDK de l\'hôte (contrat plugin :built, hôte :host) — mise à jour requise.', [
            'built' => $built,
            'host' => implode(', ', Sdk::SUPPORTED_CONTRACTS),
        ]);
    }
}
