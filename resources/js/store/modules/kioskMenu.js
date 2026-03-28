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
import { saveSnapshot, loadSnapshot, isSnapshotFresh } from '../../helpers/kioskMenuCache';

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
        fromCache:          false, // true when menu was loaded from offline snapshot
    },

    getters: {
        categories:         (s) => s.categories,
        allItems:           (s) => s.items,
        selectedCategoryId: (s) => s.selectedCategoryId,
        loading:            (s) => s.loading,
        fromCache:          (s) => s.fromCache,
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
            state.fromCache = false;
        },
        SET_FROM_CACHE(state, { categories, items }) {
            state.categories = categories;
            state.items = items;
            state.fromCache = true;
            // Do not update lastFetchedAt — keeps isStale=true so next online fetch replaces snapshot
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

        /**
         * [C3] Partial update from ItemAvailabilityChanged broadcast.
         * type='status' → update status in-place (fast, no refetch).
         * type='full'   → invalidate cache so next navigation triggers refetch.
         */
        UPDATE_ITEM(state, { item_id, status, price, type }) {
            if (type === 'full') {
                // Price or variations changed — force full refetch on next access
                state.lastFetchedAt = null;
                return;
            }
            const idx = state.items.findIndex(i => parseInt(i.id) === parseInt(item_id));
            if (idx !== -1) {
                state.items[idx] = {
                    ...state.items[idx],
                    status,
                    price,
                };
            }
        },
    },

    actions: {
        /**
         * Load the full menu (categories + items) for the kiosk.
         * Uses cache: skips network call if data is fresh (< 5min).
         * @param {boolean} [force=false] — bypass cache
         */
        async fetchMenu({ commit, state, getters }, { force = false, branchId = null } = {}) {
            // Use in-memory cache unless forced or expired
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

                // [C2] Persist fresh data to IndexedDB for offline fallback
                saveSnapshot(categories, items).catch(() => {});

                // Auto-select first real category (skip "all" category if id === 0)
                if (categories.length > 0 && !state.selectedCategoryId) {
                    const first = categories.find(c => c.id && c.id !== 0) || categories[0];
                    if (first) commit('SET_SELECTED_CATEGORY', first.id);
                }
            } catch (err) {
                // [C2] Network failure — try to load offline snapshot
                const snapshot = await loadSnapshot().catch(() => null);
                if (snapshot && isSnapshotFresh(snapshot.savedAt)) {
                    commit('SET_FROM_CACHE', { categories: snapshot.categories, items: snapshot.items });
                    if (snapshot.categories.length > 0 && !state.selectedCategoryId) {
                        const first = snapshot.categories.find(c => c.id && c.id !== 0) || snapshot.categories[0];
                        if (first) commit('SET_SELECTED_CATEGORY', first.id);
                    }
                } else {
                    // No usable snapshot — re-throw so caller can show error
                    throw err;
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
