/**
 * kioskMenu.js — Vuex store module for kiosk menu data.
 *
 * Inspired by Splash borne-windows kioskMenu.js:
 *   - Fetches categories + items in parallel (single load)
 *   - 5-minute TTL cache to avoid redundant API calls
 *   - Auto-selects first real category on load
 *   - Exposes itemsByCategory getter for filtered display
 */
import axios from 'axios';

const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes

export const kioskMenu = {
    namespaced: true,

    state: {
        categories:         [],
        items:              [],
        selectedCategoryId: null,
        loading:            false,
        lastFetchedAt:      null,
        branchId:           null,
    },

    getters: {
        categories:         (s) => s.categories,
        allItems:           (s) => s.items,
        selectedCategoryId: (s) => s.selectedCategoryId,
        loading:            (s) => s.loading,
        isStale:            (s) => !s.lastFetchedAt || (Date.now() - s.lastFetchedAt) > CACHE_TTL_MS,

        itemsByCategory: (s) => (categoryId) => {
            if (!categoryId) return s.items;
            // [GAP-26-1] Normalize both sides to int to avoid string vs number mismatch
            // API may return item_category_id as string; route params are always strings
            const id = parseInt(categoryId, 10);
            return s.items.filter(i => {
                const catId = parseInt(i.item_category_id ?? i.category_id, 10);
                return catId === id;
            });
        },

        selectedItems: (s, g) => g.itemsByCategory(s.selectedCategoryId),
    },

    mutations: {
        SET_CATEGORIES(state, categories) {
            state.categories = categories;
        },
        SET_ITEMS(state, items) {
            state.items = items;
            state.lastFetchedAt = Date.now();
        },
        SET_SELECTED_CATEGORY(state, id) {
            state.selectedCategoryId = id;
        },
        SET_LOADING(state, val) {
            state.loading = val;
        },
        SET_BRANCH(state, branchId) {
            state.branchId = branchId;
        },
        INVALIDATE_CACHE(state) {
            state.lastFetchedAt = null;
        },
    },

    actions: {
        /**
         * Load the full menu (categories + items) for the kiosk.
         * Uses cache: skips network call if data is fresh (< 5min).
         * @param {boolean} [force=false] — bypass cache
         */
        async fetchMenu({ commit, state, getters }, { force = false, branchId = null } = {}) {
            // Use cache unless forced or expired
            if (!force && !getters.isStale && state.items.length > 0) {
                return;
            }

            commit('SET_LOADING', true);

            const resolvedBranchId = branchId || state.branchId;
            const branchParam = resolvedBranchId ? `&branch_id=${resolvedBranchId}` : '';

            try {
                const [catRes, itemRes] = await Promise.all([
                    axios.get(`frontend/item-category?paginate=0&status=5&surface=kiosk${branchParam}`),
                    axios.get(`frontend/item?paginate=0&status=5&surface=kiosk${branchParam}`),
                ]);

                const categories = catRes.data?.data || [];
                const items      = itemRes.data?.data || [];

                commit('SET_CATEGORIES', categories);
                commit('SET_ITEMS', items);

                // Auto-select first real category (skip "all" category if id === 0)
                if (categories.length > 0 && !state.selectedCategoryId) {
                    const first = categories.find(c => c.id && c.id !== 0) || categories[0];
                    if (first) commit('SET_SELECTED_CATEGORY', first.id);
                }
            } finally {
                commit('SET_LOADING', false);
            }
        },

        selectCategory({ commit }, categoryId) {
            commit('SET_SELECTED_CATEGORY', categoryId);
        },

        setBranch({ commit, dispatch }, branchId) {
            commit('SET_BRANCH', branchId);
            // Invalidate cache when branch changes
            commit('INVALIDATE_CACHE');
            dispatch('fetchMenu', { force: true, branchId });
        },

        invalidateCache({ commit }) {
            commit('INVALIDATE_CACHE');
        },
    },
};
