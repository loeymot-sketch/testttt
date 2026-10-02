import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { mount } from '@vue/test-utils';

import KdsOrderLine from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue';
import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';

// [GOAL CAISSE/CUISINE #7 2026-10-02] Lisibilité cuisine sur l'ÉCRAN KDS :
//  - la ligne produit portant un supplément commence par « # » gras ;
//  - les suppléments : gras BLANC sur cadre NOIR, jamais jaune ;
//  - le fond jaune de la fiche est retiré ;
//  - tout texte de la fiche ≥ 7:1 sur TOUS les fonds d'état (normal, annulée, alerte, critique).

const DIR = path.resolve(__dirname, '../../resources/js/components/admin/kitchenDisplaySystem');
const read = (f) => fs.readFileSync(path.join(DIR, f), 'utf8');
// Les commentaires CSS citent les anciennes valeurs pour expliquer le changement : on les ignore.
const sansCommentaires = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '');
const LINE = sansCommentaires(read('KdsOrderLine.vue'));
const CARD = sansCommentaires(read('KdsOrderCard.vue'));

const lum = (hex) => {
    const h = hex.replace('#', '');
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255)
        .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
    const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
};

/** Corps CSS d'un sélecteur exact dans un bloc <style>. */
const rule = (src, selector) => {
    const esc = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const m = src.match(new RegExp(`(?:^|\\n|,\\s*)${esc}\\s*(?:,[^{]*)?\\{([^}]*)\\}`));
    return m ? m[1] : null;
};
const prop = (body, name) => {
    const m = body && body.match(new RegExp(`(?:^|[;\\s])${name}\\s*:\\s*([^;]+);`));
    return m ? m[1].trim().split(/\s/)[0] : null;
};

// Fonds réellement portés par la fiche : normal, annulée (rgba .5 sur blanc), alerte, critique.
const FONDS = { normal: '#FFFFFF', annulee: '#FEF0F0', alerte: '#FFEDD5', critique: '#FEE2E2' };

describe('ligne produit à supplément : « # » gras', () => {
    const item = (extras) => ({
        item_name: 'Tacos M',
        quantity: 1,
        instruction: '',
        composition_snapshot: {
            lines: [{ attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Algérienne' }],
            extras,
            addons: [],
        },
    });

    it('marque la ligne principale quand un supplément existe', () => {
        const r = renderItemSymbolic(item([{ extra_name: 'Cheddar', unit_price: 0.9, line_total: 0.9, quantity: 1 }]));
        expect(r.lines.find((l) => l.type === 'symbolic-main').hasSupplement).toBe(true);
    });

    it('ne marque pas une ligne sans supplément', () => {
        const r = renderItemSymbolic(item([{ extra_name: 'Salade', unit_price: 0, line_total: 0, quantity: 1 }]));
        expect(r.lines.find((l) => l.type === 'symbolic-main').hasSupplement).toBeUndefined();
    });

    it('le composant affiche « # » avant le texte uniquement si hasSupplement', () => {
        const avec = mount(KdsOrderLine, { props: { line: { type: 'symbolic-main', qty: 1, label: 'Tacos | P', hasSupplement: true } }, global: { mocks: { $t: (k) => k } } });
        const sans = mount(KdsOrderLine, { props: { line: { type: 'symbolic-main', qty: 1, label: 'Tacos | P' } }, global: { mocks: { $t: (k) => k } } });
        expect(avec.find('.kds-line__hash').exists()).toBe(true);
        expect(avec.find('.kds-line__hash').text()).toBe('#');
        expect(sans.find('.kds-line__hash').exists()).toBe(false);
    });
});

describe('suppléments : blanc gras sur noir, jamais jaune', () => {
    it('la règle CSS est blanc sur noir, gras', () => {
        const body = rule(LINE, '.kds-line__supplement');
        expect(body).not.toBeNull();
        expect(prop(body, 'color').toUpperCase()).toBe('#FFFFFF');
        expect(prop(body, 'background').toUpperCase()).toBe('#000000');
        expect(Number(prop(body, 'font-weight'))).toBeGreaterThanOrEqual(700);
        expect(ratio('#FFFFFF', '#000000')).toBeGreaterThanOrEqual(7);
    });

    it('aucun jaune ne subsiste dans la ligne ni dans la fiche', () => {
        for (const jaune of ['#CA8A04', '#FEF9C3', '#F59E0B', '#FFD400', '#FFB800']) {
            expect(LINE.toUpperCase().includes(jaune), `${jaune} dans KdsOrderLine.vue`).toBe(false);
        }
        const supp = rule(CARD, '.kds-card--has-supplements');
        expect(prop(supp, 'background').toUpperCase()).toBe('#FFFFFF');
        expect(supp.toUpperCase()).not.toContain('FEF9C3');
    });

    it("l'étoile jaune n'est plus rendue : texte nettoyé", () => {
        const w = mount(KdsOrderLine, { props: { line: { type: 'supplement', label: '⭐ Cheddar' } }, global: { mocks: { $t: (k) => k } } });
        expect(w.text()).toBe('Cheddar');
    });
});

describe('contraste ≥ 7:1 sur tous les états de la fiche', () => {
    // Classes de texte de la ligne (hors cadres à fond propre : supplément, badge MENU).
    const TEXTES = [
        '.kds-line__qty', '.kds-line__name', '.kds-line__symbolic-text', '.kds-line__hash',
        '.kds-line__group', '.kds-line__sep', '.kds-line__value', '.kds-line__menu-arrow',
        '.kds-instruction--note', '.kds-instruction--exclusion', '.kds-instruction--allergen',
        '.kds-line__allergen-icon',
    ];

    for (const sel of TEXTES) {
        it(`${sel}`, () => {
            const body = rule(LINE, sel);
            expect(body, `règle ${sel} introuvable`).not.toBeNull();
            const c = prop(body, 'color');
            expect(c, `${sel} sans couleur`).toMatch(/^#[0-9a-f]{6}$/i);
            for (const [etat, fond] of Object.entries(FONDS)) {
                expect(ratio(c, fond), `${sel} ${c} sur ${etat} ${fond}`).toBeGreaterThanOrEqual(7);
            }
        });
    }

    it("la fiche « prête » n'est plus délavée par une opacité", () => {
        const body = rule(CARD, '.kds-card--ready');
        expect(body).not.toBeNull();
        expect(body).not.toMatch(/opacity\s*:/);
    });
});
