// @vitest-environment node
/**
 * [ETIQUETTES 2026-10-16] Aller‑retour : l'encodeur (brotherQl.js) et un décodeur écrit à part, d'après le
 * manuel (tests/Playwright/helpers/brotherDecode.js), doivent se retrouver sur des images aléatoires, pour
 * TOUS les rouleaux — rotation 90° comprise. Si l'un des deux dérive, ce test le dit avant l'imprimante.
 */
import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';
import { MEDIA, createBitmap, buildPrintJob, readFrame, rotateCcw } from '../../resources/js/helpers/brotherQl.js';

const require = createRequire(import.meta.url);
const { decodeJob, pageToBitmap } = require('../Playwright/helpers/brotherDecode.js');

/** PRNG déterministe (mulberry32). */
function rng(seed) {
    let a = seed >>> 0;
    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function randomBitmap(width, height, seed, density = 0.3) {
    const r = rng(seed);
    const b = createBitmap(width, height);
    for (let i = 0; i < b.pixels.length; i++) b.pixels[i] = r() < density ? 1 : 0;
    return b;
}

/** Rotation horaire de 90° = inverse de rotateCcw. */
function rotateCw(bm) {
    let out = bm;
    for (let i = 0; i < 3; i++) out = rotateCcw(out);
    return out;
}

describe('aller‑retour encodeur ↔ décodeur indépendant', () => {
    Object.values(MEDIA).forEach((media) => {
        it(`${media.label} : l'image décodée est identique à l'image envoyée`, () => {
            const frame = readFrame(media);
            const height = frame.height || 220;
            const a = randomBitmap(frame.width, height, 11);
            const b = randomBitmap(frame.width, height, 29, 0.55);
            const job = buildPrintJob([a, b], media);

            const decoded = decodeJob(job.bytes);
            expect(decoded.error).toBeNull();
            expect(decoded.complete).toBe(true);
            expect(decoded.pages).toHaveLength(2);

            [a, b].forEach((source, idx) => {
                const page = decoded.pages[idx];
                expect(page.info.mediaType).toBe(media.type === 'continuous' ? 0x0a : 0x0b);
                expect(page.info.widthMm).toBe(media.widthMm);
                expect(page.info.lengthMm).toBe(media.lengthMm);
                expect(page.info.pageIndex).toBe(idx === 0 ? 0 : 1);
                expect(page.terminator).toBe(idx === 1 ? 0x1a : 0x0c);

                let head = pageToBitmap(page, media.leftPins, media.printPins, true);
                let back = { width: head.width, height: head.height, pixels: head.mask };
                if (media.readRotate) back = rotateCw(back);
                expect(back.width).toBe(source.width);
                expect(back.height).toBe(source.height);
                expect(Array.from(back.pixels)).toEqual(Array.from(source.pixels));
            });
        });
    });

    it('les broches de marge ne portent jamais de point (62 mm : 12 à gauche, 12 à droite)', () => {
        const full = createBitmap(696, 160);
        full.pixels.fill(1);
        const { pages } = decodeJob(buildPrintJob([full], MEDIA.c62).bytes);
        pages[0].rows.forEach((row) => {
            for (let p = 0; p < 720; p++) {
                const on = Boolean(row[p >> 3] & (0x80 >> (p & 7)));
                if (p < 12 || p >= 708) expect(on).toBe(false);
                else expect(on).toBe(true);
            }
        });
    });

    it('un octet manquant au bout du travail est détecté comme incomplet, pas comme valide', () => {
        const job = buildPrintJob([randomBitmap(696, 150, 3)], MEDIA.c62).bytes;
        const cut = job.slice(0, job.length - 1);
        const d = decodeJob(cut);
        expect(d.complete).toBe(false);
    });

    it('le décodeur rejette une ligne raster de mauvaise longueur', () => {
        const job = buildPrintJob([randomBitmap(696, 150, 5)], MEDIA.c62).bytes.slice();
        const at = job.indexOf(0x67, 400);
        job[at + 2] = 89;
        expect(decodeJob(job).error).toMatch(/en‑tête de ligne invalide/);
    });
});
