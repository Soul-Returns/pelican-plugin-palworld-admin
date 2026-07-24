{{--
    Client-side Pal export: the whole pipeline (download Level.sav via the
    panel proxy, decompress/parse/filter in the browser, hand back a filtered
    .sav) runs in this Alpine component - the server only streams the file.
--}}
<div
    x-data="{
        assetUrl: @js($assetUrl),
        savUrl: @js($savUrl),
        statUrl: @js($statUrl),
        canSave: @js($canSave),

        phase: 'boot',           // boot | save | download | analyze | select | filter | error
        error: '',
        progress: '',
        progressPct: null,       // 0..100 while downloading with a known total
        exporter: null,          // PalExport instance
        roster: null,

        tab: 'players',
        selPlayers: {},          // uid -> true
        selGuilds: {},           // group id -> true
        includeBasePals: true,
        openMembers: {},

        async init() {
            try {
                await this.loadLib();
                if (this.canSave) {
                    this.phase = 'save';
                    this.progress = 'Saving world on the server...';
                    // REST /save only *triggers* the save - the game writes the
                    // files asynchronously with no completion signal. Snapshot
                    // the file's mtime/size first, then poll until it changed
                    // and stopped growing.
                    const baseline = await this.stat();
                    const saved = await this.$wire.palSaveWorld();
                    if (saved && baseline) {
                        await this.waitForFreshSave(baseline);
                    }
                }
                this.phase = 'download';
                const sav = await this.download();
                this.phase = 'analyze';
                this.progress = 'Reading world data in your browser...';
                this.exporter = await window.PalworldPalExport.analyze(sav);
                this.roster = this.exporter.roster;
                this.phase = 'select';
            } catch (e) {
                this.error = e?.message ?? String(e);
                this.phase = 'error';
            }
        },

        loadLib() {
            window._palExportLib ??= new Promise((resolve, reject) => {
                if (window.PalworldPalExport) return resolve();
                const s = document.createElement('script');
                s.src = this.assetUrl;
                s.onload = () => resolve();
                s.onerror = () => reject(new Error('Could not load the export script'));
                document.head.appendChild(s);
            });
            return window._palExportLib;
        },

        async stat() {
            try {
                const res = await fetch(this.statUrl, { credentials: 'same-origin' });
                return res.ok ? await res.json() : null;
            } catch {
                return null;
            }
        },

        async waitForFreshSave(baseline) {
            let prev = null;
            for (let s = 1; s <= 30; s++) {
                this.progress = `World save triggered - waiting for the files to be written (${s}s)...`;
                await new Promise(r => setTimeout(r, 1000));
                const cur = await this.stat();
                if (!cur) continue;
                const changed = cur.mtime !== baseline.mtime || cur.size !== baseline.size;
                if (changed) {
                    // one more matching poll = the writer is done (torn-write guard)
                    if (prev && prev.mtime === cur.mtime && prev.size === cur.size) return;
                    prev = cur;
                }
            }
            // timeout - proceed with whatever is on disk, same guarantee as the old fixed wait
        },

        async download() {
            const res = await fetch(this.savUrl, { credentials: 'same-origin' });
            if (!res.ok) throw new Error(`Could not download the world save (HTTP ${res.status})`);
            const total = Number(res.headers.get('Content-Length') || 0);
            const reader = res.body.getReader();
            const chunks = [];
            let received = 0;
            const startedAt = performance.now();
            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;
                chunks.push(value);
                received += value.length;
                const elapsed = (performance.now() - startedAt) / 1000;
                const speed = elapsed > 0.2 ? ` @ ${(received / 1048576 / elapsed).toFixed(1)} MB/s` : '';
                const mb = (received / 1048576).toFixed(1);
                if (total > 0) {
                    this.progressPct = Math.min(100, (received / total) * 100);
                    this.progress = `Downloading world save... ${mb} / ${(total / 1048576).toFixed(1)} MB${speed}`;
                } else {
                    this.progress = `Downloading world save... ${mb} MB${speed}`;
                }
            }
            this.progressPct = null;
            const out = new Uint8Array(received);
            let off = 0;
            for (const c of chunks) { out.set(c, off); off += c.length; }
            return out;
        },

        selectedPlayers() { return Object.keys(this.selPlayers).filter(k => this.selPlayers[k]); },
        selectedGuilds() { return Object.keys(this.selGuilds).filter(k => this.selGuilds[k]); },
        canExport() { return this.selectedPlayers().length + this.selectedGuilds().length > 0; },

        guildLabel(g) {
            const own = Math.max(0, g.pals - g.ownerless);
            return `${g.name ?? g.id.slice(0, 8)} (${own} pals, ${g.base_pals} at base)`;
        },

        async doExport() {
            if (!this.canExport() || this.phase !== 'select') return;
            this.phase = 'filter';
            this.progress = 'Filtering pals in your browser...';
            try {
                const bytes = await this.exporter.filter({
                    players: this.selectedPlayers(),
                    guilds: this.selectedGuilds(),
                    includeBasePals: this.includeBasePals,
                });
                const url = URL.createObjectURL(new Blob([bytes], { type: 'application/octet-stream' }));
                const a = document.createElement('a');
                a.href = url;
                a.download = 'Level.filtered.sav';
                a.click();
                setTimeout(() => URL.revokeObjectURL(url), 60000);
                this.phase = 'select';
                this.progress = '';
            } catch (e) {
                this.error = e?.message ?? String(e);
                this.phase = 'error';
            }
        },
    }"
    class="fi-fo-component-ctn"
    style="display:flex;flex-direction:column;gap:1rem;"
>
    {{-- progress phases --}}
    <template x-if="['boot','save','download','analyze','filter'].includes(phase)">
        <div style="display:flex;flex-direction:column;gap:.5rem;">
            <div style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;color:#9ca3af;">
                <x-filament::loading-indicator style="height:1.25rem;width:1.25rem;flex:none;" />
                <span x-text="progress || 'Preparing...'"></span>
            </div>
            <div x-show="progressPct !== null" style="height:.375rem;border-radius:9999px;background:rgba(255,255,255,.1);overflow:hidden;">
                <div style="height:100%;border-radius:9999px;background:rgb(59,130,246);transition:width .2s;"
                    x-bind:style="`width:${progressPct ?? 0}%`"></div>
            </div>
        </div>
    </template>

    <template x-if="phase === 'error'">
        <div style="font-size:.875rem;color:#f87171;" x-text="error"></div>
    </template>

    {{-- selection --}}
    <template x-if="phase === 'select' || phase === 'filter'">
        <div style="display:flex;flex-direction:column;gap:1rem;">
            <div style="display:flex;gap:.25rem;border-bottom:1px solid rgba(255,255,255,.1);padding-bottom:.5rem;">
                <x-filament::button color="gray" size="sm" x-bind:outlined="tab !== 'players'" x-on:click="tab = 'players'">
                    Players
                </x-filament::button>
                <x-filament::button color="gray" size="sm" x-bind:outlined="tab !== 'guilds'" x-on:click="tab = 'guilds'">
                    Guilds
                </x-filament::button>
            </div>

            <div x-show="tab === 'players'" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.5rem;">
                <template x-for="p in roster.players" :key="p.uid">
                    <label style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;cursor:pointer;">
                        <input type="checkbox" class="fi-checkbox-input" x-model="selPlayers[p.uid]" />
                        <span x-text="`${p.name} (${p.pals} pals)`"></span>
                    </label>
                </template>
            </div>
            <div x-show="tab === 'players'" style="font-size:.75rem;color:#9ca3af;margin-top:-.5rem;">
                Each player's own pals: party + palbox.
            </div>

            <div x-show="tab === 'guilds'" style="display:flex;flex-direction:column;gap:.5rem;">
                <template x-for="g in roster.guilds" :key="g.id">
                    <div style="font-size:.875rem;">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                            <input type="checkbox" class="fi-checkbox-input" x-model="selGuilds[g.id]" />
                            <span style="font-weight:600;" x-text="guildLabel(g)"></span>
                        </label>
                        <div style="margin-left:1.75rem;color:#9ca3af;font-size:.8125rem;">
                            <button type="button" style="text-decoration:underline;" x-on:click="openMembers[g.id] = !openMembers[g.id]"
                                x-text="`${g.members.length} members ${openMembers[g.id] ? '−' : '+'}`"></button>
                            <span x-show="openMembers[g.id]" x-text="g.members.join(', ') || '-'"></span>
                        </div>
                    </div>
                </template>
                <div style="font-size:.75rem;color:#9ca3af;">
                    Every member's own pals (party + palbox) of the guild.
                </div>
            </div>

            <label style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;cursor:pointer;">
                <input type="checkbox" class="fi-checkbox-input" x-model="includeBasePals" />
                <span>Include base pals - everything deployed at the selected players' / guilds' bases (also pals deployed by other guild members)</span>
            </label>

            <div style="display:flex;justify-content:flex-end;">
                <x-filament::button color="info" x-on:click="doExport()" x-bind:disabled="!canExport() || phase === 'filter'">
                    Export
                </x-filament::button>
            </div>
        </div>
    </template>
</div>
