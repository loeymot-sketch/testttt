/**
 * [ETIQUETTES 2026-10-16] Décodeur INDÉPENDANT d'un travail d'impression Brother QL (raster), écrit à partir
 * du « Raster Command Reference » v1.01 et non à partir de brotherQl.js : il sert à vérifier, octet par octet,
 * ce que l'application a réellement envoyé à l'imprimante simulée.
 *
 * Chargé à la fois par Node (require) et par la page (addInitScript), où la fausse imprimante s'en sert pour
 * savoir quand un travail est complet.
 */
(function (root) {
    'use strict';

    /** @returns {{complete:boolean, error:string|null, pages:Array<{info:object, rows:Uint8Array[], terminator:number}>}} */
    function decodeJob(bytes) {
        const out = { complete: false, error: null, pages: [] };
        const n = bytes.length;
        let i = 0;
        let zeros = 0;
        while (i < n && bytes[i] === 0x00) { i++; zeros++; }
        if (i >= n) return out; // rien encore
        if (zeros < 200) { out.error = `invalidate trop court (${zeros} octets nuls)`; return out; }
        if (i + 2 > n) return out;
        if (bytes[i] !== 0x1b || bytes[i + 1] !== 0x40) { out.error = 'ESC @ attendu après l’invalidate'; return out; }
        i += 2;

        for (;;) {
            const page = { info: null, rows: [], terminator: null, control: [] };
            // codes de commande jusqu'au premier « g »
            for (;;) {
                if (i >= n) return out;
                const b = bytes[i];
                if (b === 0x67) break;
                if (b === 0x4d) { // M n
                    if (i + 2 > n) return out;
                    page.control.push({ cmd: 'M', n: bytes[i + 1] });
                    i += 2;
                    continue;
                }
                if (b === 0x1b) {
                    if (i + 3 > n) return out;
                    if (bytes[i + 1] !== 0x69) { out.error = `ESC inconnu à ${i}`; return out; }
                    const c = bytes[i + 2];
                    if (c === 0x7a) { // ESC i z + 10 octets
                        if (i + 13 > n) return out;
                        const d = bytes.slice(i + 3, i + 13);
                        page.info = {
                            flags: d[0], mediaType: d[1], widthMm: d[2], lengthMm: d[3],
                            rasterCount: d[4] + d[5] * 256 + d[6] * 65536 + d[7] * 16777216, pageIndex: d[8], fixed: d[9],
                        };
                        i += 13;
                    } else if (c === 0x64) { // ESC i d lo hi
                        if (i + 5 > n) return out;
                        page.control.push({ cmd: 'd', n: bytes[i + 3] + bytes[i + 4] * 256 });
                        i += 5;
                    } else if ([0x61, 0x21, 0x4d, 0x41, 0x4b].includes(c)) { // ESC i a/!/M/A/K n
                        if (i + 4 > n) return out;
                        page.control.push({ cmd: String.fromCharCode(c), n: bytes[i + 3] });
                        i += 4;
                    } else {
                        out.error = `commande ESC i 0x${c.toString(16)} inconnue à ${i}`;
                        return out;
                    }
                    continue;
                }
                out.error = `octet inattendu 0x${b.toString(16)} à ${i}`;
                return out;
            }
            // lignes raster
            while (i < n && bytes[i] === 0x67) {
                if (i + 3 > n) return out;
                if (bytes[i + 1] !== 0x00 || bytes[i + 2] !== 90) { out.error = `en‑tête de ligne invalide à ${i}`; return out; }
                if (i + 93 > n) return out;
                page.rows.push(bytes.slice(i + 3, i + 93));
                i += 93;
            }
            if (i >= n) return out;
            const t = bytes[i];
            if (t !== 0x0c && t !== 0x1a) { out.error = `terminateur de page inattendu 0x${t.toString(16)} à ${i}`; return out; }
            page.terminator = t;
            i += 1;
            if (!page.info) { out.error = 'page sans ESC i z'; return out; }
            if (page.info.rasterCount !== page.rows.length) {
                out.error = `ESC i z annonce ${page.info.rasterCount} lignes, ${page.rows.length} reçues`;
                return out;
            }
            out.pages.push(page);
            if (t === 0x1a) {
                if (i === n) out.complete = true;
                else out.error = `${n - i} octets après la dernière page`;
                return out;
            }
        }
    }

    /**
     * Reconstruit l'image de LECTURE d'une page (62 mm : 12 broches de marge à gauche, 696 imprimables).
     * Défait le miroir : la broche p correspond au point (719 − p) de la ligne [marge][contenu][marge].
     * @returns {{width:number, height:number, mask:Uint8Array}}
     */
    function pageToBitmap(page, leftPins, printPins, mirror) {
        const height = page.rows.length;
        const mask = new Uint8Array(printPins * height);
        for (let y = 0; y < height; y++) {
            const row = page.rows[y];
            for (let p = 0; p < 720; p++) {
                if (!(row[p >> 3] & (0x80 >> (p & 7)))) continue;
                const lineIndex = mirror === false ? p : 719 - p;
                const x = lineIndex - leftPins;
                if (x >= 0 && x < printPins) mask[y * printPins + x] = 1;
            }
        }
        return { width: printPins, height, mask };
    }

    const api = { decodeJob, pageToBitmap };
    root.BrotherDecode = api;
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
