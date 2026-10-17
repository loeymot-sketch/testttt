// @vitest-environment node
/**
 * [ETIQUETTES 2026-10-17] « Une coupe par commande » : les tickets d'une commande restent attachés, la lame coupe une seule fois
 * après le dernier. Le manuel Brother (QL-800/810W/820NWB, Raster Command Reference v1.01) n'a que `ESC i A n` « couper toutes les
 * n étiquettes » (1–255) et `ESC i K` « couper en fin de travail » : AUCUNE demi-coupe, AUCUNE perforation — un « petit point
 * qui relie » ne peut pas être commandé. Ce test fige ce qui l'est : les octets, vérifiés par le décodeur écrit à part.
 */
import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';
import {
    MEDIA, createBitmap, buildPrintJob, clampCutEvery, appendCutLine, CUT_LINE_HEIGHT,
} from '../../resources/js/helpers/brotherQl.js';
import {
    chainPlan, bitmapsFor, printBlocks, rememberJob, readLastJob, describeLastJob, LAST_JOB_KEY,
} from '../../resources/js/helpers/labelPrintJob.js';
import { DEFAULT_SETTINGS, loadSettings } from '../../resources/js/helpers/labelPrinter.js';

const require = createRequire(import.meta.url);
const { decodeJob } = require('../Playwright/helpers/brotherDecode.js');

const ticket = (media, h = 200) => createBitmap(media.printPins, media.type === 'continuous' ? h : media.lengthDots);
const control = (page, cmd) => page.control.find((c) => c.cmd === cmd);

describe('ESC i A : couper toutes les n étiquettes', () => {
    it('plafonne et assainit la valeur (1–255, sinon 1)', () => {
        expect([clampCutEvery(undefined), clampCutEvery(NaN), clampCutEvery(0), clampCutEvery(-4), clampCutEvery('x')]).toEqual([1, 1, 1, 1, 1]);
        expect([clampCutEvery(1), clampCutEvery(5), clampCutEvery(255), clampCutEvery(300), clampCutEvery(2.9)]).toEqual([1, 5, 255, 255, 2]);
    });

    it('par défaut : une coupe par étiquette (comportement inchangé)', () => {
        const m = MEDIA.c62;
        const d = decodeJob(buildPrintJob([ticket(m), ticket(m), ticket(m)], m).bytes);
        expect(d.error).toBeFalsy();
        d.pages.forEach((p) => {
            expect(control(p, 'A').n).toBe(1);
            expect(control(p, 'M').n).toBe(0x40);
            expect(control(p, 'K').n).toBe(0x08);
        });
    });

    it('cutEvery = nombre de tickets : une seule coupe, à la fin du travail ; la dernière page est la seule à avancer et couper', () => {
        const m = MEDIA.c62;
        const d = decodeJob(buildPrintJob([ticket(m), ticket(m), ticket(m), ticket(m), ticket(m)], m, { cutEvery: 5 }).bytes);
        expect(d.error).toBeFalsy();
        expect(d.pages).toHaveLength(5);
        d.pages.forEach((p) => expect(control(p, 'A').n).toBe(5));
        expect(d.pages.map((p) => p.terminator)).toEqual([0x0c, 0x0c, 0x0c, 0x0c, 0x1a]);
        expect(control(d.pages[4], 'K').n & 0x08).toBe(0x08); // couper en fin de travail
        expect(d.complete).toBe(true);
    });

    it('coupe automatique éteinte : jamais de coupe, quel que soit cutEvery', () => {
        const m = MEDIA.c62;
        const d = decodeJob(buildPrintJob([ticket(m), ticket(m)], m, { autoCut: false, cutEvery: 2 }).bytes);
        d.pages.forEach((p) => {
            expect(control(p, 'M').n).toBe(0x00);
            expect(control(p, 'K').n).toBe(0x00);
        });
    });
});

describe('chainPlan : quand les tickets restent attachés', () => {
    const on = { autoCut: true, cutMode: 'order' };
    it('seulement pour un groupe, en mode « order », avec coupe auto et au moins deux tickets', () => {
        expect(chainPlan(5, on, true)).toEqual({ chained: true, cutEvery: 5 });
        expect(chainPlan(5, on, false).chained).toBe(false);
        expect(chainPlan(5, { ...on, cutMode: 'each' }, true).chained).toBe(false);
        expect(chainPlan(5, { ...on, autoCut: false }, true).chained).toBe(false);
        expect(chainPlan(1, on, true)).toEqual({ chained: false, cutEvery: 1 });
        expect(chainPlan(5, { autoCut: true }, true).chained).toBe(false); // réglage absent
        expect(chainPlan(5, { autoCut: true, cutMode: 'n’importe quoi' }, true).chained).toBe(false);
    });
    it('plafonne à 255', () => {
        expect(chainPlan(400, on, true).cutEvery).toBe(255);
    });
    it('le défaut des réglages est « chaque ticket » et un ancien réglage enregistré le garde', () => {
        expect(DEFAULT_SETTINGS.cutMode).toBe('each');
        const storage = { getItem: () => JSON.stringify({ autoCut: true, mediaId: 'c62' }) };
        expect(loadSettings(storage).cutMode).toBe('each');
    });
});

describe('ligne pointillée entre deux tickets (ruban continu)', () => {
    it('ajoute CUT_LINE_HEIGHT points sous le ticket, sans toucher au contenu ni au bitmap d’origine', () => {
        const b = createBitmap(696, 100);
        b.pixels[5] = 1;
        const out = appendCutLine(b);
        expect(out.height).toBe(100 + CUT_LINE_HEIGHT);
        expect(out.width).toBe(696);
        expect(out.pixels[5]).toBe(1);
        expect(b.height).toBe(100);
        const dashed = [];
        for (let y = 100; y < out.height; y++) {
            let on = 0;
            for (let x = 0; x < 696; x++) on += out.pixels[y * 696 + x];
            if (on) dashed.push(on);
        }
        expect(dashed.length).toBe(3); // 3 points d'épaisseur
        expect(dashed[0]).toBeGreaterThan(200);
        expect(dashed[0]).toBeLessThan(500); // pointillé, pas un trait plein (696)
    });

    it('chaque ticket sauf le dernier la porte ; prédécoupé ou non groupé : rien n’est ajouté', () => {
        const c = MEDIA.c62;
        const rendered = [ticket(c), ticket(c), ticket(c)].map((bitmap) => ({ bitmap }));
        const plan = { chained: true, cutEvery: 3 };
        expect(bitmapsFor(rendered, c, plan).map((b) => b.height)).toEqual([200 + CUT_LINE_HEIGHT, 200 + CUT_LINE_HEIGHT, 200]);
        expect(bitmapsFor(rendered, c, { chained: false, cutEvery: 1 }).map((b) => b.height)).toEqual([200, 200, 200]);
        const die = MEDIA.d62x29;
        const rd = [ticket(die), ticket(die)].map((bitmap) => ({ bitmap }));
        expect(bitmapsFor(rd, die, plan).map((b) => b.height)).toEqual([die.lengthDots, die.lengthDots]);
    });
});

describe('printBlocks de bout en bout (imprimante factice, canvas factice)', () => {
    // un canvas qui ne dessine rien : le texte n'est pas l'objet, ce sont les octets envoyés
    const env = {
        createCanvas: (w, h) => ({
            width: w, height: h,
            getContext: () => ({
                measureText: () => ({ width: 10 }), fillRect() {}, strokeRect() {}, fillText() {},
                getImageData: () => ({ data: new Uint8ClampedArray(w * h * 4).fill(255) }),
            }),
        }),
    };
    const blocks = [
        { t: 'band', left: 'N° A12', right: '1/5' },
        { t: 'title', text: 'CHEESE BURGER' },
        { t: 'line', text: 'Sans oignons', size: 'md' },
        { t: 'line', text: 'Sauce algérienne', size: 'md' },
    ];
    const spy = () => { const sent = []; return { sent, print: async (job) => { sent.push(job); return { ok: true, pages: job.pages }; } }; };

    it('commande de 5 tickets, mode « order » : un seul travail, 5 pages, coupe toutes les 5, 4 lignes pointillées', async () => {
        const p = spy();
        const settings = { ...DEFAULT_SETTINGS, mediaId: 'c62', cutMode: 'order' };
        const r = await printBlocks(p, Array.from({ length: 5 }, () => blocks), settings, env, { group: true });
        expect(r.ok).toBe(true);
        expect(p.sent).toHaveLength(1);
        const d = decodeJob(p.sent[0].bytes);
        expect(d.error).toBeFalsy();
        expect(d.pages).toHaveLength(5);
        d.pages.forEach((pg) => expect(control(pg, 'A').n).toBe(5));
        const heights = d.pages.map((pg) => pg.rows.length);
        expect(heights.slice(0, 4).every((h) => h === heights[4] + CUT_LINE_HEIGHT)).toBe(true);
    });

    it('même commande en mode « each » : une coupe par ticket, aucune ligne ajoutée', async () => {
        const p = spy();
        const settings = { ...DEFAULT_SETTINGS, mediaId: 'c62', cutMode: 'each' };
        await printBlocks(p, Array.from({ length: 5 }, () => blocks), settings, env, { group: true });
        const d = decodeJob(p.sent[0].bytes);
        d.pages.forEach((pg) => expect(control(pg, 'A').n).toBe(1));
        expect(new Set(d.pages.map((pg) => pg.rows.length)).size).toBe(1);
    });

    it('copies d’une étiquette DLC (pas un groupe) : jamais attachées, même en mode « order »', async () => {
        const p = spy();
        const settings = { ...DEFAULT_SETTINGS, mediaId: 'c62', cutMode: 'order' };
        await printBlocks(p, Array.from({ length: 3 }, () => blocks), settings, env);
        const d = decodeJob(p.sent[0].bytes);
        d.pages.forEach((pg) => expect(control(pg, 'A').n).toBe(1));
    });
});

describe('dernier travail envoyé : ce que la tablette dit avoir envoyé', () => {
    const mem = () => { const m = {}; return { getItem: (k) => (k in m ? m[k] : null), setItem: (k, v) => { m[k] = v; }, m }; };

    it('se relit tel quel, et un contenu illisible ne casse rien', () => {
        const st = mem();
        expect(readLastJob(st)).toBeNull();
        rememberJob({ at: 1_700_000_000_000, pages: 4, cutEvery: 4, chained: true, ok: true }, st);
        expect(readLastJob(st)).toMatchObject({ pages: 4, cutEvery: 4, ok: true });
        st.setItem(LAST_JOB_KEY, '{pas du json');
        expect(readLastJob(st)).toBeNull();
        st.setItem(LAST_JOB_KEY, JSON.stringify({ pages: 3 })); // sans horodatage
        expect(readLastJob(st)).toBeNull();
        expect(() => rememberJob({ at: 1 }, { setItem: () => { throw new Error('quota'); } })).not.toThrow();
    });

    it('dit en français ce qui a été envoyé', () => {
        expect(describeLastJob(null)).toBe('');
        const at = new Date(2026, 9, 18, 14, 32).getTime();
        expect(describeLastJob({ at, pages: 4, cutEvery: 4, ok: true })).toBe('14:32 — 4 étiquettes, UNE coupe à la fin (tickets attachés) — envoyé');
        expect(describeLastJob({ at, pages: 4, cutEvery: 1, ok: true })).toBe('14:32 — 4 étiquettes, une coupe après chaque étiquette — envoyé');
        expect(describeLastJob({ at, pages: 6, cutEvery: 3, ok: false })).toBe('14:32 — 6 étiquettes, une coupe toutes les 3 étiquettes — échec');
        expect(describeLastJob({ at, pages: 1, cutEvery: 1, ok: true })).toBe('14:32 — 1 étiquette, une seule étiquette — envoyé');
    });
});
