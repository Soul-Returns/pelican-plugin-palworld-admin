{{-- Based on the core server-form-page view, minus its wire:submit="save":
     buttons inside a form default to type="submit", so every section action
     would ALSO trigger save() on click. Saving happens only via the explicit
     "Save to file" action. Also adds a transient power-state poller: the
     wire:poll element only exists while awaiting a stop/start, so polling
     stops by itself once settled. --}}
<x-filament-panels::page
    id="form"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()">

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
