<x-filament-panels::page>
    {{-- invisible fallback poller: keeps an in-flight pal export finishing
         (download / state cleanup) if the modal - which carries the visible
         spinner and its own poll - was dismissed early --}}
    @if ($this->palExport)
        <div wire:poll.3s="checkPalExport"></div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
