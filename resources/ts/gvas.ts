/**
 * Surgical GVAS access for Palworld Level.sav.
 *
 * Instead of parsing + re-serializing the whole save (the round-trip-corruption
 * trap), this walks property HEADERS only, records the byte ranges of the
 * things we care about, and edits the file by splicing bytes:
 *
 *   - dropping character-map entries removes their exact byte ranges,
 *   - the only fields that change are the two covering size fields
 *     (worldSaveData StructProperty + CharacterSaveParameterMap MapProperty)
 *     and the map's entry count,
 *   - every other byte of the save stays untouched.
 *
 * Size semantics (verified against palworld-save-tools' FArchiveWriter):
 *   - a property's u64 size counts its VALUE bytes only - for StructProperty
 *     from after struct_type/struct_id/id-flag, for Map/ArrayProperty from
 *     after the type names + id-flag.
 */

import { ByteReader, ZERO_UID } from './binary.ts';

export interface CharacterEntry {
    /** absolute byte range [start, end) of the whole map entry */
    start: number;
    end: number;
    isPlayer: boolean;
    /** key PlayerUId for players, SaveParameter.OwnerPlayerUId ('' if none) for pals */
    uid: string;
    owner: string;
    nickName: string;
    groupId: string;
    containerId: string;
}

export interface BaseCamp {
    groupId: string;
    workerContainerId: string;
}

export interface GuildGroup {
    id: string;
    blob: Uint8Array;
}

interface MapRegion {
    sizePos: number;
    size: number;
    countPos: number;
    count: number;
    entriesStart: number;
    entriesEnd: number;
}

const CHARACTER_MAP = 'CharacterSaveParameterMap';
const BASE_CAMP_MAP = 'BaseCampSaveData';
const GROUP_MAP = 'GroupSaveDataMap';

export class LevelSurgeon {
    readonly gvas: Uint8Array;
    readonly characters: CharacterEntry[] = [];
    readonly baseCamps: BaseCamp[] = [];
    readonly guildGroups: GuildGroup[] = [];

    private worldSizePos = -1;
    private worldSize = -1;
    private charMap: MapRegion | null = null;

    constructor(gvas: Uint8Array) {
        this.gvas = gvas;
        this.parse();
    }

    // ------------------------------------------------------------- parsing

    private parse(): void {
        const r = new ByteReader(this.gvas);
        skipGvasHeader(r);

        // top-level property stream: find worldSaveData
        for (;;) {
            const name = r.fstring();
            if (name === 'None') throw new Error('worldSaveData not found');
            const type = r.fstring();
            const sizePos = r.off;
            const size = r.u64();
            if (name === 'worldSaveData') {
                if (type !== 'StructProperty') throw new Error(`worldSaveData is ${type}?`);
                this.worldSizePos = sizePos;
                this.worldSize = size;
                r.fstring(); // struct_type
                r.skip(16); // struct_id
                r.skipOptionalGuid();
                this.parseWorldSaveData(r, r.off + size);
                return;
            }
            skipPropertyPayload(r, type, size);
        }
    }

    private parseWorldSaveData(r: ByteReader, end: number): void {
        while (r.off < end) {
            const name = r.fstring();
            if (name === 'None') break;
            const type = r.fstring();
            const sizePos = r.off;
            const size = r.u64();
            if (type === 'MapProperty' && (name === CHARACTER_MAP || name === BASE_CAMP_MAP || name === GROUP_MAP)) {
                this.parseMap(r, name, size, sizePos);
            } else {
                skipPropertyPayload(r, type, size);
            }
        }
    }

    private parseMap(r: ByteReader, name: string, size: number, sizePos: number): void {
        r.fstring(); // key type
        r.fstring(); // value type
        r.skipOptionalGuid();
        const payloadStart = r.off;
        r.u32(); // always-zero field before the count
        const countPos = r.off;
        const count = r.u32();
        const region: MapRegion = {
            sizePos,
            size,
            countPos,
            count,
            entriesStart: r.off,
            entriesEnd: payloadStart + size,
        };

        for (let i = 0; i < count; i++) {
            if (name === CHARACTER_MAP) this.parseCharacterEntry(r);
            else if (name === BASE_CAMP_MAP) this.parseBaseCampEntry(r);
            else this.parseGroupEntry(r);
        }

        if (r.off !== region.entriesEnd) {
            throw new Error(`${name}: walked to ${r.off}, size field says ${region.entriesEnd}`);
        }
        if (name === CHARACTER_MAP) {
            this.charMap = region;
        }
    }

    private parseCharacterEntry(r: ByteReader): void {
        const start = r.off;
        let uid = '';
        // key: struct as generic property stream (PlayerUId + InstanceId)
        walkProps(r, (n, type, size) => {
            if (n === 'PlayerUId' && type === 'StructProperty') {
                uid = readGuidStruct(r);
            } else {
                skipPropertyPayload(r, type, size);
            }
        });

        let blobStart = -1;
        let blobEnd = -1;
        // value: struct stream containing RawData (ArrayProperty of bytes)
        walkProps(r, (n, type, size) => {
            if (n === 'RawData' && type === 'ArrayProperty') {
                const range = readByteArrayRange(r, size);
                blobStart = range[0];
                blobEnd = range[1];
            } else {
                skipPropertyPayload(r, type, size);
            }
        });
        const end = r.off;

        const entry: CharacterEntry = {
            start, end, isPlayer: false, uid, owner: '', nickName: '', groupId: '', containerId: '',
        };
        if (blobStart >= 0) {
            parseCharacterBlob(new ByteReader(this.gvas.subarray(blobStart, blobEnd)), entry);
        }
        this.characters.push(entry);
    }

    private parseBaseCampEntry(r: ByteReader): void {
        r.skip(16); // key guid
        let groupId = '';
        let workerContainerId = '';
        walkProps(r, (n, type, size) => {
            if (n === 'RawData' && type === 'ArrayProperty') {
                const [bs, be] = readByteArrayRange(r, size);
                const b = new ByteReader(this.gvas.subarray(bs, be));
                b.skip(16); // id
                b.fstring(); // name
                b.skip(1 + 80 + 4); // state + ftransform + area_range
                groupId = b.guid();
            } else if (n === 'WorkerDirector' && type === 'StructProperty') {
                r.fstring();
                r.skip(16);
                r.skipOptionalGuid();
                walkProps(r, (wn, wtype, wsize) => {
                    if (wn === 'RawData' && wtype === 'ArrayProperty') {
                        const [bs, be] = readByteArrayRange(r, wsize);
                        const b = new ByteReader(this.gvas.subarray(bs, be));
                        b.skip(16 + 80 + 2); // id + spawn_transform + order/battle bytes
                        workerContainerId = b.guid();
                    } else {
                        skipPropertyPayload(r, wtype, wsize);
                    }
                });
            } else {
                skipPropertyPayload(r, type, size);
            }
        });
        this.baseCamps.push({ groupId, workerContainerId });
    }

    private parseGroupEntry(r: ByteReader): void {
        const id = new ByteReader(this.gvas, r.off).guid();
        r.skip(16); // key guid
        let groupType = '';
        let blob: Uint8Array | null = null;
        walkProps(r, (n, type, size) => {
            if (n === 'GroupType' && type === 'EnumProperty') {
                r.fstring(); // enum type name
                r.skipOptionalGuid();
                groupType = r.fstring();
            } else if (n === 'RawData' && type === 'ArrayProperty') {
                const [bs, be] = readByteArrayRange(r, size);
                blob = this.gvas.subarray(bs, be);
            } else {
                skipPropertyPayload(r, type, size);
            }
        });
        if (groupType.includes('Guild') && blob) {
            this.guildGroups.push({ id, blob });
        }
    }

    // ------------------------------------------------------------- surgery

    /** New GVAS bytes with only the given character entries kept. */
    splice(keep: (entry: CharacterEntry) => boolean): Uint8Array {
        const map = this.charMap;
        if (!map) throw new Error('character map not parsed');

        const kept: CharacterEntry[] = [];
        let droppedBytes = 0;
        for (const e of this.characters) {
            if (keep(e)) kept.push(e);
            else droppedBytes += e.end - e.start;
        }
        if (!kept.some((e) => !e.isPlayer)) {
            throw new Error('selection matched no pals');
        }

        // header segment with the three patches applied
        const head = this.gvas.slice(0, map.entriesStart);
        const headView = new DataView(head.buffer);
        headView.setBigUint64(this.worldSizePos, BigInt(this.worldSize - droppedBytes), true);
        headView.setBigUint64(map.sizePos, BigInt(map.size - droppedBytes), true);
        headView.setUint32(map.countPos, kept.length, true);

        const tail = this.gvas.subarray(map.entriesEnd);
        const out = new Uint8Array(this.gvas.length - droppedBytes);
        out.set(head, 0);
        let off = head.length;
        for (const e of kept) {
            out.set(this.gvas.subarray(e.start, e.end), off);
            off += e.end - e.start;
        }
        out.set(tail, off);
        return out;
    }
}

// ------------------------------------------------------------------ helpers

function skipGvasHeader(r: ByteReader): void {
    const magic = r.i32();
    if (magic !== 0x53415647) throw new Error('not a GVAS file');
    r.skip(4 + 4 + 4); // save game version, ue4, ue5
    r.skip(2 + 2 + 2 + 4); // engine version u16 x3 + changelist u32
    r.fstring(); // branch
    r.i32(); // custom version format
    const versions = r.u32();
    r.skip(versions * 20); // guid + i32 each
    r.fstring(); // save game class name
}

/** Property stream: (name, type, size) until the 'None' terminator. */
export function walkProps(
    r: ByteReader,
    visit: (name: string, type: string, size: number, sizePos: number) => void,
): void {
    for (;;) {
        const name = r.fstring();
        if (name === 'None') return;
        const type = r.fstring();
        const sizePos = r.off;
        const size = r.u64();
        visit(name, type, size, sizePos);
    }
}

/** Skip a property's payload; reader sits right after the size field. */
export function skipPropertyPayload(r: ByteReader, type: string, size: number): void {
    switch (type) {
        case 'StructProperty':
            r.fstring();
            r.skip(16);
            r.skipOptionalGuid();
            r.skip(size);
            return;
        case 'ArrayProperty':
            r.fstring();
            r.skipOptionalGuid();
            r.skip(size);
            return;
        case 'MapProperty':
            r.fstring();
            r.fstring();
            r.skipOptionalGuid();
            r.skip(size);
            return;
        case 'EnumProperty':
        case 'ByteProperty':
            r.fstring();
            r.skipOptionalGuid();
            r.skip(size);
            return;
        case 'BoolProperty':
            r.u8();
            r.skipOptionalGuid();
            return;
        case 'IntProperty':
        case 'UInt16Property':
        case 'UInt32Property':
        case 'Int64Property':
        case 'FixedPoint64Property':
        case 'FloatProperty':
        case 'StrProperty':
        case 'NameProperty':
            r.skipOptionalGuid();
            r.skip(size);
            return;
        default:
            throw new Error(`unknown property type: ${type}`);
    }
}

/** StructProperty(Guid) value: struct header then 16 raw bytes. */
function readGuidStruct(r: ByteReader): string {
    r.fstring(); // struct_type ('Guid')
    r.skip(16); // struct_id
    r.skipOptionalGuid();
    return r.guid();
}

/** ArrayProperty(ByteProperty): returns [start, end) of the raw bytes. */
function readByteArrayRange(r: ByteReader, size: number): [number, number] {
    const arrayType = r.fstring();
    if (arrayType !== 'ByteProperty') throw new Error(`expected ByteProperty array, got ${arrayType}`);
    r.skipOptionalGuid();
    const count = r.u32();
    const start = r.off;
    r.skip(count);
    if (r.off - start !== size - 4) throw new Error('byte array size mismatch');
    return [start, r.off];
}

/** Character rawdata blob: SaveParameter property stream + 4 bytes + group_id. */
function parseCharacterBlob(b: ByteReader, entry: CharacterEntry): void {
    walkProps(b, (name, type, size) => {
        if (name === 'SaveParameter' && type === 'StructProperty') {
            b.fstring();
            b.skip(16);
            b.skipOptionalGuid();
            walkProps(b, (pn, ptype, psize) => {
                switch (pn) {
                    case 'IsPlayer':
                        entry.isPlayer = b.u8() > 0;
                        b.skipOptionalGuid();
                        return;
                    case 'OwnerPlayerUId': {
                        const owner = readGuidStruct(b);
                        entry.owner = owner === ZERO_UID ? '' : owner;
                        return;
                    }
                    case 'NickName':
                        b.skipOptionalGuid();
                        entry.nickName = b.fstring();
                        return;
                    case 'SlotID':
                    case 'SlotId':
                        b.fstring();
                        b.skip(16);
                        b.skipOptionalGuid();
                        walkProps(b, (sn, stype, ssize) => {
                            if (sn === 'ContainerId' && stype === 'StructProperty') {
                                b.fstring();
                                b.skip(16);
                                b.skipOptionalGuid();
                                walkProps(b, (cn, ctype, csize) => {
                                    if (cn === 'ID' && ctype === 'StructProperty') {
                                        entry.containerId = readGuidStruct(b);
                                    } else {
                                        skipPropertyPayload(b, ctype, csize);
                                    }
                                });
                            } else {
                                skipPropertyPayload(b, stype, ssize);
                            }
                        });
                        return;
                    default:
                        skipPropertyPayload(b, ptype, psize);
                }
            });
        } else {
            skipPropertyPayload(b, type, size);
        }
    });
    b.skip(4); // unknown bytes
    entry.groupId = b.guid();
    // any trailing bytes are preserved implicitly - we never rewrite the blob
}
