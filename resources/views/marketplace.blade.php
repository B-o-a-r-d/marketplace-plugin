<div class="mx-auto max-w-4xl">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="flex items-center gap-2 text-xl font-semibold tracking-tight sm:text-2xl">
                <x-phosphor-puzzle-piece class="h-6 w-6"/> {{ __('Marketplace') }}
            </h1>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Installez des Power-Ups à chaud, sans redéploiement.') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-300">
                {{ $enabled ? __('Activée') : __('Désactivée') }}
                <button type="button" role="switch" :aria-checked="@js($enabled)" wire:click="toggleEnabled"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $enabled ? 'bg-indigo-600' : 'bg-neutral-300 dark:bg-neutral-700' }}">
                    <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $enabled ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                </button>
            </label>
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

    <div class="space-y-3">
        @forelse ($catalog as $entry)
            @php $pkg = $installed[$entry['key']] ?? null; @endphp
            <div wire:key="mk-{{ $entry['key'] }}" class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-800">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-800">
                            <x-dynamic-component :component="'phosphor-'.$entry['icon']" class="h-5 w-5 text-neutral-600 dark:text-neutral-300"/>
                        </span>
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {{ $entry['name'] }}
                                @if ($pkg)
                                    <span class="rounded bg-green-100 px-1.5 py-0.5 text-[10px] font-medium text-green-700 dark:bg-green-500/15 dark:text-green-400">v{{ $pkg->version }}</span>
                                    @unless ($pkg->enabled)
                                        <span class="rounded bg-neutral-200 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-neutral-700">{{ __('inactif') }}</span>
                                    @endunless
                                    @if ($pkg->hasUpdate())
                                        <span class="rounded px-1.5 py-0.5 text-[10px] font-medium {{ $pkg->breaking_update ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' }}">
                                            {{ $pkg->breaking_update ? __('maj majeure v:v', ['v' => $pkg->available_version]) : __('maj v:v', ['v' => $pkg->available_version]) }}
                                        </span>
                                    @endif
                                @endif
                            </p>
                            <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-neutral-400">{{ $entry['description'] }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                @foreach ($entry['capabilities'] as $cap)
                                    <span class="rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{{ $cap }}</span>
                                @endforeach
                                <a href="https://github.com/{{ $entry['repo'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-[11px] text-neutral-400 hover:text-indigo-600 hover:underline" title="{{ __('Voir sur GitHub') }}">
                                    {{ $entry['repo'] }}
                                    <x-phosphor-arrow-square-out class="h-3 w-3 shrink-0"/>
                                </a>
                            </div>
                            @if ($pkg?->load_error)
                                <p class="mt-2 rounded bg-red-50 px-2 py-1 text-[11px] text-red-600 dark:bg-red-500/10 dark:text-red-400">{{ __('Erreur de chargement') }} : {{ $pkg->load_error }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-1.5">
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
                            <div class="flex items-center gap-1.5">
                                <button type="button" wire:click="togglePackage('{{ $entry['key'] }}')" @disabled(! $enabled)
                                        class="rounded-lg border border-neutral-300 px-2 py-1 text-xs hover:bg-neutral-100 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800">
                                    {{ $pkg->enabled ? __('Désactiver') : __('Activer') }}
                                </button>
                                <button type="button" @disabled(! $enabled)
                                        @click="$store.confirm.open({ title: '{{ __('Désinstaller') }}', message: '{{ __('Retirer ce plugin de l’instance ?') }}', confirmLabel: '{{ __('Désinstaller') }}', danger: true }).then(ok => ok && $wire.uninstall('{{ $entry['key'] }}'))"
                                        class="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40 dark:hover:bg-red-500/10" title="{{ __('Désinstaller') }}">
                                    <x-phosphor-trash class="h-4 w-4"/>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-neutral-300 p-10 text-center text-sm text-neutral-400 dark:border-neutral-700">
                {{ __('Catalogue vide ou indisponible.') }}
            </div>
        @endforelse
    </div>
</div>
