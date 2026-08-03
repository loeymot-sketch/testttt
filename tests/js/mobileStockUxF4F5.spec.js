// mobileStockUxF4F5.spec.js — /m (stock mobile PIN-gated) UX finitions F4 + F5.
//
// [F4/F5 2026-07-24] Source : reports/goal-global-validation-2026-07-24/
// ACCES-cuisine-mobile-findings.md
//   F4 — recherche live + quantités (on_hand) + catégories repliables.
//   F5 — confirmation 2-taps avant de couper un produit (RUPTURE), 1 tap pour remettre.
//
// La page /m est un Blade AUTONOME (inline CSS + vanilla JS, PAS de build Mix) : on
// exécute le VRAI <script> livré dans happy-dom (même technique d'eval-IIFE que
// tests/js/mobileDataAntiFiction.spec.js) et on pilote le DOM réel.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const BLADE = resolve(process.cwd(), 'resources/views/mobile-stock.blade.php');

const flush = async (n = 6) => {
    for (let i = 0; i < n; i++) {
        // eslint-disable-next-line no-await-in-loop
        await new Promise((r) => setTimeout(r, 0));
    }
};

// Catalogue de test : une catégorie « Tacos » (avec quantité) + « Boissons » (sans),
// et un groupe d'ingrédients « Sauces ».
function catalogPayload() {
    return {
        branch_id: 1,
        shopping: [],
        categories: [
            {
                id: 1, name: 'Tacos', items: [
                    { id: 11, name: 'Tacos Poulet', is_available: true, reason: null, on_hand: 12 },
                    { id: 12, name: 'Tacos Viande', is_available: false, reason: 'stock_rupture', on_hand: 0 },
                ],
            },
            {
                id: 2, name: 'Boissons', items: [
                    { id: 21, name: 'Coca', is_available: true, reason: null, on_hand: null },
                ],
            },
        ],
        ingredients: [
            { group: 'Sauces', kind: 'extra', items: [{ name: 'Andalouse', ids: [31, 32], is_available: true }] },
        ],
    };
}

let fetchCalls;

function installBlade() {
    let raw = readFileSync(BLADE, 'utf8');
    // Neutralise les directives Blade pour obtenir du HTML+JS valides.
    raw = raw
        .replace(/\{\{\s*csrf_token\(\)\s*\}\}/g, 'test-csrf')
        .replace(/\{\{\s*url\('([^']+)'\)\s*\}\}/g, '$1');

    const bodyInner = raw.match(/<body>([\s\S]*?)<\/body>/)[1];
    const scriptSrc = bodyInner.match(/<script>([\s\S]*?)<\/script>/)[1];
    const markup = bodyInner.replace(/<script>[\s\S]*?<\/script>/, '');

    document.head.innerHTML = '<meta name="csrf-token" content="test-csrf">';
    document.body.innerHTML = markup;

    // eslint-disable-next-line no-eval
    (0, eval)(scriptSrc);
}

describe('/m F4/F5 — recherche, quantités, repli, confirmation 2-taps', () => {
    beforeEach(async () => {
        fetchCalls = [];
        global.fetch = vi.fn((url, opts) => {
            fetchCalls.push({ url: String(url), opts });
            let body = {};
            if (String(url).indexOf('/m/api/status') !== -1) body = { unlocked: true };
            else if (String(url).indexOf('/m/api/catalog') !== -1) body = catalogPayload();
            else if (String(url).indexOf('/m/api/toggle-extra') !== -1) body = { ok: true, is_available: false };
            else if (String(url).indexOf('/m/api/toggle') !== -1) {
                const sent = JSON.parse((opts && opts.body) || '{}');
                body = { ok: true, is_available: !!sent.is_available };
            }
            return Promise.resolve({ status: 200, json: () => Promise.resolve(body) });
        });
        installBlade();
        await flush(); // boot: status -> showStock -> loadCatalog -> render
    });

    afterEach(() => { vi.restoreAllMocks(); });

    const catalogText = () => document.getElementById('catalog').textContent;
    const rowsFor = (text) => Array.from(document.querySelectorAll('#catalog .row'))
        .filter((r) => r.querySelector('.name').textContent.indexOf(text) !== -1);

    it('boots into the stock screen and renders the catalog', () => {
        expect(document.getElementById('stock-screen').classList.contains('hidden')).toBe(false);
        expect(catalogText()).toContain('Tacos Poulet');
        expect(catalogText()).toContain('Coca');
    });

    // ---- F4 : quantités (on_hand) ----
    it('F4 — affiche « 12 en stock » quand on_hand est fourni, rien quand null', () => {
        const badges = Array.from(document.querySelectorAll('#catalog .qty')).map((b) => b.textContent);
        expect(badges).toContain('12 en stock');
        // Coca a on_hand=null → sa ligne ne porte AUCUN badge quantité.
        const cocaRow = rowsFor('Coca')[0];
        expect(cocaRow.querySelector('.qty')).toBeNull();
    });

    // ---- F4 : recherche live ----
    it('F4 — la recherche filtre en direct sans appel réseau', async () => {
        const before = fetchCalls.length;
        const input = document.getElementById('stock-search');
        input.value = 'coca';
        input.dispatchEvent(new Event('input'));

        expect(catalogText()).toContain('Coca');
        expect(catalogText()).not.toContain('Tacos Poulet');
        // Filtrage purement local : aucun fetch déclenché par la frappe.
        expect(fetchCalls.length).toBe(before);
    });

    it('F4 — recherche accent-insensible + bandeau « aucun résultat »', () => {
        const input = document.getElementById('stock-search');
        input.value = 'ANDALOUSE';
        input.dispatchEvent(new Event('input'));
        expect(document.getElementById('ingredients').textContent).toContain('Andalouse');

        input.value = 'zzz-introuvable';
        input.dispatchEvent(new Event('input'));
        expect(document.getElementById('no-results').classList.contains('hidden')).toBe(false);
    });

    // ---- F4 : catégories repliables ----
    it('F4 — cliquer l’en-tête catégorie replie puis déplie la section', () => {
        const head = document.querySelector('#catalog .cat-head');
        const body = head.nextElementSibling;
        expect(body.classList.contains('hidden')).toBe(false);
        head.click();
        // Après repli, le corps de la 1re catégorie est masqué.
        expect(document.querySelector('#catalog .cat-head').nextElementSibling.classList.contains('hidden')).toBe(true);
        document.querySelector('#catalog .cat-head').click();
        expect(document.querySelector('#catalog .cat-head').nextElementSibling.classList.contains('hidden')).toBe(false);
    });

    // ---- F5 : confirmation 2-taps sur RUPTURE ----
    it('F5 — couper un produit EN STOCK exige 2 taps (1er = confirmation, pas de coupe)', async () => {
        const row = rowsFor('Tacos Poulet')[0];
        const btn = row.querySelector('.toggle');
        expect(btn.textContent).toBe('EN STOCK');

        btn.click(); // 1er tap → arme la confirmation, AUCUN appel toggle
        await flush(1);
        expect(fetchCalls.some((c) => c.url.indexOf('/m/api/toggle') !== -1 && c.url.indexOf('toggle-extra') === -1)).toBe(false);
        expect(btn.textContent).toContain('Confirmer');
        expect(row.querySelector('.cancel-btn')).not.toBeNull();

        btn.click(); // 2e tap → coupe réellement
        await flush(2);
        const toggleCall = fetchCalls.find((c) => c.url.indexOf('/m/api/toggle') !== -1 && c.url.indexOf('toggle-extra') === -1);
        expect(toggleCall).toBeTruthy();
        expect(JSON.parse(toggleCall.opts.body).is_available).toBe(false);
    });

    it('F5 — « Annuler » désarme la confirmation sans couper', async () => {
        const row = rowsFor('Tacos Poulet')[0];
        const btn = row.querySelector('.toggle');
        btn.click(); // arme
        row.querySelector('.cancel-btn').click(); // annule
        await flush(1);
        expect(btn.textContent).toBe('EN STOCK');
        expect(row.querySelector('.cancel-btn')).toBeNull();
        expect(fetchCalls.some((c) => c.url.indexOf('/m/api/toggle') !== -1 && c.url.indexOf('toggle-extra') === -1)).toBe(false);
    });

    it('F5 — remettre EN STOCK un produit rupturé reste 1 seul tap (réversible)', async () => {
        const row = rowsFor('Tacos Viande')[0];
        const btn = row.querySelector('.toggle');
        expect(btn.textContent).toBe('RUPTURE');
        btn.click(); // 1 tap direct
        await flush(2);
        const toggleCall = fetchCalls.find((c) => c.url.indexOf('/m/api/toggle') !== -1 && c.url.indexOf('toggle-extra') === -1);
        expect(toggleCall).toBeTruthy();
        expect(JSON.parse(toggleCall.opts.body).is_available).toBe(true);
    });
});
