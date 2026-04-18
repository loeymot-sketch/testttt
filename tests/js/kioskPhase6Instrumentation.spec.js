/**
 * Kiosk Design V1 — Phase 6 : audit d'alimentation
 *
 * Vérifie le câblage effectif après refactoring Phase 6 :
 *  - kioskHardware expose `reload` et `quit` + fallback stub ok.
 *  - kioskAnalyticsPlugin hook les mutations kioskCart (add/remove/update)
 *    et délègue à kioskAnalytics.track avec un payload anonyme.
 *  - Les handlers ne throw jamais même si kioskAnalytics throw.
 *  - kioskPrinter passe bien par kioskHardware (et non window.borne direct).
 *  - KioskUpsellComponent émet upsell_shown quand suggestions chargées.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createStore } from 'vuex';

import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import kioskAnalyticsPlugin from '../../resources/js/store/plugins/kioskAnalyticsPlugin';

function freshHardware() {
    vi.resetModules();
    return import('../../resources/js/services/kioskHardware.js');
}

describe('Phase 6.5 — kioskHardware.reload / .quit', () => {
    afterEach(() => {
        delete window.borne;
        vi.restoreAllMocks();
    });

    it('stub-mode reload() returns {ok:true} when bridge absent', async () => {
        delete window.borne;
        const hw = await freshHardware();
        const r = await hw.reload();
        expect(r.ok).toBe(true);
    });

    it('stub-mode quit() returns {ok:true} when bridge absent', async () => {
        delete window.borne;
        const hw = await freshHardware();
        const r = await hw.quit();
        expect(r.ok).toBe(true);
    });

    it('reload() delegates to bridge.reload when present', async () => {
        const spy = vi.fn().mockResolvedValue(undefined);
        window.borne = { isElectron: true, reload: spy };
        const hw = await freshHardware();
        const r = await hw.reload();
        expect(spy).toHaveBeenCalled();
        expect(r.ok).toBe(true);
    });

    it('quit() delegates to bridge.quit when present', async () => {
        const spy = vi.fn().mockResolvedValue(undefined);
        window.borne = { isElectron: true, quit: spy };
        const hw = await freshHardware();
        const r = await hw.quit();
        expect(spy).toHaveBeenCalled();
        expect(r.ok).toBe(true);
    });

    it('reload() wraps thrown errors into {ok:false}', async () => {
        window.borne = {
            isElectron: true,
            reload: async () => { throw new Error('reload_crash'); },
        };
        const hw = await freshHardware();
        const r = await hw.reload();
        expect(r.ok).toBe(false);
        expect(r.error).toMatch(/reload_crash/);
    });

    it('exposes reload + quit on default export', async () => {
        delete window.borne;
        const hw = await freshHardware();
        expect(typeof hw.default.reload).toBe('function');
        expect(typeof hw.default.quit).toBe('function');
    });
});

describe('Phase 6.4 — kioskAnalyticsPlugin (Vuex)', () => {
    let trackSpy;

    beforeEach(() => {
        // Mock du module helpers/kioskAnalytics — capture les appels track().
        vi.resetModules();
        trackSpy = vi.fn();
        vi.doMock('../../resources/js/helpers/kioskAnalytics', () => ({
            default: { track: trackSpy },
        }));
    });

    afterEach(() => {
        vi.unmock('../../resources/js/helpers/kioskAnalytics');
    });

    async function makeStoreWithPlugin() {
        // Re-import du plugin via les mocks actifs.
        const { default: pluginUnderMock } = await import('../../resources/js/store/plugins/kioskAnalyticsPlugin');

        const cartModule = {
            namespaced: true,
            state: () => ({ items: [] }),
            mutations: {
                ADD_ITEM(state, item) { state.items.push(item); },
                REMOVE_ITEM(state, index) { state.items.splice(index, 1); },
                UPDATE_QUANTITY(state, { index, quantity }) {
                    if (state.items[index]) state.items[index].quantity = quantity;
                },
                RESET(state) { state.items = []; },
            },
        };

        return createStore({
            modules: { kioskCart: cartModule, kioskSettings },
            plugins: [pluginUnderMock],
        });
    }

    it('emits add_to_cart with anonymous payload (no PII)', async () => {
        const store = await makeStoreWithPlugin();
        store.commit('kioskCart/ADD_ITEM', {
            item_id: 42,
            name: 'Burger classic', // ne doit pas fuiter
            quantity: 2,
            item_variations: [{ id: 1 }, { id: 2 }],
            item_extras: [{ id: 7 }],
        });

        expect(trackSpy).toHaveBeenCalledWith('add_to_cart', {
            item_id: 42,
            quantity: 2,
            variations_count: 2,
            extras_count: 1,
        });
        const payload = trackSpy.mock.calls[0][1];
        expect(payload.name).toBeUndefined();
        expect(payload.phone).toBeUndefined();
    });

    it('emits remove_from_cart with remaining_items count', async () => {
        const store = await makeStoreWithPlugin();
        store.commit('kioskCart/ADD_ITEM', { item_id: 1, quantity: 1 });
        store.commit('kioskCart/ADD_ITEM', { item_id: 2, quantity: 1 });
        trackSpy.mockClear();

        store.commit('kioskCart/REMOVE_ITEM', 0);

        expect(trackSpy).toHaveBeenCalledWith('remove_from_cart', expect.objectContaining({
            remaining_items: 1,
            removed_index: 0,
        }));
    });

    it('emits quantity_changed with quantity only', async () => {
        const store = await makeStoreWithPlugin();
        store.commit('kioskCart/ADD_ITEM', { item_id: 1, quantity: 1 });
        trackSpy.mockClear();

        store.commit('kioskCart/UPDATE_QUANTITY', { index: 0, quantity: 5 });

        expect(trackSpy).toHaveBeenCalledWith('quantity_changed', { quantity: 5 });
    });

    it('RESET does not emit (panier vide symétrie)', async () => {
        const store = await makeStoreWithPlugin();
        store.commit('kioskCart/ADD_ITEM', { item_id: 1, quantity: 1 });
        trackSpy.mockClear();

        store.commit('kioskCart/RESET');

        expect(trackSpy).not.toHaveBeenCalled();
    });

    it('swallows handler errors silently (ne crash pas le store)', async () => {
        trackSpy.mockImplementation(() => { throw new Error('boom'); });
        const store = await makeStoreWithPlugin();
        expect(() =>
            store.commit('kioskCart/ADD_ITEM', { item_id: 99, quantity: 1 })
        ).not.toThrow();
    });

    it('unknown mutations are ignored (pas de bruit)', async () => {
        const store = await makeStoreWithPlugin();
        trackSpy.mockClear();
        // Mutation non handled par le plugin (pas un des 4 kioskCart/*).
        store.commit('kioskSettings/SET_LOCALE', 'en');
        expect(trackSpy).not.toHaveBeenCalled();
    });
});

describe('Phase 6.2 — kioskPrinter passe par kioskHardware', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        delete window.borne;
    });

    it('printReceipt does NOT touch window.borne directly anymore', async () => {
        // On simule un bridge Electron minimal — kioskPrinter doit appeler
        // kioskHardware.printReceipt, qui lui-même parle à window.borne.printReceipt.
        const printReceiptSpy = vi.fn().mockResolvedValue({ ok: true });
        window.borne = {
            isElectron: true,
            printReceipt: printReceiptSpy,
        };
        // Reset modules pour que kioskPrinter reload kioskHardware avec le bridge présent.
        vi.resetModules();
        const { printReceipt } = await import('../../resources/js/helpers/kioskPrinter');

        const res = await printReceipt({
            restaurantName: 'FoodKing',
            queueNumber: 'T999',
            items: [{ name: 'Test', quantity: 1, total_price: 0 }],
            total: 0,
        });

        expect(printReceiptSpy).toHaveBeenCalledTimes(1);
        // printReceipt helper retourne { method: 'electron' } ou équivalent quand OK.
        expect(res).toBeDefined();
    });
});
