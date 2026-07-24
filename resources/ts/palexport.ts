/**
 * Client-side Pal export: analyze a Palworld Level.sav entirely in the
 * browser, list players/guilds, and produce a filtered Level.sav.
 *
 * Port of the yolk's filter-pals.py - selection semantics are identical:
 *   - players / guilds select "own" pals (party + palbox, OwnerPlayerUId set),
 *   - includeBasePals additionally keeps everything deployed at the selected
 *     players'/guilds' bases (deploying clears OwnerPlayerUId; the base-camp
 *     worker-container match also catches owned pals standing at a base).
 */

import { LevelSurgeon, type CharacterEntry } from './gvas.ts';
import { compressSav, decompressSav } from './palsav.ts';

export interface RosterPlayer {
    uid: string;
    name: string;
    group_id: string;
    pals: number;
}

export interface RosterGuild {
    id: string;
    name: string | null;
    members: string[];
    pals: number;
    ownerless: number;
    base_pals: number;
}

export interface Roster {
    entries: number;
    players: RosterPlayer[];
    guilds: RosterGuild[];
}

export interface FilterSelection {
    players?: string[];
    guilds?: string[];
    includeBasePals?: boolean;
}

export class PalExport {
    readonly roster: Roster;
    private readonly surgeon: LevelSurgeon;
    private readonly saveType: number;
    private readonly baseContainers: Map<string, string>;

    private constructor(surgeon: LevelSurgeon, saveType: number, baseContainers: Map<string, string>, roster: Roster) {
        this.surgeon = surgeon;
        this.saveType = saveType;
        this.baseContainers = baseContainers;
        this.roster = roster;
    }

    static async analyze(sav: Uint8Array): Promise<PalExport> {
        const { gvas, saveType } = await decompressSav(sav);
        const surgeon = new LevelSurgeon(gvas);

        const baseContainers = new Map<string, string>();
        for (const camp of surgeon.baseCamps) {
            if (camp.workerContainerId) baseContainers.set(camp.workerContainerId, camp.groupId);
        }

        return new PalExport(surgeon, saveType, baseContainers, buildRoster(surgeon, baseContainers));
    }

    /** Filtered Level.sav bytes (always recompressed as legacy zlib / PlZ). */
    async filter(selection: FilterSelection): Promise<Uint8Array> {
        const keepUids = new Set(selection.players ?? []);
        const keepGroups = new Set(selection.guilds ?? []);
        if (keepUids.size === 0 && keepGroups.size === 0) {
            throw new Error('nothing selected');
        }

        const baseGroups = new Set<string>();
        if (selection.includeBasePals) {
            for (const gid of keepGroups) baseGroups.add(gid);
            for (const p of this.roster.players) {
                if (keepUids.has(p.uid)) baseGroups.add(p.group_id);
            }
        }

        const gvas = this.surgeon.splice((e: CharacterEntry) => {
            if (e.isPlayer) {
                return keepUids.has(e.uid) || keepGroups.has(e.groupId);
            }
            if (e.owner === '') {
                // ownerless = deployed at a guild base; only the base toggle keeps these
                return baseGroups.has(e.groupId);
            }
            return keepUids.has(e.owner) || keepGroups.has(e.groupId)
                || (baseGroups.size > 0 && baseGroups.has(this.baseContainers.get(e.containerId) ?? ''));
        });

        return compressSav(gvas, this.saveType);
    }
}

// ------------------------------------------------------------------- roster

function buildRoster(surgeon: LevelSurgeon, baseContainers: Map<string, string>): Roster {
    const players = new Map<string, RosterPlayer>();
    const guilds = new Map<string, RosterGuild>();

    const guildOf = (gid: string): RosterGuild => {
        let g = guilds.get(gid);
        if (!g) {
            g = { id: gid, name: null, members: [], pals: 0, ownerless: 0, base_pals: 0 };
            guilds.set(gid, g);
        }
        return g;
    };

    for (const e of surgeon.characters) {
        const guild = guildOf(e.groupId);
        if (e.isPlayer) {
            const existing = players.get(e.uid);
            players.set(e.uid, {
                uid: e.uid,
                name: e.nickName || '?',
                group_id: e.groupId,
                pals: existing?.pals ?? 0,
            });
            guild.members.push(e.nickName || '?');
        } else {
            if (e.owner !== '') {
                let p = players.get(e.owner);
                if (!p) {
                    p = { uid: e.owner, name: '(unknown)', group_id: e.groupId, pals: 0 };
                    players.set(e.owner, p);
                }
                p.pals += 1;
            } else {
                guild.ownerless += 1;
            }
            if (baseContainers.has(e.containerId)) guild.base_pals += 1;
            guild.pals += 1;
        }
    }

    for (const grp of surgeon.guildGroups) {
        const guild = guilds.get(grp.id);
        if (guild) {
            guild.name = guildNameFromBlob(grp.blob, new Set(guild.members));
        }
    }

    return {
        entries: surgeon.characters.length,
        players: [...players.values()].sort((a, b) => b.pals - a.pals),
        guilds: [...guilds.values()].sort((a, b) => b.pals - a.pals),
    };
}

// -------------------------------------------------------------- guild names

const ASCII = new TextDecoder('latin1');
const UTF16LE = new TextDecoder('utf-16le');
const HEXISH = /^[0-9A-Fa-f-]{16,36}$/;

/** Every plausible FString (utf-8 or utf-16le, NUL-terminated) in the blob. */
function fstringCandidates(data: Uint8Array): Array<[number, string]> {
    const view = new DataView(data.buffer, data.byteOffset, data.byteLength);
    const out: Array<[number, string]> = [];
    for (let i = 0; i + 4 <= data.length; i++) {
        const n = view.getInt32(i, true);
        if (n >= 2 && n <= 64 && i + 4 + n <= data.length && data[i + 3 + n] === 0) {
            const raw = data.subarray(i + 4, i + 3 + n);
            let printable = raw.length > 0;
            for (const c of raw) {
                if (c < 32 || c >= 127) {
                    printable = false;
                    break;
                }
            }
            if (printable) out.push([i, ASCII.decode(raw)]);
        } else if (n <= -2 && n >= -64 && i + 4 - n * 2 <= data.length) {
            const end = i + 4 - n * 2;
            if (data[end - 1] === 0 && data[end - 2] === 0) {
                const s = UTF16LE.decode(data.subarray(i + 4, end - 2));
                if (s.length > 0 && [...s].every((c) => isPrintable(c))) out.push([i, s]);
            }
        }
    }
    return out;
}

function isPrintable(c: string): boolean {
    const code = c.codePointAt(0)!;
    if (code < 32 || (code >= 0x7f && code < 0xa0)) return false;
    return !/\p{Cc}|\p{Cn}|\p{Cs}/u.test(c);
}

/**
 * Best-effort guild name: in the guild group struct the name is the last
 * string before the member roster; hex-ish id strings are skipped.
 */
export function guildNameFromBlob(blob: Uint8Array, memberNames: Set<string>): string | null {
    const cands = fstringCandidates(blob).filter(([, s]) => !HEXISH.test(s));
    if (cands.length === 0) return null;
    const memberOffsets = cands.filter(([, s]) => memberNames.has(s)).map(([off]) => off);
    if (memberOffsets.length > 0) {
        const first = Math.min(...memberOffsets);
        const before = cands.filter(([off, s]) => off < first && !memberNames.has(s));
        if (before.length > 0) return before[before.length - 1][1];
    }
    return memberNames.has(cands[0][1]) ? null : cands[0][1];
}
