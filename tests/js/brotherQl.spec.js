/**
 * [ETIQUETTES 2026-10-16] Octets du protocole raster Brother QL‑800/810W/820NWB.
 *
 * Chaque attente est rattachée à une section du « Raster Command Reference » v1.01 de Brother.
 * Ces tests ne prouvent pas que l'imprimante imprimera (il faut la machine) : ils prouvent que
 * nous envoyons EXACTEMENT les octets que le manuel prescrit, et que nous lisons exactement ce
 * qu'elle répond.
 */
import { describe, it, expect } from 'vitest';
import {
    RASTER_BYTES,
    HEAD_PINS,
    INVALIDATE_BYTES,
    MEDIA,
    createBitmap,
    rotateCcw,
    rotate180,
    readFrame,
    bitmapToRasterRows,
    printInformation,
    buildPrintJob,
    STATUS_REQUEST,
    STATUS_LENGTH,
    looksLikeStatus,
    extractStatusFrames,
    parseStatus,
    mediaMismatchMessage,
    mediaFromStatus,
} from '../../resources/js/helpers/brotherQl.js';

const hex = (u8) => Array.from(u8).map((b) => b.toString(16).padStart(2, '0')).join(' ');

function frame({ err1 = 0, err2 = 0, width = 62, type = 0x4a, length = 0, statusType = 0x00, phase = 0x00, model = 0x41 } = {}) {
    const f = new Uint8Array(STATUS_LENGTH);
    f.set([0x80, 0x20, 0x42, 0x34, model, 0x30, 0x30, 0x00, err1, err2, width, type, 0, 0, 0x3f, 0, 0, length, statusType, phase, 0, 0, 0, 0]);
    return f;
}

describe('dimensions (manuel §2.3)', () => {
    it('une ligne raster = 90 octets = 720 broches', () => {
        expect(RASTER_BYTES).toBe(90);
        expect(HEAD_PINS).toBe(720);
    });

    it('toutes les largeurs de broches additionnent 720', () => {
        Object.values(MEDIA).forEach((m) => {
            expect(m.leftPins + m.printPins + m.rightPins).toBe(HEAD_PINS);
        });
    });

    it('62 mm : 12 / 696 / 12 ; 29 mm : 408 / 306 / 6 ; 38 mm : 295 / 413 / 12', () => {
        expect([MEDIA.c62.leftPins, MEDIA.c62.printPins, MEDIA.c62.rightPins]).toEqual([12, 696, 12]);
        expect([MEDIA.d29x90.leftPins, MEDIA.d29x90.printPins, MEDIA.d29x90.rightPins]).toEqual([408, 306, 6]);
        expect([MEDIA.d38x90.leftPins, MEDIA.d38x90.printPins, MEDIA.d38x90.rightPins]).toEqual([295, 413, 12]);
    });

    it('longueurs imprimables des étiquettes prédécoupées : 271 / 1109 / 991', () => {
        expect(MEDIA.d62x29.lengthDots).toBe(271);
        expect(MEDIA.d62x100.lengthDots).toBe(1109);
        expect(MEDIA.d29x90.lengthDots).toBe(991);
        expect(MEDIA.d38x90.lengthDots).toBe(991);
    });

    it('repère de lecture : 62×29 à plat, 29×90 couchée (longueur × largeur), continu à hauteur libre', () => {
        expect(readFrame(MEDIA.d62x29)).toEqual({ width: 696, height: 271 });
        expect(readFrame(MEDIA.d29x90)).toEqual({ width: 991, height: 306 });
        expect(readFrame(MEDIA.c62)).toEqual({ width: 696, height: null });
    });
});

describe('rotations', () => {
    it('rotateCcw : le coin haut‑gauche passe en bas à gauche (PIL rotate(90))', () => {
        const bm = createBitmap(3, 2); // 3 larges, 2 hauts
        bm.pixels[0] = 1; // (0,0)
        const r = rotateCcw(bm);
        expect([r.width, r.height]).toEqual([2, 3]);
        expect(r.pixels[2 * 2 + 0]).toBe(1); // (x'=0, y'=2) = bas‑gauche
        expect(Array.from(r.pixels).reduce((a, b) => a + b, 0)).toBe(1);
    });

    it('rotate180 est son propre inverse', () => {
        const bm = createBitmap(5, 4);
        bm.pixels[3] = 1;
        bm.pixels[17] = 1;
        expect(Array.from(rotate180(rotate180(bm)).pixels)).toEqual(Array.from(bm.pixels));
    });
});

describe('lignes raster', () => {
    it('62 mm : un point à l’extrême gauche de la lecture tombe sur la broche 707 (miroir, marge 12)', () => {
        const bm = createBitmap(696, 150);
        bm.pixels[0] = 1; // x=0, y=0
        const rows = bitmapToRasterRows(bm, MEDIA.c62);
        expect(rows).toHaveLength(150);
        expect(rows[0]).toHaveLength(90);
        // ligne [12 vides][contenu]… inversée : x=0 → indice 12 → 719‑12 = 707 → octet 88, bit 3.
        expect(rows[0][88]).toBe(0x80 >> 3);
        expect(rows[0].reduce((a, b) => a + b, 0)).toBe(0x10);
        expect(rows[1].every((b) => b === 0)).toBe(true);
    });

    it('62 mm : un point à l’extrême droite tombe sur la broche 12', () => {
        const bm = createBitmap(696, 150);
        bm.pixels[695] = 1;
        const rows = bitmapToRasterRows(bm, MEDIA.c62);
        expect(rows[0][1]).toBe(0x80 >> 4); // broche 12 = octet 1, bit 4
    });

    it('sans miroir : x=0 → broche 12 (octet 1, bit 4)', () => {
        const bm = createBitmap(696, 150);
        bm.pixels[0] = 1;
        const rows = bitmapToRasterRows(bm, MEDIA.c62, { mirror: false });
        expect(rows[0][1]).toBe(0x08);
    });

    it('29×90 : la zone imprimable est décalée par les 408 broches de marge (avant miroir)', () => {
        const bm = createBitmap(991, 306);
        bm.pixels[0] = 1; // haut‑gauche de la lecture
        // lecture couchée → tête : bas‑gauche de l'image tête = (x'=0, y'=990)
        const rows = bitmapToRasterRows(bm, MEDIA.d29x90, { mirror: false });
        expect(rows).toHaveLength(991);
        const lit = rows[990];
        // sans miroir : x'=0 → broche 408 → octet 51, bit 0
        expect(lit[51]).toBe(0x80);
    });

    it('refuse une image dont la largeur n’est pas celle du rouleau', () => {
        expect(() => bitmapToRasterRows(createBitmap(600, 200), MEDIA.c62)).toThrow(/Largeur/);
    });

    it('refuse une hauteur incorrecte sur étiquettes prédécoupées', () => {
        expect(() => bitmapToRasterRows(createBitmap(696, 270), MEDIA.d62x29)).toThrow(/Hauteur/);
    });

    it('refuse un ruban continu trop court (< 150 points) ou trop long', () => {
        expect(() => bitmapToRasterRows(createBitmap(696, 100), MEDIA.c62)).toThrow(/hors limites/);
        expect(() => bitmapToRasterRows(createBitmap(696, 12000), MEDIA.c62)).toThrow(/hors limites/);
    });
});

describe('commande ESC i z (informations d’impression)', () => {
    it('ruban continu 62 mm, 300 lignes, première page', () => {
        // 1B 69 7A | n1=0xC6 (qualité+reprise+type+largeur) | 0A continu | 3E=62 | 00 | 2C 01 00 00 | 00 | 00
        expect(hex(printInformation(MEDIA.c62, 300, 0))).toBe('1b 69 7a c6 0a 3e 00 2c 01 00 00 00 00');
    });

    it('étiquette prédécoupée 62×29 : drapeau « longueur valide » en plus, type 0B, longueur 1D', () => {
        expect(hex(printInformation(MEDIA.d62x29, 271, 0))).toBe('1b 69 7a ce 0b 3e 1d 0f 01 00 00 00 00');
    });

    it('pages suivantes : n9 = 1', () => {
        expect(printInformation(MEDIA.c62, 150, 1)[11]).toBe(1);
    });
});

describe('travail d’impression complet', () => {
    const bm = () => {
        const b = createBitmap(696, 150);
        b.pixels[10] = 1;
        return b;
    };

    it('commence par 400 octets nuls puis ESC @ (manuel §2.1 (1))', () => {
        const { bytes } = buildPrintJob([bm()], MEDIA.c62);
        expect(bytes.slice(0, INVALIDATE_BYTES).every((b) => b === 0)).toBe(true);
        expect(hex(bytes.slice(INVALIDATE_BYTES, INVALIDATE_BYTES + 2))).toBe('1b 40');
    });

    it('enchaîne les codes de commande dans l’ordre du manuel', () => {
        const { bytes } = buildPrintJob([bm()], MEDIA.c62);
        const head = hex(bytes.slice(INVALIDATE_BYTES + 2, INVALIDATE_BYTES + 2 + 4 + 4 + 13 + 4 + 4 + 4 + 5 + 2));
        expect(head).toBe([
            '1b 69 61 01',                               // mode raster
            '1b 69 21 00',                               // notifications d'état
            '1b 69 7a c6 0a 3e 00 96 00 00 00 00 00',    // 150 lignes (0x96)
            '1b 69 4d 40',                               // coupe auto
            '1b 69 41 01',                               // coupe à chaque étiquette
            '1b 69 4b 08',                               // couper en fin de travail
            '1b 69 64 23 00',                            // marge 35 points
            '4d 00',                                     // sans compression
        ].join(' '));
    });

    it('chaque ligne est « g 00 5A » + 90 octets, et le travail se termine par 1A', () => {
        const { bytes, pages, rows } = buildPrintJob([bm()], MEDIA.c62);
        expect(pages).toBe(1);
        expect(rows).toBe(150);
        // contrôle : 400 + 2 + codes + 150 × 93 + 1
        const codesLen = 4 + 4 + 13 + 4 + 4 + 4 + 5 + 2;
        expect(bytes.length).toBe(INVALIDATE_BYTES + 2 + codesLen + 150 * 93 + 1);
        expect(bytes[bytes.length - 1]).toBe(0x1a);
        const firstRow = INVALIDATE_BYTES + 2 + codesLen;
        expect(hex(bytes.slice(firstRow, firstRow + 3))).toBe('67 00 5a');
    });

    it('plusieurs étiquettes : 0C entre les pages, 1A à la fin, n9 = 1 dès la 2ᵉ page', () => {
        const { bytes, pages } = buildPrintJob([bm(), bm(), bm()], MEDIA.c62);
        expect(pages).toBe(3);
        const count = (value) => bytes.reduce((n, b) => n + (b === value ? 1 : 0), 0);
        // 0C peut apparaître dans les données ; on contrôle donc la position exacte des fins de page.
        const codesLen = 4 + 4 + 13 + 4 + 4 + 4 + 5 + 2;
        const pageLen = codesLen + 150 * 93 + 1;
        const base = INVALIDATE_BYTES + 2;
        expect(bytes[base + pageLen - 1]).toBe(0x0c);
        expect(bytes[base + 2 * pageLen - 1]).toBe(0x0c);
        expect(bytes[base + 3 * pageLen - 1]).toBe(0x1a);
        expect(bytes[base + pageLen + 4 + 4 + 11]).toBe(1);           // n9 de la page 2
        expect(bytes[base + 4 + 4 + 11]).toBe(0);                     // n9 de la page 1
        expect(count(0x1a)).toBeGreaterThanOrEqual(1);
    });

    it('sans coupe automatique : ESC i M 00 et ESC i K 00', () => {
        const { bytes } = buildPrintJob([bm()], MEDIA.c62, { autoCut: false });
        const s = hex(bytes.slice(INVALIDATE_BYTES + 2, INVALIDATE_BYTES + 2 + 60));
        expect(s).toContain('1b 69 4d 00');
        expect(s).toContain('1b 69 4b 00');
    });

    it('étiquettes prédécoupées : marge d’avance forcée à 0 (manuel §2.3.3)', () => {
        const b = createBitmap(696, 271);
        const { bytes } = buildPrintJob([b], MEDIA.d62x29);
        expect(hex(bytes.slice(0, 600))).toContain('1b 69 64 00 00');
    });

    it('refuse un travail vide', () => {
        expect(() => buildPrintJob([], MEDIA.c62)).toThrow(/Aucune étiquette/);
    });

    it('la demande de statut est ESC i S', () => {
        expect(hex(STATUS_REQUEST)).toBe('1b 69 53');
    });
});

describe('statut de l’imprimante (32 octets, manuel §4)', () => {
    it('reconnaît l’en‑tête 80 20 42 34 et le modèle QL‑820NWB (code « A »)', () => {
        const s = parseStatus(frame());
        expect(s.valid).toBe(true);
        expect(s.model).toBe('QL-820NWB');
        expect(s.hasError).toBe(false);
        expect(s.media).toEqual({ widthMm: 62, lengthMm: 0, type: 'continuous' });
        expect(s.statusType).toBe('reply');
    });

    it('décode les erreurs : pas de rouleau, capot ouvert, rouleau différent', () => {
        const s = parseStatus(frame({ err1: 0x01, err2: 0x11 }));
        expect(s.errors.map((e) => e.code)).toEqual(['no_media', 'replace_media', 'cover_open']);
        expect(s.hasError).toBe(true);
        s.errors.forEach((e) => expect(e.message).toMatch(/[a-zé]/i));
    });

    it('distingue « impression terminée » (01), « erreur » (02) et « changement de phase » (06)', () => {
        expect(parseStatus(frame({ statusType: 0x01 })).statusType).toBe('completed');
        expect(parseStatus(frame({ statusType: 0x02 })).statusType).toBe('error');
        const phase = parseStatus(frame({ statusType: 0x06, phase: 0x01 }));
        expect(phase.statusType).toBe('phase');
        expect(phase.phase).toBe('printing');
    });

    it('une trame sans en‑tête ou trop courte est invalide, jamais une exception', () => {
        expect(parseStatus(new Uint8Array(32)).valid).toBe(false);
        expect(parseStatus(new Uint8Array(5)).valid).toBe(false);
        expect(parseStatus(null).valid).toBe(false);
    });

    it('extractStatusFrames se resynchronise après des octets parasites et garde une trame partielle', () => {
        const a = frame({ statusType: 0x06, phase: 0x01 });
        const b = frame({ statusType: 0x01 });
        const noise = Uint8Array.of(0x00, 0x13, 0x80, 0x99);
        const half = b.slice(0, 10);
        const buf = new Uint8Array(noise.length + a.length + b.length + half.length);
        buf.set(noise, 0);
        buf.set(a, noise.length);
        buf.set(b, noise.length + a.length);
        buf.set(half, noise.length + a.length + b.length);
        const { frames, rest } = extractStatusFrames(buf);
        expect(frames).toHaveLength(2);
        expect(looksLikeStatus(frames[0])).toBe(true);
        expect(parseStatus(frames[1]).statusType).toBe('completed');
        expect(Array.from(rest)).toEqual(Array.from(half));
    });

    it('rouleau chargé ≠ format réglé → message clair ; identique → rien', () => {
        const loaded = parseStatus(frame({ width: 29, type: 0x4b, length: 90 }));
        expect(mediaMismatchMessage(loaded, MEDIA.c62)).toMatch(/29\s×\s90\smm.*62\smm\scontinu/);
        expect(mediaMismatchMessage(loaded, MEDIA.d29x90)).toBeNull();
        expect(mediaMismatchMessage(parseStatus(frame()), MEDIA.c62)).toBeNull();
        // « aucun rouleau » est signalé par errors, pas par ce message
        expect(mediaMismatchMessage(parseStatus(frame({ width: 0, type: 0x00 })), MEDIA.c62)).toBeNull();
    });
});

describe('rouleau détecté', () => {
    it('reconnaît les formats gérés d’après l’état de l’imprimante', () => {
        expect(mediaFromStatus(parseStatus(frame())).id).toBe('c62');
        expect(mediaFromStatus(parseStatus(frame({ width: 29, type: 0x4b, length: 90 }))).id).toBe('d29x90');
        expect(mediaFromStatus(parseStatus(frame({ width: 62, type: 0x4b, length: 29 }))).id).toBe('d62x29');
    });

    it('format inconnu, aucun rouleau ou état invalide : null', () => {
        expect(mediaFromStatus(parseStatus(frame({ width: 12, type: 0x4a })))).toBeNull();
        expect(mediaFromStatus(parseStatus(frame({ width: 0, type: 0x00 })))).toBeNull();
        expect(mediaFromStatus(null)).toBeNull();
    });
});
