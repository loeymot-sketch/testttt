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
import { sortCategoriesForKioskDisplay } from '../../helpers/kioskCategoryOrder';
import { sortKioskItemsForDisplay } from '../../helpers/kioskItemDisplayOrder';
import {
    expandKioskSidebarCategories,
    filterSandwichItemsForKioskColumn,
} from '../../helpers/kioskSandwichSplit';

const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes

function getKioskSandwichSplitConfig() {
    if (typeof window === 'undefined' || !window.foodkingConfig) {
        return null;
    }
    return window.foodkingConfig.kioskSandwichSplit || null;
}

export const kioskMenu = {
    namespaced: true,

    state: {
        categories:         [],
        items:              [],
        selectedCategoryId: null,
        /** null = signatures (Nos Sandwichs) ; 'cold' = sous-colonne virtuelle « Sandwich froid » */
        kioskSandwichSubcolumn: null,
        loading:            false,
        lastFetchedAt:      null,
        branchId:           null,
        fromCache:          false, // true when menu was loaded from offline snapshot
        // Phase 8.5 — Promos actives + flags branche. Alimenté par l'appel
        // best-effort `/api/frontend/menu` (serveur fait le scoping par
        // branch_id via token Sanctum). Tombe silencieusement en cas d'échec.
        promos:             [],
        branchFlags:        { is_rush: false, is_night: false, server_time: null },
    },

    getters: {
        categories:         (s) => s.categories,
        allItems:           (s) => s.items,
        forBranch:          (s) => (branchId) => ({
            branch_id: branchId ?? s.branchId,
            categories: s.categories,
            items: s.items,
        }),
        selectedCategoryId: (s) => s.selectedCategoryId,
        kioskSandwichSubcolumn: (s) => s.kioskSandwichSubcolumn,
        loading:            (s) => s.loading,
        fromCache:          (s) => s.fromCache,
        isStale:            (s) => !s.lastFetchedAt || (Date.now() - s.lastFetchedAt) > CACHE_TTL_MS,

        nosSandwichParentId: (s) => {
            const split = getKioskSandwichSplitConfig();
            const slug = String(split?.parent_category_slug || 'nos-sandwichs').toLowerCase();
            const c = s.categories.find(x => String(x.slug || '').toLowerCase() === slug);
            return c != null ? parseInt(c.id, 10) : null;
        },

        sidebarCategories: (s) => {
            const split = getKioskSandwichSplitConfig();
            const coldSlugs = split?.cold_item_slugs || [];
            // V3.8 (2026-05-10) Owner gate : exclure cat "Frites & Accompagnements"
            // (id 315) de la sidebar kiosk. Owner : "tout accompagnement vient
            // avec le produit lui-même ; cette catégorie crée confusion (un
            // supplément cliqué standalone ne sait à quel produit il s'attache).
            // On la cache du welcome screen mais les items restent au catalog
            // pour POS staff + addons backend (360-403)."
            // [owner-feedback 2026-05-10 round-5] Cats 306/307/308 (sandwich/burger/
            // tacos) restent VISIBLES sur la borne. Le précédent gating était une
            // mis-interprétation de "scape les sandwich, burger et tacos" — l'intent
            // owner était de SAUTER ces cats dans le scope de l'audit test E2E
            // uniquement, pas de les retirer de l'UI.
            // Cat 315 (Frites & Accompagnements) reste masquée — ses items sont
            // des addons d'autres produits, accédés via les steps wizard.
            const KIOSK_HIDDEN_CATEGORY_IDS = new Set([315]);
            const filtered = (s.categories || []).filter((c) =>
                !KIOSK_HIDDEN_CATEGORY_IDS.has(parseInt(c.id, 10))
            );
            return expandKioskSidebarCategories(filtered, {
                parentSlug: split?.parent_category_slug || 'nos-sandwichs',
                coldSlugs,
                coldLabel: split?.cold_sidebar_label || 'Sandwich froid',
            });
        },

        itemsByCategory: (s) => (categoryId) => {
            // [GAP-26-1] Normalize both sides to int to avoid string vs number mismatch
            const id = parseInt(categoryId, 10);
            const filtered = !categoryId
                ? [...s.items]
                : s.items.filter(i => {
                    const catId = parseInt(i.item_category_id ?? i.category_id, 10);
                    return catId === id;
                });
            return sortKioskItemsForDisplay(filtered);
        },

        /** Articles grille catalogue : filtre sous-colonne sandwich sur la borne uniquement */
        kioskCatalogItems: (s, g) => {
            const base = g.itemsByCategory(s.selectedCategoryId);
            const split = getKioskSandwichSplitConfig();
            const coldSlugs = (split?.cold_item_slugs || [])
                .map(x => String(x).toLowerCase().trim())
                .filter(Boolean);
            if (!coldSlugs.length) return base;
            const parentId = g.nosSandwichParentId;
            const selId = parseInt(s.selectedCategoryId, 10);
            if (parentId == null || selId !== parentId) return base;
            const filtered = filterSandwichItemsForKioskColumn(base, s.kioskSandwichSubcolumn, coldSlugs);
            return sortKioskItemsForDisplay(filtered);
        },

        selectedItems: (s, g) => g.kioskCatalogItems,

        // Phase 8.5 — Promos kiosk actives (server-driven). Utilisé par
        // KioskPromoCarouselComponent.
        kioskPromos:  (s) => Array.isArray(s.promos) ? s.promos : [],
        kioskBranchFlags: (s) => s.branchFlags || { is_rush: false, is_night: false },
    },

    mutations: {
        SET_CATEGORIES(state, categories) {
            state.categories = sortCategoriesForKioskDisplay(categories);
            state.kioskSandwichSubcolumn = null;
        },
        SET_ITEMS(state, items) {
            state.items = items;
            state.lastFetchedAt = Date.now();
            state.fromCache = false;
        },
        SET_FROM_CACHE(state, { categories, items }) {
            state.categories = sortCategoriesForKioskDisplay(categories);
            state.items = items;
            state.fromCache = true;
            state.kioskSandwichSubcolumn = null;
            // Do not update lastFetchedAt — keeps isStale=true so next online fetch replaces snapshot
        },
        SET_SELECTED_CATEGORY(state, id) {
            state.selectedCategoryId = id;
        },
        SET_KIOSK_SANDWICH_SUBCOLUMN(state, val) {
            state.kioskSandwichSubcolumn = val;
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
        // Phase 8.5 — promos + flags branche (server-driven).
        SET_PROMOS(state, promos) {
            state.promos = Array.isArray(promos) ? promos : [];
        },
        SET_BRANCH_FLAGS(state, flags) {
            state.branchFlags = {
                is_rush: !!flags?.is_rush,
                is_night: !!flags?.is_night,
                server_time: flags?.server_time || null,
            };
        },

        /**
         * Kiosk Phase 9.1.5 — Mutation partielle depuis le broadcast
         * `ItemAvailabilityChanged` (Echo channel `branch.{branchId}`) ou depuis
         * un 409 Conflict rencontré mid-wizard (wiring côté composant à venir
         * P9.3.11). Patch l'item en place sans refetch réseau pour une
         * réactivité < 1 s.
         *
         * Payload attendu :
         *   { item_id, status?, price?, type?, is_available?, unavailable_reason? }
         *
         * `type='full'` → flags uniquement un refetch global (prix/variation
         * changés côté admin), l'item en mémoire reste tel quel en attendant
         * la nouvelle requête /menu.
         *
         * `is_available=false` → le wizard et la catalog doivent griser l'item
         * ; `unavailable_reason` (ex. `stock_rupture`, `admin_86`) est affiché
         * en message clair. Quand `is_available` n'est pas fourni (event
         * historique), on déduit : status===0 ou 2 → false, sinon true.
         *
         * Invariants :
         *   - Merge non-destructif : les champs absents du payload ne sont
         *     JAMAIS écrasés (pas de "hidden reset" des allergens / catégorie).
         *   - Aucune écriture de prix client : si le serveur pousse un price,
         *     on l'accepte uniquement comme hint d'affichage — la SSOT reste
         *     l'API /pricing/preview avant commande.
         */
        UPDATE_ITEM(state, payload) {
            if (!payload || typeof payload !== 'object') return;

            const { item_id, type } = payload;
            if (type === 'full') {
                state.lastFetchedAt = null;
                return;
            }

            const idx = state.items.findIndex(i => parseInt(i.id) === parseInt(item_id));
            if (idx === -1) return;

            const current = state.items[idx];
            const patch = {};

            if (Object.prototype.hasOwnProperty.call(payload, 'status')) {
                patch.status = payload.status;
            }
            if (Object.prototype.hasOwnProperty.call(payload, 'price')) {
                patch.price = payload.price;
            }

            if (Object.prototype.hasOwnProperty.call(payload, 'is_available')) {
                patch.is_available = !!payload.is_available;
            } else if (Object.prototype.hasOwnProperty.call(payload, 'status')) {
                // Rétrocompat broadcasts anciens (status sans is_available).
                patch.is_available = payload.status !== 0 && payload.status !== 2;
            }

            if (Object.prototype.hasOwnProperty.call(payload, 'unavailable_reason')) {
                patch.unavailable_reason = payload.unavailable_reason;
            } else if (Object.prototype.hasOwnProperty.call(payload, 'reason')) {
                // Broadcast `ItemAvailabilityChanged::forBranch` utilise `reason`.
                patch.unavailable_reason = payload.reason;
            }

            // Si l'item redevient disponible, on nettoie la raison précédente
            // pour éviter qu'un ancien "stock_rupture" s'affiche encore.
            if (patch.is_available === true) {
                patch.unavailable_reason = null;
            }

            state.items[idx] = { ...current, ...patch };
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
                try {
                    const menuRes = await axios.get('frontend/menu');
                    const data = menuRes?.data?.data || {};
                    const categories = Array.isArray(data.categories) ? data.categories : [];
                    const items = Array.isArray(data.items) ? data.items : [];

                    commit('SET_CATEGORIES', categories);
                    commit('SET_ITEMS', items);
                    commit('SET_PROMOS', data.promos || []);
                    commit('SET_BRANCH_FLAGS', data.branch || {});

                    saveSnapshot(state.categories, state.items).catch(() => {});

                    if (categories.length > 0 && !state.selectedCategoryId) {
                        const first = categories.find(c => c.id && c.id !== 0) || categories[0];
                        if (first) commit('SET_SELECTED_CATEGORY', first.id);
                    }

                    return;
                } catch (_) {
                    // Legacy fallback for unauthenticated web/table contexts. Kiosk machines
                    // should normally use /frontend/menu because it resolves branch_id from
                    // KioskMachine and includes item_branch_availability.
                }

                const [catRes, itemRes] = await Promise.all([
                    axios.get(
                        `frontend/item-category?paginate=0&status=5&surface=kiosk&order_column=sort&order_type=asc${branchParam}`
                    ),
                    axios.get(`frontend/item?paginate=0&status=5&surface=kiosk${branchParam}`),
                ]);

                const categories = catRes.data?.data || [];
                const items      = itemRes.data?.data || [];

                commit('SET_CATEGORIES', categories);
                commit('SET_ITEMS', items);

                // [C2] Persist fresh data — même ordre sidebar que le state (tri kiosk)
                saveSnapshot(state.categories, state.items).catch(() => {});

                // Auto-select first real category (skip "all" category if id === 0)
                if (categories.length > 0 && !state.selectedCategoryId) {
                    const first = categories.find(c => c.id && c.id !== 0) || categories[0];
                    if (first) commit('SET_SELECTED_CATEGORY', first.id);
                }

                // Phase 8.5 — fetch best-effort `/api/frontend/menu` pour
                // récupérer promos + branch flags (server-driven). Sans ability
                // kiosk:order (ex: un utilisateur staff POS), on récupère 403
                // et on continue silencieusement.
                try {
                    const menuRes = await axios.get('frontend/menu');
                    const data = menuRes?.data?.data || {};
                    commit('SET_PROMOS', data.promos || []);
                    commit('SET_BRANCH_FLAGS', data.branch || {});
                } catch (_) { /* non-bloquant */ }
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
            commit('SET_KIOSK_SANDWICH_SUBCOLUMN', null);
        },

        selectKioskCategory({ commit }, { categoryId, sandwichSubcolumn = null }) {
            commit('SET_SELECTED_CATEGORY', categoryId);
            commit('SET_KIOSK_SANDWICH_SUBCOLUMN', sandwichSubcolumn);
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
