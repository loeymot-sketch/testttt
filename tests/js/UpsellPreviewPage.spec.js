/**
 * UpsellPreviewPage.spec.js — [V2-3 Phase A — A6 ultra-review 2026-05-08]
 *
 * Couvre :
 *   - Mount + bootstrap branches via Vuex `branch/lists` action
 *   - Cart : addLine() + removeLine() + au moins 1 ligne toujours présente
 *   - canSubmit validator (item_id integer + quantity ≥ 1 + branch sélectionné)
 *   - runPreview POST `admin/upsell-preview` avec body cart_items + branch_id + strategy
 *   - Render result : recommendations + latency + cart_size pills
 *   - Error path : alertService.error appelé sur 422 (UI ne montre pas de DOM error,
 *     feedback via toast)
 *   - Empty recommendations : `upsell-preview-empty` rendered
 *
 * Pattern : `KioskThemeManagerPage.spec.js` — vi.mock axios + Vuex stub +
 *           LoadingComponent stub + vue-toastification stub (alertService chain).
 *
 * Important :
 *   - Le composant n'utilise PAS axios pour charger les branches (Vuex action).
 *     Le test stub donc l'action store, pas axios.get.
 *   - Les erreurs runPreview ne s'affichent PAS dans le DOM (pas de
 *     `error-message`). Elles passent par `alertService.error()`. On assert
 *     donc sur le mock alertService, pas sur le DOM.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';

// vi.mock DOIT être hoisté avant l'import du composant testé.
vi.mock('axios', () => {
    const get = vi.fn();
    const post = vi.fn();
    return {
        default: { get, post },
    };
});

// alertService importé par UpsellPreviewPage → mock complet.
vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        default: vi.fn(),
        success: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
}));

// LoadingComponent + ErrorBoundary : stubs minimalistes (déjà testés ailleurs).
vi.mock('../../resources/js/components/admin/components/LoadingComponent', () => ({
    default: {
        name: 'LoadingComponent',
        props: ['props'],
        template: '<div data-testid="loading-stub" :data-active="props && props.isActive ? \'1\' : \'0\'" />',
    },
}));

vi.mock('../../resources/js/components/admin/components/ErrorBoundary.vue', () => ({
    default: {
        name: 'ErrorBoundary',
        template: '<div data-testid="error-boundary-stub"><slot /></div>',
    },
}));

import axios from 'axios';
import alertService from '../../resources/js/services/alertService';
import UpsellPreviewPage from '../../resources/js/components/admin/upsellPreview/UpsellPreviewPage.vue';

function makeStore({ branchLists = [], branchListsAction = null } = {}) {
    const action = branchListsAction || vi.fn(() => Promise.resolve());
    return {
        store: createStore({
            modules: {
                branch: {
                    namespaced: true,
                    state: () => ({ lists: branchLists }),
                    getters: {
                        lists: (state) => state.lists,
                    },
                    actions: {
                        lists: action,
                    },
                },
            },
        }),
        action,
    };
}

function mountPage({ branchLists = [{ id: 1, name: 'HQ Paris' }, { id: 2, name: 'Lyon' }] } = {}) {
    const { store, action } = makeStore({ branchLists });
    const w = mount(UpsellPreviewPage, {
        global: {
            plugins: [store],
            mocks: { $t: (k) => k },
        },
    });
    return { w, store, action };
}

describe('UpsellPreviewPage [V2-3 Phase A — A6]', () => {
    beforeEach(() => {
        axios.get.mockReset();
        axios.post.mockReset();
        alertService.error.mockReset();
        // Default : POST runPreview returns a small payload.
        axios.post.mockResolvedValue({
            data: {
                strategy_used: 'rule_based',
                latency_ms: 12,
                cart_size: 1,
                recommendations: [
                    { item_id: 7, name: 'Fries M', price: 2.5, reason: 'side_for_burger', score: 0.85 },
                    { item_id: 9, name: 'Coke', price: 2.0, reason: 'drink_for_burger', score: 0.80 },
                ],
            },
        });
    });

    // -----------------------------------------------------------------------
    // Test 1 — bootstrap branches via Vuex (NOT axios)
    // -----------------------------------------------------------------------
    it('dispatches branch/lists on mount and renders branch options', async () => {
        // Empty state initially → triggers dispatch.
        const { store, action } = makeStore({ branchLists: [] });
        const w = mount(UpsellPreviewPage, {
            global: { plugins: [store], mocks: { $t: (k) => k } },
        });

        await flushPromises();

        // The Vuex action must have been dispatched (loadBranches → branch/lists).
        expect(action).toHaveBeenCalled();
        // First positional arg is the action context, second is the payload.
        const payload = action.mock.calls[0][1];
        expect(payload).toEqual({ paginate: 0 });

        // Empty branches → "no_branch" placeholder option present, no axios.get.
        expect(axios.get).not.toHaveBeenCalled();
        expect(w.find('#up-branch').exists()).toBe(true);
    });

    it('renders branch <option> rows when store already has branches', async () => {
        const { w } = mountPage({
            branchLists: [{ id: 5, name: 'HQ' }, { id: 7, name: 'Marseille' }],
        });
        await flushPromises();

        const options = w.find('#up-branch').findAll('option');
        // 2 branches → 2 options (no placeholder when list non-empty).
        expect(options).toHaveLength(2);
        expect(options[0].text()).toBe('HQ');
        expect(options[1].text()).toBe('Marseille');
        // Auto-selected first branch.
        expect(w.vm.selectedBranchId).toBe(5);
    });

    // -----------------------------------------------------------------------
    // Test 2 — addLine adds a row to cartItems
    // -----------------------------------------------------------------------
    it('addLine() appends a new {item_id:null, quantity:1} entry', async () => {
        const { w } = mountPage();
        await flushPromises();

        expect(w.vm.cartItems).toHaveLength(1);

        await w.find('.upsell-preview-cart-add').trigger('click');
        await flushPromises();

        expect(w.vm.cartItems).toHaveLength(2);
        expect(w.vm.cartItems[1]).toEqual({ item_id: null, quantity: 1 });
        // 2 lines rendered now.
        expect(w.findAll('.upsell-preview-cart-line')).toHaveLength(2);
    });

    // -----------------------------------------------------------------------
    // Test 3 — removeLine deletes a row but keeps at least 1
    // -----------------------------------------------------------------------
    it('removeLine() deletes the targeted row but always keeps ≥ 1 line', async () => {
        const { w } = mountPage();
        await flushPromises();

        // Add 2 lines (3 total) so the remove button becomes visible.
        await w.find('.upsell-preview-cart-add').trigger('click');
        await w.find('.upsell-preview-cart-add').trigger('click');
        await flushPromises();
        expect(w.vm.cartItems).toHaveLength(3);

        // Click first remove button.
        const removes = w.findAll('.upsell-preview-cart-remove');
        expect(removes.length).toBeGreaterThan(0);
        await removes[0].trigger('click');
        await flushPromises();

        expect(w.vm.cartItems).toHaveLength(2);

        // Drop second one → 1 left.
        await w.findAll('.upsell-preview-cart-remove')[0].trigger('click');
        await flushPromises();
        expect(w.vm.cartItems).toHaveLength(1);

        // No remove button when only 1 line remains.
        expect(w.findAll('.upsell-preview-cart-remove')).toHaveLength(0);
    });

    // -----------------------------------------------------------------------
    // Test 4 — canSubmit validator
    // -----------------------------------------------------------------------
    it('canSubmit is false when item_id is null, quantity < 1, or no branch', async () => {
        // Case A : empty branches → no selectedBranchId → false even with valid line.
        const { store: emptyStore } = makeStore({ branchLists: [] });
        const w0 = mount(UpsellPreviewPage, {
            global: { plugins: [emptyStore], mocks: { $t: (k) => k } },
        });
        await flushPromises();
        // No branches → selectedBranchId stays null.
        w0.vm.cartItems = [{ item_id: 42, quantity: 1 }];
        await flushPromises();
        expect(w0.vm.canSubmit).toBe(false);

        // Case B : branch + valid line → true.
        const { w } = mountPage();
        await flushPromises();
        // Auto-selected branch 1, default line item_id=null → false.
        expect(w.vm.canSubmit).toBe(false);

        // Set valid line.
        w.vm.cartItems = [{ item_id: 42, quantity: 1 }];
        await flushPromises();
        expect(w.vm.canSubmit).toBe(true);

        // Case C : item_id non-integer (float) → false.
        w.vm.cartItems = [{ item_id: 1.5, quantity: 1 }];
        await flushPromises();
        expect(w.vm.canSubmit).toBe(false);

        // Case D : quantity 0 → false.
        w.vm.cartItems = [{ item_id: 42, quantity: 0 }];
        await flushPromises();
        expect(w.vm.canSubmit).toBe(false);

        // Case E : item_id 0 (not > 0) → false.
        w.vm.cartItems = [{ item_id: 0, quantity: 1 }];
        await flushPromises();
        expect(w.vm.canSubmit).toBe(false);
    });

    // -----------------------------------------------------------------------
    // Test 5 — runPreview POST emission with correct body
    // -----------------------------------------------------------------------
    it('runPreview() POSTs admin/upsell-preview with cart_items + branch_id + strategy', async () => {
        const { w } = mountPage({ branchLists: [{ id: 3, name: 'Nice' }] });
        await flushPromises();

        // Set valid cart with one line + select strategy.
        w.vm.cartItems = [
            { item_id: 42, quantity: 2 },
            { item_id: 9, quantity: 1 },
        ];
        w.vm.selectedStrategy = 'ml_placeholder';
        await flushPromises();

        // Trigger the click.
        await w.find('.db-btn-primary').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, body] = axios.post.mock.calls[0];
        // Endpoint is relative — `admin/upsell-preview` (NO `/api/` prefix).
        expect(url).toBe('admin/upsell-preview');
        expect(body).toEqual({
            cart_items: [
                { item_id: 42, quantity: 2 },
                { item_id: 9, quantity: 1 },
            ],
            branch_id: 3,
            strategy: 'ml_placeholder',
        });
    });

    it('runPreview() filters out invalid lines from payload', async () => {
        const { w } = mountPage({ branchLists: [{ id: 1, name: 'HQ' }] });
        await flushPromises();

        // Mix : 1 valid + 1 invalid (null item_id) + 1 valid.
        w.vm.cartItems = [
            { item_id: 42, quantity: 2 },
            { item_id: null, quantity: 1 },
            { item_id: 9, quantity: 3 },
        ];
        await flushPromises();

        await w.find('.db-btn-primary').trigger('click');
        await flushPromises();

        const [, body] = axios.post.mock.calls[0];
        // Only the 2 valid lines should be sent.
        expect(body.cart_items).toEqual([
            { item_id: 42, quantity: 2 },
            { item_id: 9, quantity: 3 },
        ]);
    });

    // -----------------------------------------------------------------------
    // Test 6 — Result render after success
    // -----------------------------------------------------------------------
    it('renders recommendations + latency + cart_size pills after success', async () => {
        const { w } = mountPage();
        await flushPromises();

        w.vm.cartItems = [{ item_id: 5, quantity: 1 }];
        await flushPromises();

        await w.find('.db-btn-primary').trigger('click');
        await flushPromises();

        // Result panel rendered.
        expect(w.find('.upsell-preview-result').exists()).toBe(true);

        // Meta pills (3 items : strategy + latency + cart_size).
        const pills = w.findAll('.upsell-preview-meta-pill');
        expect(pills).toHaveLength(3);
        const text = pills.map(p => p.text()).join(' ');
        expect(text).toContain('rule_based');
        expect(text).toContain('12');     // latency_ms
        expect(text).toContain('1');      // cart_size

        // Recommendation list — 2 items.
        const items = w.findAll('.upsell-preview-list-item');
        expect(items).toHaveLength(2);
        expect(items[0].text()).toContain('#7');
        expect(items[0].text()).toContain('Fries M');
        expect(items[0].text()).toContain('side_for_burger');
        expect(items[1].text()).toContain('Coke');
    });

    it('renders empty-state placeholder when recommendations array is empty', async () => {
        axios.post.mockResolvedValue({
            data: {
                strategy_used: 'rule_based',
                latency_ms: 5,
                cart_size: 1,
                recommendations: [],
            },
        });

        const { w } = mountPage();
        await flushPromises();

        w.vm.cartItems = [{ item_id: 1, quantity: 1 }];
        await flushPromises();
        await w.find('.db-btn-primary').trigger('click');
        await flushPromises();

        expect(w.find('.upsell-preview-empty').exists()).toBe(true);
        expect(w.findAll('.upsell-preview-list-item')).toHaveLength(0);
    });

    // -----------------------------------------------------------------------
    // Test 7 — Error path (alertService.error called, NOT a DOM error node)
    // -----------------------------------------------------------------------
    it('calls alertService.error with backend message on 422 failure', async () => {
        axios.post.mockRejectedValue({
            response: { status: 422, data: { message: 'Cart validation failed' } },
        });

        const { w } = mountPage();
        await flushPromises();

        w.vm.cartItems = [{ item_id: 99, quantity: 1 }];
        await flushPromises();

        await w.find('.db-btn-primary').trigger('click');
        await flushPromises();

        expect(alertService.error).toHaveBeenCalledTimes(1);
        expect(alertService.error).toHaveBeenCalledWith('Cart validation failed');
        // No result rendered (lastResult stays null).
        expect(w.find('.upsell-preview-result').exists()).toBe(false);
    });

    it('falls back to i18n key when 500 has no message body', async () => {
        axios.post.mockRejectedValue({ response: { status: 500, data: {} } });

        const { w } = mountPage();
        await flushPromises();

        w.vm.cartItems = [{ item_id: 99, quantity: 1 }];
        await flushPromises();

        await w.find('.db-btn-primary').trigger('click');
        await flushPromises();

        expect(alertService.error).toHaveBeenCalledTimes(1);
        // Fallback uses $t('kiosk.admin.upsell_preview_error') which the test stub
        // returns as-is (mocks.$t = (k) => k).
        expect(alertService.error).toHaveBeenCalledWith('kiosk.admin.upsell_preview_error');
    });
});
