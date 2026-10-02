import { beforeEach, describe, expect, it, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

// [GOAL CAISSE/CUISINE #5 2026-10-02] Bouton « Offert » sur chaque supplément / sauce du panier.
// Le client n'envoie JAMAIS de prix : seulement des ids dans `item_extras_offered`. Le total
// AFFICHÉ baisse de la valeur offerte (confort caissier) ; le total FACTURÉ reste celui de
// PricingService (preuve côté PHP : tests/Feature/Pos/OffertSurSupplementTest.php).

const localStorageMock = (() => {
    let store = {};
    return {
        getItem: (k) => store[k] || null,
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
        clear: () => { store = {}; },
    };
})();
Object.defineProperty(window, 'localStorage', { value: localStorageMock });

import { posCart } from '../../resources/js/store/modules/posCart';
import { computePosCartLineDisplayTotal, offeredValue } from '../../resources/js/helpers/posCartLineMath';

const line = (extra = {}) => ({
    item_id: 26,
    name: 'Tacos M',
    quantity: 1,
    convert_price: 9.8, // 8,00 + 2 × 0,90 (base dérivée du wizard)
    item_variation_total: 0,
    item_extra_total: 0,
    item_variations: [],
    item_extras: [{ id: 12, quantity: 2, name: 'Cheddar', unit_price: 0.9 }],
    instruction: '',
    ...extra,
});

const mk = (l) => {
    const state = { lists: [], subtotal: 0, discount: 0, restoredFromStorage: false };
    posCart.mutations.lists(state, [l]);
    posCart.mutations.subtotal(state);
    return state;
};

describe('posCart — basculer « Offert » sur un extra', () => {
    beforeEach(() => localStorageMock.clear());

    it('offre : l\'extra quitte item_extras (payant) et passe dans item_extras_offered', () => {
        const state = mk(line());

        posCart.mutations.toggleExtraOffered(state, { index: 0, extraId: 12 });

        expect(state.lists[0].item_extras).toEqual([]);
        expect(state.lists[0].item_extras_offered).toEqual([{ id: 12, quantity: 2, name: 'Cheddar', unit_price: 0.9 }]);
    });

    it('le total AFFICHÉ baisse de la valeur offerte (2 × 0,90)', () => {
        const state = mk(line());
        posCart.mutations.toggleExtraOffered(state, { index: 0, extraId: 12 });
        posCart.mutations.subtotal(state);

        expect(offeredValue(state.lists[0])).toBeCloseTo(1.8, 6);
        expect(state.lists[0].total).toBeCloseTo(8.0, 6);
        expect(state.subtotal).toBeCloseTo(8.0, 6);
    });

    it('rebascule : « Annuler l\'offert » remet l\'extra payant et le total d\'origine', () => {
        const state = mk(line());
        posCart.mutations.toggleExtraOffered(state, { index: 0, extraId: 12 });
        posCart.mutations.toggleExtraOffered(state, { index: 0, extraId: 12 });
        posCart.mutations.subtotal(state);

        expect(state.lists[0].item_extras.map((e) => [e.id, e.quantity])).toEqual([[12, 2]]);
        expect(state.lists[0].item_extras_offered).toEqual([]);
        expect(state.lists[0].total).toBeCloseTo(9.8, 6);
    });

    it('un extra sans prix connu (gratuit / ancien panier) n\'est pas offrable', () => {
        const state = mk(line({ item_extras: [{ id: 99, quantity: 1, name: 'Salade' }] }));

        posCart.mutations.toggleExtraOffered(state, { index: 0, extraId: 99 });

        expect(state.lists[0].item_extras.map((e) => e.id)).toEqual([99]);
        expect(state.lists[0].item_extras_offered).toEqual([]);
    });

    it('deux lignes identiques, l\'une offerte, ne fusionnent pas', () => {
        const state = mk(line());
        posCart.mutations.toggleExtraOffered(state, { index: 0, extraId: 12 });

        posCart.mutations.lists(state, [line()]);

        expect(state.lists).toHaveLength(2);
    });

    it('survit au rechargement du panier (forme canonique conservée)', () => {
        const state = mk(line());
        posCart.mutations.toggleExtraOffered(state, { index: 0, extraId: 12 });

        const state2 = { lists: [], subtotal: 0, discount: 0, restoredFromStorage: false };
        posCart.mutations.hydrateFromScope(state2, { lists: JSON.parse(JSON.stringify(state.lists)), subtotal: 8 });

        expect(state2.lists[0].item_extras_offered.map((e) => e.id)).toEqual([12]);
    });
});

describe('payload checkout — des ids, jamais de prix', () => {
    const src = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'), 'utf8');

    it('buildPosCheckoutOrderRow envoie item_extras_offered en {id, quantity} seulement', () => {
        const body = src.slice(src.indexOf('buildPosCheckoutOrderRow: function'), src.indexOf('cartOfferableExtras: function'));
        expect(body).toContain('item_extras_offered');
        const from = body.indexOf('const item_extras_offered');
        const offeredBlock = body.slice(from, body.indexOf('return {', from));
        expect(offeredBlock).toMatch(/id:\s*normalizeId/);
        expect(offeredBlock).not.toMatch(/unit_price|price\s*:|line_total/);
    });

    it('le panier propose un bouton « Offert » (testid) relié à la bascule', () => {
        expect(src).toContain('data-testid="pos-cart-offer-extra"');
        expect(src).toContain('toggleExtraOffered');
        expect(src).toContain("$t('pos.offer_extra')");
    });
});
