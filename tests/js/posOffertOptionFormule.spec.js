import { describe, expect, it, beforeEach, vi } from 'vitest';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

import { posCart } from '../../resources/js/store/modules/posCart.js';
import { formulaOptionExtras } from '../../resources/js/helpers/posFormulaBilling.js';
import { computePosCartLineDisplayTotal } from '../../resources/js/helpers/posCartLineMath.js';
import fs from 'node:fs';
import path from 'node:path';

// [GOAL REMARQUES 2026-10-03 · T-3.7 R-041] Propriétaire, 02/10 : un petit bouton « Offert » pour ne pas
// facturer une sauce ou un supplément à un client habitué. Le 02/10 l'a fait pour les extras du produit ;
// les OPTIONS DE FORMULE (« Grande Portion », « Cheddar Fondu ») — facturées depuis le 02/10 sur la ligne
// formule — n'avaient pas de bouton. Même mécanisme : l'id passe de `item_extras` (payant) à
// `item_extras_offered` ; le serveur (OfferedExtras) l'accepte car l'extra appartient au produit de la
// ligne formule. Aucun prix n'est envoyé.

const catalogue = {
    addon_item_extras: [
        { id: 234, name: 'Grande Portion', price: 1 },
        { id: 235, name: 'Cheddar Fondu', price: 1 },
    ],
};

const panier = () => ({
    lists: [{
        item_id: 22, name: 'Cayenne', quantity: 1, convert_price: 8, item_extras: [], item_extras_offered: [],
        pos_line_addons: [{
            item_id: 1, name: 'Menu (Frites + Boisson)', quantity: 1,
            item_extras: formulaOptionExtras({ fritesGrande: true, fritesCheddar: true }, catalogue, 1),
        }],
    }],
});

describe('Offert sur une option de formule (R-041)', () => {
    it('l\'option porte son prix catalogue (affichage seulement) pour être offrable', () => {
        expect(formulaOptionExtras({ fritesGrande: true }, catalogue, 1)[0].unit_price).toBe(1);
    });

    it('offrir déplace l\'option vers item_extras_offered ; ré-appuyer la remet payante', () => {
        const state = panier();
        posCart.mutations.toggleAddonExtraOffered(state, { index: 0, addonIndex: 0, extraId: 234 });
        const formule = state.lists[0].pos_line_addons[0];
        expect(formule.item_extras.map((e) => e.id)).toEqual([235]);
        expect(formule.item_extras_offered.map((e) => e.id)).toEqual([234]);

        posCart.mutations.toggleAddonExtraOffered(state, { index: 0, addonIndex: 0, extraId: 234 });
        expect(state.lists[0].pos_line_addons[0].item_extras.map((e) => e.id).sort()).toEqual([234, 235]);
        expect(state.lists[0].pos_line_addons[0].item_extras_offered).toEqual([]);
    });

    it('le total affiché du panier baisse du prix de l\'option offerte (affiché = facturé)', () => {
        const state = panier();
        state.lists[0].pos_line_addons[0].total_price = 4.5; // 2,50 formule + 1 Grande Portion + 1 Cheddar Fondu
        const avant = computePosCartLineDisplayTotal(state.lists[0]);
        posCart.mutations.toggleAddonExtraOffered(state, { index: 0, addonIndex: 0, extraId: 234 });
        expect(computePosCartLineDisplayTotal(state.lists[0])).toBeCloseTo(avant - 1, 6);
    });

    it('une option gratuite ou inconnue ne bascule pas', () => {
        const state = panier();
        state.lists[0].pos_line_addons[0].item_extras.push({ id: 999, item_id: 1, name: 'Gratuit', quantity: 1, unit_price: 0 });
        posCart.mutations.toggleAddonExtraOffered(state, { index: 0, addonIndex: 0, extraId: 999 });
        expect(state.lists[0].pos_line_addons[0].item_extras_offered || []).toEqual([]);
    });

    it('la ligne envoyée au serveur ne porte JAMAIS de prix : ids et quantités seulement', () => {
        const src = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'), 'utf8');
        const corps = src.slice(src.indexOf('const item_extras = this.cartExtraEntries(row).map('), src.indexOf('return {\n                item_id: row.item_id,'));
        expect(corps).not.toMatch(/unit_price|price:/);
    });

    it('le panier de la caisse affiche le bouton « Offert » des options de formule', () => {
        const src = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/pos/PosComponent.vue'), 'utf8');
        expect(src).toContain('data-testid="pos-cart-offer-addon-extra"');
        expect(src).toMatch(/posCart\/toggleAddonExtraOffered/);
    });
});
