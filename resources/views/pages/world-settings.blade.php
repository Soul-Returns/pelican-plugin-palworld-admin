{{-- Based on the core server-form-page view, minus its wire:submit="save":
     buttons inside a form default to type="submit", so every section action
     would ALSO trigger save() on click. Saving happens only via the explicit
     "Save to file" action. Also adds a transient power-state poller: the
     wire:poll element only exists while awaiting a stop/start, so polling
     stops by itself once settled. --}}
<x-filament-panels::page
    id="form"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()">

    @if ($this->worldOptionSav)
        <div style="display:flex;gap:.6rem;align-items:flex-start;padding:.75rem 1rem;border:1px solid rgba(245,158,11,.4);border-radius:.5rem;background:rgba(245,158,11,.08);font-size:.875rem;line-height:1.4;">
            <x-filament::icon icon="tabler-alert-triangle" style="height:1.25rem;width:1.25rem;flex:none;color:#f59e0b;" />
            <span>
                <strong>This world has a WorldOption.sav</strong> (SaveGames/0/&lt;world&gt;/). The game takes most world
                settings from that file instead of PalWorldSettings.ini, and on shutdown rewrites the settings file
                from it &mdash; silently removing every setting the .sav does not contain (newer ones like
                bAllowGlobalPalboxImport). To make this page authoritative: stop the server, delete
                WorldOption.sav via the Files page, then start again.
            </span>
        </div>
    @endif

    @if ($this->awaitingTarget)
        <div wire:poll.3s="checkPowerState"
             style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;color:#9ca3af;">
            <x-filament::loading-indicator style="height:1.25rem;width:1.25rem;flex:none;" />
            <span>
                {{ $this->awaitingTarget === 'stopped'
                    ? 'Stopping server (world + settings are written to disk) — editing unlocks automatically…'
                    : 'Starting server…' }}
            </span>
        </div>
    @endif

    {{ $this->form }}
</x-filament-panels::page>
