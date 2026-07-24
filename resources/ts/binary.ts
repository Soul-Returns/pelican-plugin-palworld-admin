/**
 * Low-level reader for UE/Palworld binary data (FArchive conventions).
 *
 * All offsets are absolute positions into a single backing Uint8Array; the
 * reader is a thin cursor so callers can freely record/restore positions when
 * mapping out byte ranges for the surgical filter.
 */

const ASCII = new TextDecoder('latin1');
const UTF16LE = new TextDecoder('utf-16le');

export const ZERO_UID = '00000000-0000-0000-0000-000000000000';

/** Palworld's byte-swizzled GUID-to-string, identical to palworld-save-tools. */
export function guidToString(b: Uint8Array, off: number): string {
    const hex = (n: number, width: number) => n.toString(16).padStart(width, '0');
    return (
        hex(((b[off + 3] << 24) | (b[off + 2] << 16) | (b[off + 1] << 8) | b[off]) >>> 0, 8) +
        '-' +
        hex((b[off + 7] << 8) | b[off + 6], 4) +
        '-' +
        hex((b[off + 5] << 8) | b[off + 4], 4) +
        '-' +
        hex((b[off + 0xb] << 8) | b[off + 0xa], 4) +
        '-' +
        hex((b[off + 9] << 8) | b[off + 8], 4) +
        hex((((b[off + 0xf] << 24) | (b[off + 0xe] << 16) | (b[off + 0xd] << 8) | b[off + 0xc]) >>> 0), 8)
    );
}

export class ByteReader {
    readonly bytes: Uint8Array;
    readonly view: DataView;
    off: number;

    constructor(bytes: Uint8Array, off = 0) {
        this.bytes = bytes;
        this.view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        this.off = off;
    }

    eof(): boolean {
        return this.off >= this.bytes.length;
    }

    u8(): number {
        return this.bytes[this.off++];
    }

    i32(): number {
        const v = this.view.getInt32(this.off, true);
        this.off += 4;
        return v;
    }

    u32(): number {
        const v = this.view.getUint32(this.off, true);
        this.off += 4;
        return v;
    }

    /** u64 as a JS number - GVAS sizes/counts stay far below 2^53. */
    u64(): number {
        const v = this.view.getBigUint64(this.off, true);
        this.off += 8;
        return Number(v);
    }

    skip(n: number): void {
        this.off += n;
    }

    /** FString: i32 length incl. NUL; negative length means UTF-16LE. */
    fstring(): string {
        const n = this.i32();
        if (n === 0) return '';
        if (n < 0) {
            const raw = this.bytes.subarray(this.off, this.off + -n * 2 - 2);
            this.off += -n * 2;
            return UTF16LE.decode(raw);
        }
        const raw = this.bytes.subarray(this.off, this.off + n - 1);
        this.off += n;
        return ASCII.decode(raw);
    }

    guid(): string {
        const s = guidToString(this.bytes, this.off);
        this.off += 16;
        return s;
    }

    /** Optional-guid: bool flag byte, guid only present when the flag is set. */
    skipOptionalGuid(): void {
        if (this.u8()) this.off += 16;
    }
}
