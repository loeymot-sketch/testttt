/**
 * [VG-2026-10-21 C4] « Dupliquer » une ligne du panier caisse crée une SECONDE ligne, jamais une fusion.
 *
 * On duplique pour MODIFIER la copie (autre sauce…) : fusionnée dans l'original (1 ligne × 2), elle ne
 * peut plus être ajustée séparément. L'ajout NORMAL d'un même article deux fois, lui, continue de
 * fusionner (comportement historique de la caisse).
 *
 * Ce banc couvre le panier (posCart) et le relais PosComponent → ItemComponent ; le parcours complet
 * avec le vrai wizard gelé est dans posTacosDupliquerVraiWizard.spec.js.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { createStore } from 'vuex';
import { posCart } from '../../resources/js/store/modules/posCart';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

function ligne(overrides = {}) {
    return {
        name: 'Tacos M', image: '', item_id: 26, quantity: 1, discount: 0,
        currency_price: '6.90 €', convert_price: 6.9,
        item_variations: [{ id: 43, item_attribute_id: 1, quantity: 1, variation_name: 'Viande 1', name: 'Poulet mariné' }],
        item_extras: [], item_variation_total: 0, item_extra_total: 0,
        instruction: 'Viande : Poulet mariné · Sauce : Barbecue', pos_line_addons: [], cart_display: '',
        ...overrides,
    };
}

function creerStore() {
    return createStore({ modules: { posCart } });
}

/** Le même chemin que ItemComponent.addToCart pour un ajout normal (optimiste → lists → confirmation). */
async function ajoutNormal(store, payload) {
    const tempId = await store.dispatch('posCart/addOptimistic', { item: payload });
    await store.dispatch('posCart/lists', [payload]);
    store.commit('posCart/__optimisticConfirm', tempId);
    store.commit('posCart/subtotal');
}

beforeEach(async () => {
    try { localStorage.clear(); } catch (e) { /* ignore */ }
});

describe('posCart — ajout en nouvelle ligne (« Dupliquer »)', () => {
    it("l'ajout NORMAL d'un même article deux fois fusionne toujours : 1 ligne × 2", async () => {
        const store = creerStore();
        await store.dispatch('posCart/resetCart');
        await ajoutNormal(store, ligne());
        await ajoutNormal(store, ligne());
        expect(store.state.posCart.lists.map((l) => l.quantity)).toEqual([2]);
        expect(store.state.posCart.subtotal).toBeCloseTo(13.8, 2);
    });

    it('addLine crée une SECONDE ligne même identique à une ligne existante', async () => {
        const store = creerStore();
        await store.dispatch('posCart/resetCart');
        await ajoutNormal(store, ligne());
        await store.dispatch('posCart/addLine', ligne());
        expect(store.state.posCart.lists.map((l) => l.quantity)).toEqual([1, 1]);
        expect(store.state.posCart.subtotal).toBeCloseTo(13.8, 2);
    });

    it("après une duplication, un ajout normal ne s'additionne qu'à UNE ligne (pas aux deux copies)", async () => {
        const store = creerStore();
        await store.dispatch('posCart/resetCart');
        await ajoutNormal(store, ligne());
        await store.dispatch('posCart/addLine', ligne());
        await ajoutNormal(store, ligne());
        const q = store.state.posCart.lists.map((l) => l.quantity);
        expect(q.reduce((a, b) => a + b, 0), 'trois Tacos M au total, pas quatre').toBe(3);
        expect(q).toEqual([2, 1]);
        expect(store.state.posCart.subtotal).toBeCloseTo(20.7, 2);
    });

    it('la copie reste indépendante : modifier, +/−, supprimer ne touchent que sa ligne', async () => {
        const store = creerStore();
        await store.dispatch('posCart/resetCart');
        await ajoutNormal(store, ligne());
        await store.dispatch('posCart/addLine', ligne());

        // Modifier la copie (autre sauce) : seule la ligne 1 change.
        await store.dispatch('posCart/replaceCartLine', { index: 1, item: ligne({ instruction: 'Viande : Poulet mariné · Sauce : Algérienne' }) });
        expect(store.state.posCart.lists.map((l) => l.instruction)).toEqual([
            'Viande : Poulet mariné · Sauce : Barbecue',
            'Viande : Poulet mariné · Sauce : Algérienne',
        ]);

        await store.dispatch('posCart/quantity', { id: 1, status: 'increment' });
        expect(store.state.posCart.lists.map((l) => l.quantity)).toEqual([1, 2]);

        await store.dispatch('posCart/deleteCartItem', { id: 0, status: 'decrement' });
        expect(store.state.posCart.lists).toHaveLength(1);
        expect(store.state.posCart.lists[0].instruction).toMatch(/Algérienne/);
        expect(store.state.posCart.subtotal).toBeCloseTo(13.8, 2);
    });

    it('deux lignes identiques partent au serveur comme deux lignes distinctes (payload)', async () => {
        const { normalizeCartForApi } = await import('../../resources/js/store/modules/posCart');
        const store = creerStore();
        await store.dispatch('posCart/resetCart');
        await ajoutNormal(store, ligne());
        await store.dispatch('posCart/addLine', ligne());
        const api = normalizeCartForApi(store.state.posCart.lists);
        expect(api).toHaveLength(2);
        api.forEach((row) => {
            expect(row.item_id).toBe(26);
            expect(row.quantity).toBe(1);
            expect(row.item_variations).toEqual(normalizeCartForApi([ligne()])[0].item_variations);
        });
    });
});

describe('PosComponent — « Dupliquer » demande explicitement une nouvelle ligne', () => {
    function hote(carts) {
        const appels = [];
        const ctx = {
            carts,
            $refs: { posItemComponent: { openEditFromCart: (...args) => appels.push(args) } },
            $store: { getters: {} },
            openManualSupplement: () => {},
        };
        ctx.editCartLine = PosComponent.methods.editCartLine.bind(ctx);
        return { ctx, appels };
    }

    it('« Dupliquer » → openEditFromCart(ligne, null, { newLine: true })', () => {
        const { ctx, appels } = hote([ligne()]);
        PosComponent.methods.duplicateCartLine.call(ctx, 0);
        expect(appels).toHaveLength(1);
        expect(appels[0][1]).toBeNull();
        expect(appels[0][2]).toEqual({ newLine: true });
    });

    it('« Modifier » → openEditFromCart(ligne, index) sans demande de nouvelle ligne', () => {
        const { ctx, appels } = hote([ligne()]);
        ctx.editCartLine(0);
        expect(appels).toHaveLength(1);
        expect(appels[0][1]).toBe(0);
        expect(appels[0][2] && appels[0][2].newLine).toBeFalsy();
    });
});
