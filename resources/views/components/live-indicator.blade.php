{{-- Live-refresh indicator: pulsating green dot + seconds since last Livewire
     render (each table poll re-renders this view, restamping data-ts).
     $error (nullable string) switches to a static red dot. --}}
<div style="display:flex;align-items:center;gap:.5rem;font-size:.75rem;font-weight:400;color:#9ca3af;"
     x-data="{ now: Date.now() }" x-init="setInterval(() => now = Date.now(), 1000)">
    @if ($error ?? null)
        <span style="display:inline-block;width:.5rem;height:.5rem;border-radius:9999px;background:#ef4444;flex:none;"></span>
        <span>live refresh failing &mdash; retrying&hellip;</span>
    @else
        <span style="position:relative;display:inline-flex;width:.5rem;height:.5rem;flex:none;">
            <span style="position:absolute;inset:0;border-radius:9999px;background:#22c55e;opacity:.6;animation:palworld-admin-ping 1.6s cubic-bezier(0,0,.2,1) infinite;"></span>
            <span style="position:relative;display:inline-block;width:100%;height:100%;border-radius:9999px;background:#22c55e;"></span>
        </span>
        <span data-ts="{{ now()->getTimestamp() }}"
              x-text="'last refresh ' + Math.max(0, Math.floor(now / 1000 - Number($el.dataset.ts))) + 's ago'">live</span>
    @endif
</div>
<style>
    @keyframes palworld-admin-ping {
        75%, 100% { transform: scale(2.4); opacity: 0; }
    }
</style>
