<div class="mx-auto max-w-5xl"
     x-data="{ view: localStorage.getItem('marketplace-view') ?? 'grid' }"
     x-init="$watch('view', v => localStorage.setItem('marketplace-view', v))">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="flex items-center gap-2 text-xl font-semibold tracking-tight sm:text-2xl">
                <x-phosphor-puzzle-piece class="h-6 w-6"/> {{ __('Marketplace') }}
            </h1>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Installez des Power-Ups à chaud, sans redéploiement.') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-300">
                {{ $enabled ? __('Activée') : __('Désactivée') }}
                <button type="button" role="switch" :aria-checked="@js($enabled)" wire:click="toggleEnabled"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $enabled ? 'bg-indigo-600' : 'bg-neutral-300 dark:bg-neutral-700' }}">
                    <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $enabled ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                </button>
            </label>

            <div class="flex items-center gap-0.5 rounded-lg border border-neutral-300 p-0.5 dark:border-neutral-700">
                <button type="button" @click="view = 'grid'" title="{{ __('Grille') }}"
                        class="rounded p-1.5 transition" :class="view === 'grid' ? 'bg-neutral-200 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-200' : 'text-neutral-400 hover:text-neutral-600'">
                    <x-phosphor-squares-four class="h-4 w-4"/>
                </button>
                <button type="button" @click="view = 'list'" title="{{ __('Liste') }}"
                        class="rounded p-1.5 transition" :class="view === 'list' ? 'bg-neutral-200 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-200' : 'text-neutral-400 hover:text-neutral-600'">
                    <x-phosphor-list class="h-4 w-4"/>
                </button>
            </div>

            <button type="button" wire:click="openSource" @disabled(! $enabled)
                    class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800">
                <x-phosphor-git-branch class="h-4 w-4"/>
                {{ __('Depuis une source') }}
            </button>
            <button type="button" wire:click="refreshCatalog"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800">
                <x-phosphor-arrows-clockwise class="h-4 w-4" wire:loading.class="animate-spin" wire:target="refreshCatalog"/>
                {{ __('Actualiser') }}
            </button>
        </div>
    </div>

    @unless ($enabled)
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            <x-phosphor-warning class="mt-0.5 h-5 w-5 shrink-0"/>
            <div>
                <p class="font-medium">{{ __('Marketplace désactivée') }}</p>
                <p class="mt-0.5">{{ __("L'installation exécute du code tiers. Activez la marketplace avec l'interrupteur ci-dessus pour installer des plugins.") }}</p>
            </div>
        </div>
    @endunless

    <div :class="view === 'grid' ? 'grid items-start gap-4 sm:grid-cols-2' : 'space-y-3'">
        @forelse ($catalog as $entry)
            @php $pkg = $installed[$entry['key']] ?? null; @endphp
            <div wire:key="mk-{{ $entry['key'] }}" class="flex flex-col overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-800">
                {{-- Banner (grid view) — catalog image or a placeholder gradient --}}
                <div x-show="view === 'grid'" class="relative h-28 w-full shrink-0">
                    @if ($entry['banner'] !== '')
                        <img src="{{ $entry['banner'] }}" alt="" loading="lazy" class="h-28 w-full object-cover">
                    @else
                        <div class="flex h-28 w-full items-center justify-center bg-gradient-to-br from-indigo-500/70 via-indigo-400/50 to-purple-500/60 dark:from-indigo-600/50 dark:via-indigo-500/30 dark:to-purple-600/40">
                            <x-dynamic-component :component="'phosphor-'.$entry['icon']" class="h-10 w-10 text-white/80"/>
                        </div>
                    @endif
                    @if (isset($downloads[$entry['key']]))
                        <span class="absolute right-2 top-2 inline-flex items-center gap-1 rounded-full bg-black/50 px-2 py-0.5 text-[11px] font-medium text-white backdrop-blur">
                            <x-phosphor-download-simple class="h-3 w-3"/> {{ \Illuminate\Support\Number::abbreviate($downloads[$entry['key']]) }}
                        </span>
                    @endif
                </div>

                <div class="flex flex-1 flex-col p-4">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-800">
                            <x-dynamic-component :component="'phosphor-'.$entry['icon']" class="h-5 w-5 text-neutral-600 dark:text-neutral-300"/>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {{ $entry['name'] }}
                                @if ($pkg)
                                    <span class="rounded bg-green-100 px-1.5 py-0.5 text-[10px] font-medium text-green-700 dark:bg-green-500/15 dark:text-green-400">v{{ $pkg->version }}</span>
                                    @unless ($pkg->enabled)
                                        <span class="rounded bg-neutral-200 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-neutral-700">{{ __('inactif') }}</span>
                                    @endunless
                                    @unless ($pkg->isCompatible())
                                        <span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:bg-red-500/15 dark:text-red-400">{{ __('incompatible') }}</span>
                                    @endunless
                                    @if ($pkg->hasUpdate())
                                        <span class="rounded px-1.5 py-0.5 text-[10px] font-medium {{ $pkg->breaking_update ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' }}">
                                            {{ $pkg->breaking_update ? __('maj majeure v:v', ['v' => $pkg->available_version]) : __('maj v:v', ['v' => $pkg->available_version]) }}
                                        </span>
                                    @endif
                                @endif
                            </p>
                            <p class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{{ $entry['description'] }}</p>
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                        @foreach ($entry['capabilities'] as $cap)
                            <span class="rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{{ $cap }}</span>
                        @endforeach
                        @if ($entry['package'] !== '')
                            <span class="inline-flex items-center gap-1 rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300" title="{{ $entry['package'] }}">
                                <x-phosphor-package class="h-3 w-3"/> composer
                            </span>
                        @endif
                        <a href="https://github.com/{{ $entry['repo'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-[11px] text-neutral-400 hover:text-indigo-600 hover:underline" title="{{ __('Voir sur GitHub') }}">
                            {{ $entry['repo'] }}
                            <x-phosphor-arrow-square-out class="h-3 w-3 shrink-0"/>
                        </a>
                        @if (isset($downloads[$entry['key']]))
                            <span x-show="view === 'list'" class="inline-flex items-center gap-1 text-[11px] text-neutral-400">
                                <x-phosphor-download-simple class="h-3 w-3"/> {{ \Illuminate\Support\Number::abbreviate($downloads[$entry['key']]) }}
                            </span>
                        @endif
                    </div>

                    @if ($pkg && ! $pkg->isCompatible())
                        <p class="mt-2 rounded bg-red-50 px-2 py-1 text-[11px] text-red-600 dark:bg-red-500/10 dark:text-red-400">
                            {{ $pkg->incompatibilityReason() }} {{ __("Désactivé automatiquement pour ne pas affecter l'application.") }}
                        </p>
                    @elseif ($pkg?->load_error)
                        <p class="mt-2 rounded bg-red-50 px-2 py-1 text-[11px] text-red-600 dark:bg-red-500/10 dark:text-red-400">{{ __('Erreur de chargement') }} : {{ $pkg->load_error }}</p>
                    @endif

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 pt-1">
                        <button type="button" wire:click="showDetails('{{ $entry['key'] }}')"
                                class="text-xs text-neutral-400 transition hover:text-indigo-600 hover:underline dark:hover:text-indigo-400">{{ __('Détails') }}</button>

                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            @if (! $pkg)
                                <button type="button" wire:click="install('{{ $entry['key'] }}')" @disabled(! $enabled)
                                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40">
                                    <span wire:loading.remove wire:target="install('{{ $entry['key'] }}')">{{ __('Installer') }}</span>
                                    <span wire:loading wire:target="install('{{ $entry['key'] }}')">{{ __('Installation…') }}</span>
                                </button>
                            @else
                                @if ($pkg->hasUpdate())
                                    @if ($pkg->breaking_update)
                                        <button type="button" @disabled(! $enabled)
                                                @click="$store.confirm.open({ title: '{{ __('Mise à jour majeure') }}', message: '{{ __('Cette version peut casser la compatibilité. Continuer ?') }}', confirmLabel: '{{ __('Mettre à jour') }}', danger: true }).then(ok => ok && $wire.update('{{ $entry['key'] }}', true))"
                                                class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40">{{ __('Mettre à jour') }}</button>
                                    @else
                                        <button type="button" wire:click="update('{{ $entry['key'] }}')" @disabled(! $enabled)
                                                class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">{{ __('Mettre à jour') }}</button>
                                    @endif
                                @endif
                                @if (in_array($entry['key'], $configurableKeys, true))
                                    <button type="button" wire:click="startSettings('{{ $entry['key'] }}')" @disabled(! $enabled)
                                            class="rounded-lg border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-100 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800">
                                        {{ __('Configurer') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="togglePackage('{{ $entry['key'] }}')" @disabled(! $enabled)
                                        class="rounded-lg border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-100 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800">
                                    {{ $pkg->enabled ? __('Désactiver') : __('Activer') }}
                                </button>
                                <button type="button" @disabled(! $enabled)
                                        @click="$store.confirm.open({ title: '{{ __('Désinstaller') }}', message: '{{ __('Retirer ce plugin de l’instance ?') }}', confirmLabel: '{{ __('Désinstaller') }}', danger: true }).then(ok => ok && $wire.uninstall('{{ $entry['key'] }}'))"
                                        class="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40 dark:hover:bg-red-500/10" title="{{ __('Désinstaller') }}">
                                    <x-phosphor-trash class="h-4 w-4"/>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-neutral-300 p-10 text-center text-sm text-neutral-400 dark:border-neutral-700 sm:col-span-2">
                {{ __('Catalogue vide ou indisponible.') }}
            </div>
        @endforelse
    </div>

    {{-- Packages installed outside the catalog (custom sources) --}}
    @if ($offCatalog->isNotEmpty())
        <h2 class="mb-3 mt-8 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ __('Installés hors catalogue') }}</h2>
        <div class="space-y-3">
            @foreach ($offCatalog as $pkg)
                <div wire:key="mk-off-{{ $pkg->key }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-800">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-800">
                            <x-phosphor-package class="h-5 w-5 text-neutral-600 dark:text-neutral-300"/>
                        </span>
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {{ $pkg->name }}
                                <span class="rounded bg-green-100 px-1.5 py-0.5 text-[10px] font-medium text-green-700 dark:bg-green-500/15 dark:text-green-400">v{{ $pkg->version }}</span>
                                @unless ($pkg->enabled)
                                    <span class="rounded bg-neutral-200 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-neutral-700">{{ __('inactif') }}</span>
                                @endunless
                                @if ($pkg->hasUpdate())
                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-medium {{ $pkg->breaking_update ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' }}">
                                        {{ $pkg->breaking_update ? __('maj majeure v:v', ['v' => $pkg->available_version]) : __('maj v:v', ['v' => $pkg->available_version]) }}
                                    </span>
                                @endif
                            </p>
                            <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $pkg->package_name }}</p>
                            @if ($pkg->load_error)
                                <p class="mt-1 rounded bg-red-50 px-2 py-1 text-[11px] text-red-600 dark:bg-red-500/10 dark:text-red-400">{{ __('Erreur de chargement') }} : {{ $pkg->load_error }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        @if ($pkg->hasUpdate())
                            <button type="button" wire:click="update('{{ $pkg->key }}'{{ $pkg->breaking_update ? ', true' : '' }})" @disabled(! $enabled)
                                    class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">{{ __('Mettre à jour') }}</button>
                        @endif
                        @if (in_array($pkg->key, $configurableKeys, true))
                            <button type="button" wire:click="startSettings('{{ $pkg->key }}')" @disabled(! $enabled)
                                    class="rounded-lg border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-100 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800">{{ __('Configurer') }}</button>
                        @endif
                        <button type="button" wire:click="togglePackage('{{ $pkg->key }}')" @disabled(! $enabled)
                                class="rounded-lg border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-100 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800">
                            {{ $pkg->enabled ? __('Désactiver') : __('Activer') }}
                        </button>
                        <button type="button" @disabled(! $enabled)
                                @click="$store.confirm.open({ title: '{{ __('Désinstaller') }}', message: '{{ __('Retirer ce plugin de l’instance ?') }}', confirmLabel: '{{ __('Désinstaller') }}', danger: true }).then(ok => ok && $wire.uninstall('{{ $pkg->key }}'))"
                                class="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40 dark:hover:bg-red-500/10" title="{{ __('Désinstaller') }}">
                            <x-phosphor-trash class="h-4 w-4"/>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Install from a custom source: composer repositories + raw package name --}}
    @if ($showSource)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/50 p-4 backdrop-blur-sm" wire:key="plugin-source-modal">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                <h2 class="text-lg font-semibold">{{ __('Installer depuis une source') }}</h2>
                <p class="mt-1 text-xs text-neutral-500">{{ __('Le package sera résolu via Packagist et les sources ci-dessous, comme un composer require.') }}</p>

                <div class="mt-4">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-neutral-500">{{ __('Sources composer (repositories)') }}</p>
                    <div class="space-y-1.5">
                        @forelse ($repositories as $repository)
                            <div wire:key="repo-{{ $repository->id }}" class="flex items-center gap-2 rounded-lg border border-neutral-200 px-2.5 py-1.5 text-sm dark:border-neutral-700">
                                <span class="shrink-0 rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-neutral-500 dark:bg-neutral-800">{{ $repository->type }}</span>
                                <span class="min-w-0 flex-1 truncate" title="{{ $repository->url }}">{{ $repository->url }}</span>
                                <button type="button" wire:click="removeRepository({{ $repository->id }})" class="shrink-0 text-neutral-400 hover:text-red-500" title="{{ __('Retirer') }}">
                                    <x-phosphor-x class="h-3.5 w-3.5"/>
                                </button>
                            </div>
                        @empty
                            <p class="rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-400 dark:bg-neutral-800/50">{{ __('Aucune source personnalisée — Packagist est utilisé par défaut.') }}</p>
                        @endforelse
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <select wire:model="newRepoType" class="shrink-0 rounded-lg border border-neutral-300 bg-white px-2 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                            <option value="vcs">vcs</option>
                            <option value="composer">composer</option>
                        </select>
                        <input type="url" wire:model="newRepoUrl" wire:keydown.enter="addRepository" placeholder="https://…"
                               class="min-w-0 flex-1 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800">
                        <button type="button" wire:click="addRepository" class="shrink-0 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800">{{ __('Ajouter') }}</button>
                    </div>
                    @error('newRepoUrl') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="mt-5 border-t border-neutral-100 pt-4 dark:border-neutral-800">
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-neutral-500">{{ __('Package composer') }}</label>
                    <input type="text" wire:model="sourcePackage" wire:keydown.enter="installFromSource" placeholder="vendor/nom-du-plugin"
                           class="block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800">
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="closeSource"
                            class="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800">{{ __('Annuler') }}</button>
                    <button type="button" wire:click="installFromSource" @disabled(trim($sourcePackage) === '')
                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">
                        <span wire:loading.remove wire:target="installFromSource">{{ __('Installer') }}</span>
                        <span wire:loading wire:target="installFromSource">{{ __('Installation…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Catalog entry details: banner, screenshots, readme --}}
    @if ($detailsEntry !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/50 p-4 backdrop-blur-sm" wire:key="plugin-details-{{ $detailsEntry['key'] }}" wire:click.self="showDetails(null)">
            <div class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-neutral-900">
                @if ($detailsEntry['banner'] !== '')
                    <img src="{{ $detailsEntry['banner'] }}" alt="" class="h-36 w-full shrink-0 object-cover">
                @endif
                <div class="flex items-start justify-between gap-3 p-6 pb-0">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-800">
                            <x-dynamic-component :component="'phosphor-'.$detailsEntry['icon']" class="h-5 w-5 text-neutral-600 dark:text-neutral-300"/>
                        </span>
                        <div class="min-w-0">
                            <h2 class="truncate text-lg font-semibold">{{ $detailsEntry['name'] }}</h2>
                            <p class="truncate text-xs text-neutral-500">{{ $detailsEntry['package'] !== '' ? $detailsEntry['package'] : $detailsEntry['repo'] }}</p>
                        </div>
                    </div>
                    <button type="button" wire:click="showDetails(null)" class="shrink-0 rounded-full p-1.5 text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800">
                        <x-phosphor-x class="h-4 w-4"/>
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-6">
                    @if ($detailsEntry['screenshots'] !== [])
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-neutral-500">{{ __("Captures d'écran") }}</p>
                        <div class="mb-4 flex gap-3 overflow-x-auto pb-2">
                            @foreach ($detailsEntry['screenshots'] as $screenshot)
                                <a href="{{ $screenshot }}" target="_blank" rel="noopener noreferrer" class="shrink-0">
                                    <img src="{{ $screenshot }}" alt="" loading="lazy" class="h-40 rounded-lg border border-neutral-200 object-cover dark:border-neutral-700">
                                </a>
                            @endforeach
                        </div>
                    @endif
                    <div class="markdown text-sm text-neutral-700 dark:text-neutral-300">
                        {!! \Illuminate\Support\Str::markdown($detailsEntry['readme'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Instance settings for an installed plugin (no-code, no .env) --}}
    @if ($configuringKey !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/50 p-4 backdrop-blur-sm" wire:key="plugin-settings-{{ $configuringKey }}">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                <h2 class="text-lg font-semibold">{{ __('Réglages') }} · {{ $configuringKey }}</h2>
                <p class="mt-1 text-xs text-neutral-500">{{ __("Réglages d'instance, appliqués à tous les boards. Aucun redémarrage ni .env requis.") }}</p>

                <div class="mt-4 space-y-4">
                    @foreach ($settingsFields as $field)
                        @php $type = $field['type'] ?? 'text'; @endphp
                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                {{ $field['label'] ?? $field['key'] }}
                                @if ($field['required'] ?? false)<span class="text-red-500">&nbsp;*</span>@endif
                            </label>

                            @if ($type === 'boolean')
                                <input type="checkbox" wire:model="settingsDraft.{{ $field['key'] }}"
                                       class="h-4 w-4 rounded border-neutral-300 text-indigo-600 focus:ring-indigo-500">
                            @elseif ($type === 'select')
                                <select wire:model="settingsDraft.{{ $field['key'] }}"
                                        class="block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                                    @foreach (($field['options'] ?? []) as $opt)
                                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="{{ $type === 'password' ? 'password' : 'text' }}"
                                       wire:model="settingsDraft.{{ $field['key'] }}"
                                       placeholder="{{ $type === 'password' ? '••••••••' : ($field['placeholder'] ?? '') }}"
                                       class="block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800">
                            @endif

                            @if (! empty($field['help']))
                                <p class="mt-1 text-xs text-neutral-500">{{ $field['help'] }}</p>
                            @endif
                            @error('settingsDraft.'.$field['key'])
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelSettings"
                            class="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-100 dark:border-neutral-700 dark:hover:bg-neutral-800">{{ __('Annuler') }}</button>
                    <button type="button" wire:click="saveSettings"
                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">{{ __('Enregistrer') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
