/**
 * Pembuat `idempotency_key` untuk checkout POS (QA-002): UUID versi 4.
 *
 * Modul murni (tanpa DOM/state) supaya gampang diuji dan tidak menambah
 * beban ke kasir-pos.js. Server memvalidasi dengan rule `uuid` Laravel,
 * jadi hasilnya harus UUID valid: nibble ke-13 = 4 (versi) dan nibble
 * ke-17 = 8/9/a/b (varian RFC 4122).
 *
 * Urutan pilihan sumber acak:
 *  1. crypto.randomUUID() — hanya ada di konteks aman (HTTPS/localhost)
 *     pada browser modern.
 *  2. crypto.getRandomValues() — tersedia juga di konteks non-HTTPS dan
 *     browser lebih lama (mis. WebView Android tua di tablet kasir).
 *  3. Math.random() — bukan kriptografis, tapi key ini bukan rahasia
 *     keamanan, hanya pembeda antar-percobaan checkout; yang penting tidak
 *     bentrok. Pencegahan replay lintas-kasir dijaga server (key dibatasi
 *     per user), bukan oleh keacakan key ini.
 *
 * @returns {string} contoh: "3f2b8c1e-5d47-4a9b-8e21-7c6d0f4a9b12"
 */
export function generateIdempotencyKey() {
    const c = typeof crypto !== 'undefined' ? crypto : null;

    if (c && typeof c.randomUUID === 'function') {
        return c.randomUUID();
    }

    const bytes = new Uint8Array(16);
    if (c && typeof c.getRandomValues === 'function') {
        c.getRandomValues(bytes);
    } else {
        for (let i = 0; i < bytes.length; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40; // versi 4
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // varian RFC 4122

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));

    return [
        hex.slice(0, 4).join(''),
        hex.slice(4, 6).join(''),
        hex.slice(6, 8).join(''),
        hex.slice(8, 10).join(''),
        hex.slice(10, 16).join(''),
    ].join('-');
}