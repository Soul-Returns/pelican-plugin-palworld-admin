<x-filament-panels::page>
    @if ($this->palExport)
        <div wire:poll.3s="checkPalExport"
             style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;color:#9ca3af;">
            <x-filament::loading-indicator style="height:1.25rem;width:1.25rem;flex:none;" />
            <span>{{ $this->palExport['state'] === 'listing'
                ? 'Reading world save on the node - the selection dialog opens when ready...'
                : 'Filtering pals on the node (world save included) - the download starts when ready...' }}</span>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
