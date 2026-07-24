/**
 * Palworld .sav container: [uncompressed_len u32][compressed_len u32][magic 3][type 1][data].
 *
 * Reads legacy zlib ("PlZ", type 0x31 single / 0x32 double) and Oodle
 * ("PlM", 2026 Summer Update) saves; always writes PlZ back - the game and
 * all known tools accept zlib-recompressed saves (palworld-save-tools#214).
 * Mirrors the patched palworld-save-tools palsav.py byte-for-byte.
 */

import createOoz from './ooz.mjs';

type OozModule = {
    _malloc(n: number): number;
    _free(p: number): void;
    _Ooz_Decompress(
        src: number, srcLen: number, dst: number, dstLen: number,
        a: number, b: number, c: number, d: number, e: number,
        f: number, g: number, h: number, i: number, j: number,
    ): number;
    HEAPU8: Uint8Array;
};

let oozPromise: Promise<OozModule> | null = null;

function ooz(): Promise<OozModule> {
    // the emscripten glue is untyped JS - the cast is the module's contract
    oozPromise ??= createOoz() as Promise<OozModule>;
    return oozPromise;
}

async function inflate(data: Uint8Array): Promise<Uint8Array> {
    const stream = new Blob([data as BlobPart]).stream().pipeThrough(new DecompressionStream('deflate'));
    return new Uint8Array(await new Response(stream).arrayBuffer());
}

async function deflate(data: Uint8Array): Promise<Uint8Array> {
    const stream = new Blob([data as BlobPart]).stream().pipeThrough(new CompressionStream('deflate'));
    return new Uint8Array(await new Response(stream).arrayBuffer());
}

export interface DecompressedSav {
    gvas: Uint8Array;
    saveType: number;
}

export async function decompressSav(data: Uint8Array): Promise<DecompressedSav> {
    const view = new DataView(data.buffer, data.byteOffset, data.byteLength);
    let uncompressedLen = view.getUint32(0, true);
    let compressedLen = view.getUint32(4, true);
    let magic = ASCII3(data, 8);
    let saveType = data[11];
    let start = 12;
    if (magic === 'CNK') {
        uncompressedLen = view.getUint32(12, true);
        compressedLen = view.getUint32(16, true);
        magic = ASCII3(data, 20);
        saveType = data[23];
        start = 24;
    }

    if (magic === 'PlM') {
        const compressed = data.subarray(start);
        if (compressedLen !== compressed.length) {
            throw new Error(`incorrect compressed length: ${compressedLen}`);
        }
        const mod = await ooz();
        const srcPtr = mod._malloc(compressed.length);
        const dstPtr = mod._malloc(uncompressedLen + 64);
        try {
            mod.HEAPU8.set(compressed, srcPtr);
            const result = mod._Ooz_Decompress(
                srcPtr, compressed.length, dstPtr, uncompressedLen,
                0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            );
            if (result !== uncompressedLen) {
                throw new Error(`Ooz_Decompress returned ${result}, expected ${uncompressedLen}`);
            }
            // copy out - HEAPU8 is invalidated when the wasm heap grows
            return { gvas: mod.HEAPU8.slice(dstPtr, dstPtr + uncompressedLen), saveType };
        } finally {
            mod._free(srcPtr);
            mod._free(dstPtr);
        }
    }

    if (magic !== 'PlZ') {
        throw new Error(`not a compressed Palworld save (magic ${JSON.stringify(magic)})`);
    }
    if (saveType !== 0x31 && saveType !== 0x32) {
        throw new Error(`unhandled save type: 0x${saveType.toString(16)}`);
    }
    if (saveType === 0x31 && compressedLen !== data.length - start) {
        throw new Error(`incorrect compressed length: ${compressedLen}`);
    }
    let gvas = await inflate(data.subarray(start));
    if (saveType === 0x32) {
        if (compressedLen !== gvas.length) {
            throw new Error(`incorrect compressed length: ${compressedLen}`);
        }
        gvas = await inflate(gvas);
    }
    if (uncompressedLen !== gvas.length) {
        throw new Error(`incorrect uncompressed length: ${uncompressedLen}`);
    }
    return { gvas, saveType };
}

/** Always writes zlib (PlZ); saveType 0x32 double-compresses like the game did. */
export async function compressSav(gvas: Uint8Array, saveType: number): Promise<Uint8Array> {
    const type = saveType === 0x32 ? 0x32 : 0x31;
    let compressed = await deflate(gvas);
    const compressedLen = compressed.length;
    if (type === 0x32) {
        compressed = await deflate(compressed);
    }
    const out = new Uint8Array(12 + compressed.length);
    const view = new DataView(out.buffer);
    view.setUint32(0, gvas.length, true);
    view.setUint32(4, compressedLen, true);
    out[8] = 0x50; // P
    out[9] = 0x6c; // l
    out[10] = 0x5a; // Z
    out[11] = type;
    out.set(compressed, 12);
    return out;
}

function ASCII3(b: Uint8Array, off: number): string {
    return String.fromCharCode(b[off], b[off + 1], b[off + 2]);
}
