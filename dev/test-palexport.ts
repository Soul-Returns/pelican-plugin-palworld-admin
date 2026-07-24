/**
 * Parity test for the TypeScript Pal-export pipeline against the python
 * implementation (filter-pals.py in the palworld yolk).
 *
 *   node dev/test-palexport.ts <Level.sav> <python-list.json> [out-dir]
 *
 * where python-list.json is the output of `filter-pals.py <sav> --list --json`.
 * Verifies the roster matches and writes filtered saves for every selector
 * combination so the caller can re-read them with the python tooling.
 */

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { PalExport } from '../resources/ts/palexport.ts';

const [savPath, pyJsonPath, outDir = '/tmp'] = process.argv.slice(2);
if (!savPath || !pyJsonPath) {
    console.error('usage: node dev/test-palexport.ts <Level.sav> <python-list.json> [out-dir]');
    process.exit(1);
}

const started = Date.now();
const exp = await PalExport.analyze(new Uint8Array(readFileSync(savPath)));
console.error(`analyze: ${Date.now() - started}ms`);

const py = JSON.parse(readFileSync(pyJsonPath, 'utf-8'));
const ts = exp.roster;

let failures = 0;
function check(what: string, expected: unknown, actual: unknown): void {
    const e = JSON.stringify(expected);
    const a = JSON.stringify(actual);
    if (e !== a) {
        failures++;
        console.error(`MISMATCH ${what}:\n  python: ${e}\n  ts:     ${a}`);
    }
}

check('entry count', py.entries, ts.entries);

const pyPlayers = new Map<string, any>(py.players.map((p: any) => [p.uid, p]));
check('player uids', [...pyPlayers.keys()].sort(), ts.players.map((p) => p.uid).sort());
for (const p of ts.players) {
    const ref = pyPlayers.get(p.uid);
    if (!ref) continue;
    check(`player ${p.uid} name`, ref.name, p.name);
    check(`player ${p.uid} pals`, ref.pals, p.pals);
    check(`player ${p.uid} group`, ref.group_id, p.group_id);
}

const pyGuilds = new Map<string, any>(py.guilds.map((g: any) => [g.id, g]));
check('guild ids', [...pyGuilds.keys()].sort(), ts.guilds.map((g) => g.id).sort());
for (const g of ts.guilds) {
    const ref = pyGuilds.get(g.id);
    if (!ref) continue;
    check(`guild ${g.id} name`, ref.name, g.name);
    check(`guild ${g.id} members`, [...ref.members].sort(), [...g.members].sort());
    check(`guild ${g.id} pals`, ref.pals, g.pals);
    check(`guild ${g.id} ownerless`, ref.ownerless, g.ownerless);
    check(`guild ${g.id} base_pals`, ref.base_pals, g.base_pals);
}

// filtered outputs for python cross-validation
mkdirSync(outDir, { recursive: true });
const topPlayer = ts.players.find((p) => p.pals > 0)!;
const guild = ts.guilds.find((g) => g.members.length > 0)!;
const cases: Array<[string, Parameters<typeof exp.filter>[0]]> = [
    ['player-only', { players: [topPlayer.uid] }],
    ['player-base', { players: [topPlayer.uid], includeBasePals: true }],
    ['guild-only', { guilds: [guild.id] }],
    ['guild-base', { guilds: [guild.id], includeBasePals: true }],
];
for (const [label, selection] of cases) {
    const out = await exp.filter(selection);
    const path = join(outDir, `ts-${label}.sav`);
    writeFileSync(path, out);
    console.log(`${label}: ${out.length} bytes -> ${path}`);
}

console.error(failures === 0 ? 'ROSTER PARITY OK' : `${failures} MISMATCHES`);
process.exit(failures === 0 ? 0 : 1);
