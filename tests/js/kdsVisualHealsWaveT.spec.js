import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * [Wave T R1 F3 2026-05-20] KDS visual heals sentinel.
 *
 * Locks 3 P1 heals from wave-B-findings.json so a future refactor cannot
 * silently re-introduce the regression:
 *
 *   WT-B-R1-001 — Undo toast must NOT use position:absolute top:110px center
 *                 min-width:620px (geometrically overlaps Row 1 grid cards).
 *                 Required: position:fixed bottom/right corner, min-width<=420.
 *
 *   WT-B-R1-002 — Elapsed timer + ATTENTE label must NOT clip at viewport
 *                 1280×800. Required: clamp() font sizing on .kds-card__queue
 *                 and .kds-card__elapsed AND letter-spacing on elapsed-label
 *                 reduced to <=0.08em so "ATTENTE" fits at narrow widths.
 *
 *   WT-B-R1-007 — kdsIsCentralAdmin gate must require branchCount>1, so the
 *                 "Compte central" polling hint is suppressed on single-branch
 *                 installs like Le Cayenne. Locale string must NOT contain
 *                 "n'est pas abonné" or "multi-succursales" phrasing.
 */

const ROOT = path.resolve(__dirname, '..', '..');
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');

describe('KDS Wave T R1 F3 — visual heals sentinel', () => {
    describe('WT-B-R1-001 — undo toast geometry', () => {
        const toastSrc = read(
            'resources/js/components/admin/kitchenDisplaySystem/KdsUndoToast.vue'
        );

        it('uses position:fixed (not absolute) on .kds-toast', () => {
            // Specifically the .kds-toast { } scoped block — not the @keyframes.
            const block = toastSrc.match(/\.kds-toast\s*\{[^}]+\}/);
            expect(block, 'expected .kds-toast { } block').not.toBeNull();
            expect(block[0]).toMatch(/position:\s*fixed/);
            expect(block[0]).not.toMatch(/position:\s*absolute/);
        });

        it('pins toast to bottom-right corner (not top-center)', () => {
            const block = toastSrc.match(/\.kds-toast\s*\{[^}]+\}/);
            expect(block[0]).toMatch(/bottom:\s*\d+px/);
            expect(block[0]).toMatch(/right:\s*\d+px/);
            // Must NOT use the old top:110px center pattern.
            expect(block[0]).not.toMatch(/top:\s*110px/);
            expect(block[0]).not.toMatch(/translate\(-50%/);
        });

        it('shrinks min-width to <=420px so the toast doesn\'t span the grid', () => {
            const block = toastSrc.match(/\.kds-toast\s*\{[^}]+\}/);
            const mw = block[0].match(/min-width:\s*(\d+)px/);
            expect(mw, 'expected min-width on .kds-toast').not.toBeNull();
            expect(parseInt(mw[1], 10)).toBeLessThanOrEqual(420);
        });

        it('declares aria-live so screen-readers announce the undo window', () => {
            expect(toastSrc).toMatch(/aria-live=["']assertive["']/);
            expect(toastSrc).toMatch(/role=["']status["']/);
        });

        it('flips to left:24px under [dir="rtl"]', () => {
            expect(toastSrc).toMatch(/\[dir="rtl"\]\s+\.kds-toast/);
        });
    });

    describe('WT-B-R1-002 — elapsed timer not clipped at 1280px', () => {
        const cardSrc = read(
            'resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue'
        );

        it('uses clamp() on .kds-card__queue font-size (responsive shrink)', () => {
            const block = cardSrc.match(/\.kds-card__queue\s*\{[^}]+\}/);
            expect(block, 'expected .kds-card__queue block').not.toBeNull();
            expect(block[0]).toMatch(/font-size:\s*clamp\(/);
        });

        it('uses clamp() on .kds-card__elapsed font-size', () => {
            const block = cardSrc.match(/\.kds-card__elapsed\s*\{[^}]+\}/);
            expect(block, 'expected .kds-card__elapsed block').not.toBeNull();
            expect(block[0]).toMatch(/font-size:\s*clamp\(/);
        });

        it('reduces .kds-card__elapsed-label letter-spacing to <=0.08em', () => {
            const block = cardSrc.match(/\.kds-card__elapsed-label\s*\{[^}]+\}/);
            expect(block, 'expected .kds-card__elapsed-label block').not.toBeNull();
            const ls = block[0].match(/letter-spacing:\s*([\d.]+)em/);
            expect(ls, 'expected letter-spacing in em').not.toBeNull();
            expect(parseFloat(ls[1])).toBeLessThanOrEqual(0.08);
        });

        it('forces white-space:nowrap on the elapsed digits so seconds never wrap/clip', () => {
            const block = cardSrc.match(/\.kds-card__elapsed\s*\{[^}]+\}/);
            expect(block[0]).toMatch(/white-space:\s*nowrap/);
        });

        it('declares overflow:visible on the elapsed-wrap so the column never hides digits', () => {
            const block = cardSrc.match(/\.kds-card__elapsed-wrap\s*\{[^}]+\}/);
            expect(block, 'expected .kds-card__elapsed-wrap block').not.toBeNull();
            expect(block[0]).toMatch(/overflow:\s*visible/);
        });
    });

    describe('WT-B-R1-007 — admin polling banner gated on multi-branch', () => {
        const kdsParent = read(
            'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'
        );
        const masterBlade = read('resources/views/master.blade.php');
        const frJson = read('resources/js/languages/fr.json');
        const enJson = read('resources/js/languages/en.json');

        it('kdsIsCentralAdmin computed checks branchCount > 1', () => {
            // The computed must reference window.foodkingConfig?.branchCount
            // (or equivalent) and require the count to exceed 1.
            const computed = kdsParent.match(
                /kdsIsCentralAdmin\s*\(\)\s*\{[\s\S]*?\n\s{4,6}\},/
            );
            expect(computed, 'expected kdsIsCentralAdmin computed').not.toBeNull();
            expect(computed[0]).toMatch(/branchCount/);
            expect(computed[0]).toMatch(/>\s*1/);
        });

        it('master.blade.php injects branchCount into foodkingConfig', () => {
            expect(masterBlade).toMatch(/branchCount\s*:/);
            expect(masterBlade).toMatch(/Branch::query\(\)->count\(\)|Branch::count\(\)/);
        });

        it('fr.json kds_admin_polling_hint no longer says "n\'est pas abonné" or "multi-succursales"', () => {
            const m = frJson.match(/"kds_admin_polling_hint":\s*"([^"]+)"/);
            expect(m, 'expected kds_admin_polling_hint key').not.toBeNull();
            expect(m[1]).not.toMatch(/n'est pas abonn/);
            expect(m[1]).not.toMatch(/multi-succursales/);
            // Sanity: still reads as informational, not error.
            expect(m[1]).toMatch(/admin|central/i);
        });

        it('en.json kds_admin_polling_hint no longer carries the French legacy copy', () => {
            const m = enJson.match(/"kds_admin_polling_hint":\s*"([^"]+)"/);
            expect(m, 'expected kds_admin_polling_hint key').not.toBeNull();
            expect(m[1]).not.toMatch(/multi-succursales/);
        });
    });
});
