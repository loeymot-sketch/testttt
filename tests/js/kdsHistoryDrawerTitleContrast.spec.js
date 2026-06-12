/**
 * [W-REM T-R3.1c 2026-06-12] KDS « Historique du jour » — contraste AA du titre.
 * -----------------------------------------------------------------------------
 * Constat audit : le <h2 class="kds-history-drawer__title"> est rendu DANS le
 * header sombre (.kds-history-drawer__header { background:#111111; color:#fff })
 * MAIS la base Tailwind globale (resources/css/app.css : h1..h6 { @apply
 * text-heading } avec heading=#1F1F39, tailwind.config.js:32) pose une règle
 * DIRECTE sur h2 qui bat l'héritage du blanc → titre #1F1F39 sur #111111
 * ≈ 1,16:1 (illisible, WCAG AA exige ≥ 4,5:1).
 *
 * Invariant : .kds-history-drawer__title déclare explicitement une couleur
 * dont le contraste avec le fond du header est ≥ 4,5:1.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const src = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue'),
    'utf-8',
)
    // Strip les commentaires CSS AVANT parsing : un commentaire peut citer
    // « h2 { color:#1F1F39 } » et fausser l'extraction de règle naïve.
    .replace(/\/\*[\s\S]*?\*\//g, '');

function extractRule(selector) {
    const re = new RegExp(`${selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*\\{([^}]*)\\}`);
    const m = src.match(re);
    return m ? m[1] : null;
}

function hexToRgb(hex) {
    let h = hex.replace('#', '');
    if (h.length === 3) h = h.split('').map((c) => c + c).join('');
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
}

function luminance([r, g, b]) {
    const f = (v) => {
        const s = v / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}

function contrastRatio(fgHex, bgHex) {
    const L1 = luminance(hexToRgb(fgHex));
    const L2 = luminance(hexToRgb(bgHex));
    const [hi, lo] = L1 >= L2 ? [L1, L2] : [L2, L1];
    return (hi + 0.05) / (lo + 0.05);
}

describe('[T-R3.1c] KDS history drawer title vs dark header — WCAG AA', () => {
    it('le header est bien sombre (#111111) — prérequis du constat', () => {
        const headerRule = extractRule('.kds-history-drawer__header');
        expect(headerRule).toBeTruthy();
        expect(headerRule).toMatch(/background:\s*#111111/);
    });

    it('le titre déclare explicitement sa couleur (sinon h2 global #1F1F39 gagne)', () => {
        const titleRule = extractRule('.kds-history-drawer__title');
        expect(titleRule).toBeTruthy();
        expect(titleRule).toMatch(/color:\s*#[0-9a-fA-F]{3,6}/);
    });

    it('contraste titre/fond header ≥ 4,5:1 (AA texte normal)', () => {
        const titleRule = extractRule('.kds-history-drawer__title');
        const m = titleRule && titleRule.match(/color:\s*(#[0-9a-fA-F]{3,6})/);
        expect(m).toBeTruthy();
        const ratio = contrastRatio(m[1], '#111111');
        expect(ratio).toBeGreaterThanOrEqual(4.5);
    });

    it('preuve du bug : #1F1F39 (h2 Tailwind global) sur #111111 était < 1,5:1', () => {
        expect(contrastRatio('#1F1F39', '#111111')).toBeLessThan(1.5);
    });
});
