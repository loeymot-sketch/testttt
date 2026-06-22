/**
 * Sentinel — Mission #2 Vague 1 action 1.3.
 *
 * Asserts that when CatalogChanged or ComposerProfileChanged arrives
 * while the kiosk wizard is open with a non-empty cart, the
 * useCatalogChangeNotifier composable surfaces a toast, prunes
 * unavailable items, and announces via the a11y helper.
 *
 * Plan: plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md task 1.3
 */

import { defineComponent } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useCatalogChangeNotifier } from '../../resources/js/composables/useCatalogChangeNotifier.js';
import { announce } from '../../resources/js/helpers/a11y/announcer.js';

vi.mock('../../resources/js/helpers/a11y/announcer.js', () => ({
    announce: vi.fn(),
}));

const translations = {
    'catalog.menu_refreshed_toast': 'Le menu a été mis à jour',
    'catalog.cart_lines_removed': 'Certaines lignes ont été retirées de votre panier ({count})',
    'catalog.menu_refreshed_recheck_selection': 'Vérifiez vos choix.',
};

function testI18n() {
    return {
        t(key, params = {}) {
            const text = translations[key] || key;
            return text.replace('{count}', params.count ?? '');
        },
    };
}

function cartLine(overrides = {}) {
    return {
        cart_line_id: 'line-1',
        item_id: 12,
        name: 'Tacos',
        item_variations: [{ id: 101, variation_name: 'viande', name: 'Poulet' }],
        item_extras: [],
        item_addons: [],
        ...overrides,
    };
}

function projection(items = [menuItem()]) {
    return {
        branch_id: 7,
        categories: [{ id: 1, name: 'Tacos', items }],
        items,
    };
}

function menuItem(overrides = {}) {
    return {
        id: 12,
        name: 'Tacos',
        variations: { 1: [{ id: 101, name: 'Poulet' }] },
        extras: [],
        addons: [],
        ...overrides,
    };
}

function makeStore({ snapshot = [cartLine()], newProjection = projection(), dispatchImpl = null } = {}) {
    const store = {
        getters: {
            'kioskCart/snapshot': snapshot,
            'kioskCart/items': snapshot,
            'kioskMenu/forBranch': vi.fn(() => newProjection),
            'kioskMenu/categories': newProjection.categories,
            'kioskMenu/allItems': newProjection.items,
        },
        state: {
            kioskCart: { items: snapshot, branchId: 7 },
            kioskMenu: { categories: newProjection.categories, items: newProjection.items },
        },
        dispatch: vi.fn((action, payload) => {
            if (dispatchImpl) {
                return dispatchImpl(action, payload);
            }
            return Promise.resolve({ action, payload });
        }),
        commit: vi.fn(),
    };
    return store;
}

function mountNotifier({
    branchId = 7,
    snapshot = [cartLine()],
    newProjection = projection(),
    analytics = null,
    hasOpenWizard = () => false,
} = {}) {
    let api = null;
    let bindings = [];
    const unsubscribe = vi.fn();
    const eventContract = {
        onEvents: vi.fn((subscribedBranchId, nextBindings) => {
            bindings = nextBindings;
            return { unsubscribe };
        }),
    };
    const store = makeStore({ snapshot, newProjection });
    const Host = defineComponent({
        name: 'CatalogNotifierHost',
        setup() {
            api = useCatalogChangeNotifier({
                store,
                eventContract,
                branchId,
                i18n: testI18n(),
                analytics,
                hasOpenWizard,
            });
            return () => null;
        },
    });

    const wrapper = mount(Host);

    const emit = async (broadcastAs, event = { branch_id: branchId, payload: { branch_id: branchId } }) => {
        const binding = bindings.find((entry) => entry.broadcastAs === broadcastAs);
        expect(binding).toBeTruthy();
        await binding.handler(event);
        await flushPromises();
    };

    return { api, bindings: () => bindings, emit, eventContract, store, unsubscribe, wrapper };
}

describe('useCatalogChangeNotifier composable', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('subscribes to CatalogChanged and ComposerProfileChanged on mount', () => {
        const { bindings, eventContract, unsubscribe, wrapper } = mountNotifier();

        expect(eventContract.onEvents).toHaveBeenCalledWith('7', expect.any(Array));
        expect(bindings().map((entry) => entry.broadcastAs)).toEqual([
            'CatalogChanged',
            'ComposerProfileChanged',
        ]);

        wrapper.unmount();
        expect(unsubscribe).toHaveBeenCalledTimes(1);
    });

    it('shows a toast when a CatalogChanged event arrives for the same branch', async () => {
        const { api, emit } = mountNotifier();

        await emit('CatalogChanged');

        expect(api.toastVisible.value).toBe(true);
        expect(api.toastMessage.value).toBe('Le menu a été mis à jour');
        expect(api.toastSeverity.value).toBe('info');
    });

    it('ignores events for other branches', async () => {
        const { api, emit, store } = mountNotifier();

        await emit('CatalogChanged', { branch_id: 99, payload: { branch_id: 99 } });

        expect(api.toastVisible.value).toBe(false);
        expect(store.dispatch).not.toHaveBeenCalled();
    });

    it('prunes cart lines that reference items removed from the new projection', async () => {
        const { api, emit, store } = mountNotifier({
            snapshot: [cartLine({ item_id: 12 })],
            newProjection: projection([]),
        });

        await emit('CatalogChanged');

        expect(store.dispatch).toHaveBeenCalledWith('kioskMenu/fetchMenu', {
            branchId: '7',
            force: true,
        });
        expect(store.dispatch).toHaveBeenCalledWith('kioskCart/pruneUnavailableLines', [12]);
        expect(store.commit).toHaveBeenCalledWith('kioskCart/SET_CART_LINES', []);
        expect(api.toastMessage.value).toBe('Certaines lignes ont été retirées de votre panier (1)');
        expect(api.toastSeverity.value).toBe('warning');
    });

    it('emits wizard:invalidate-step when a chosen variation is removed from current profile', async () => {
        const listener = vi.fn();
        window.addEventListener('wizard:invalidate-step', listener);
        const { api, emit } = mountNotifier({
            snapshot: [cartLine({
                item_variations: [{ id: 999, variation_name: 'viande', name: 'Boeuf' }],
            })],
            newProjection: projection([menuItem({
                variations: { 1: [{ id: 101, name: 'Poulet' }] },
            })]),
        });

        await emit('ComposerProfileChanged');

        expect(listener).toHaveBeenCalledTimes(1);
        expect(listener.mock.calls[0][0].detail.step_ids).toEqual(['viande']);
        expect(api.removedSelectionsByStep.value.viande[0]).toMatchObject({
            cart_line_id: 'line-1',
            option_id: 999,
            label: 'Boeuf',
        });

        window.removeEventListener('wizard:invalidate-step', listener);
    });

    it('calls announcer with the toast message', async () => {
        const { api, emit } = mountNotifier();

        await emit('CatalogChanged');

        expect(announce).toHaveBeenCalledWith(api.toastMessage.value, 'polite');
    });

    it('tracks the event via kioskAnalytics.track when analytics is provided', async () => {
        const analytics = { track: vi.fn() };
        const { emit } = mountNotifier({
            analytics,
            snapshot: [cartLine({
                item_variations: [{ id: 999, variation_name: 'viande', name: 'Boeuf' }],
            })],
            newProjection: projection([menuItem()]),
        });

        await emit('CatalogChanged');

        expect(analytics.track).toHaveBeenCalledWith('catalog_change_mid_session', {
            removed_items: 0,
            removed_selections: 1,
            branch_id: '7',
        });
    });
});
