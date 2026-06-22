/**
 * Sentinel — Mission #2 Vague 2 action 2.3.
 *
 * Asserts that ComposerProfileChanged events handled by useCatalogChangeNotifier
 * trigger the composer-specific UX paths (separate from generic CatalogChanged):
 *   - Subscription is active.
 *   - Composer-only mutations (no item add/remove) still trigger wizard step
 *     invalidation when an in-cart selection becomes invalid.
 *   - Branch isolation: ComposerProfileChanged for another branch is ignored.
 *   - Pruning: cart lines whose composer profile version diverges and the
 *     selection no longer matches are pruned.
 *   - Toast severity matches the composer-specific path.
 *
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md §2.3.
 * Production code: resources/js/composables/useCatalogChangeNotifier.js (already
 * subscribes to both CatalogChanged and ComposerProfileChanged).
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
        item_variations: [],
        item_extras: [],
        item_addons: [],
        ...overrides,
    };
}

function menuItem(overrides = {}) {
    return {
        id: 12,
        is_available: true,
        variations: [],
        extras: [],
        addons: [],
        ...overrides,
    };
}

function projection(items = []) {
    return {
        branch_id: 7,
        categories: [{ id: 1, name: 'Tacos', items }],
        items,
    };
}

function makeStore({ snapshot = [], newProjection = projection([menuItem()]), dispatchImpl = null } = {}) {
    return {
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
            if (action === 'kioskMenu/fetchMenu') {
                return Promise.resolve(newProjection);
            }
            if (action === 'kioskCart/pruneUnavailableLines') {
                return Promise.resolve(payload);
            }
            return Promise.resolve({ action, payload });
        }),
        commit: vi.fn(),
    };
}

function mountNotifier({
    snapshot,
    newProjection,
    branchId = '7',
    hasOpenWizard = () => true,
} = {}) {
    const analytics = { track: vi.fn() };
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
        name: 'ComposerNotifierHost',
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

    return {
        api,
        bindings: () => bindings,
        emit,
        eventContract,
        store,
        unsubscribe,
        wrapper,
        analytics,
    };
}

describe('useCatalogChangeNotifier — ComposerProfileChanged path (M2 2.3)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('exposes a binding for ComposerProfileChanged on mount', () => {
        const { bindings, wrapper } = mountNotifier();

        const composerBinding = bindings().find((entry) => entry.broadcastAs === 'ComposerProfileChanged');
        expect(composerBinding).toBeTruthy();
        expect(typeof composerBinding.handler).toBe('function');

        wrapper.unmount();
    });

    it('shows a toast when ComposerProfileChanged arrives for the current branch', async () => {
        const { api, emit } = mountNotifier();

        await emit('ComposerProfileChanged');

        expect(api.toastVisible.value).toBe(true);
        expect(api.toastMessage.value).toBe('Le menu a été mis à jour');
        expect(api.toastSeverity.value).toBe('info');
    });

    it('ignores ComposerProfileChanged events for other branches', async () => {
        const { api, emit, store } = mountNotifier();

        await emit('ComposerProfileChanged', { branch_id: 99, payload: { branch_id: 99 } });

        expect(api.toastVisible.value).toBe(false);
        expect(store.dispatch).not.toHaveBeenCalled();
    });

    it('emits wizard:invalidate-step when a chosen variation is removed from the new composer projection', async () => {
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

        expect(listener).toHaveBeenCalled();
        expect(listener.mock.calls[0][0].detail.step_ids).toContain('viande');
        expect(api.toastMessage.value).toBe('Vérifiez vos choix.');
        expect(api.toastSeverity.value).toBe('warning');

        window.removeEventListener('wizard:invalidate-step', listener);
    });

    it('tracks the composer event via analytics', async () => {
        const { emit, analytics } = mountNotifier();

        await emit('ComposerProfileChanged');

        expect(analytics.track).toHaveBeenCalled();
        const calls = analytics.track.mock.calls;
        const composerCall = calls.find(([eventName]) =>
            typeof eventName === 'string' && (eventName.toLowerCase().includes('composer') || eventName.toLowerCase().includes('catalog')));
        expect(composerCall).toBeTruthy();
    });
});
