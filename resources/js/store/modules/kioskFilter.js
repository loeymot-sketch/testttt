import { KIOSK_FILTERS } from '../../helpers/kioskFilters';

const STORAGE_KEY_FILTERS = 'kiosk:filters';
const STORAGE_KEY_ALLERGENS = 'kiosk:customer_allergens';

function sanitizeFilterIds(raw) {
    if (!Array.isArray(raw)) return [];
    return raw.filter((id) => KIOSK_FILTERS.includes(id));
}

export default {
    namespaced: true,
    state() {
        return {
            activeFilters: [],
            customerAllergens: [],
            hydrated: false,
        };
    },
    getters: {
        activeFilters: (s) => s.activeFilters,
        customerAllergens: (s) => s.customerAllergens,
        hasActiveFilters: (s) => s.activeFilters.length > 0 || s.customerAllergens.length > 0,
        hydrated: (s) => s.hydrated,
    },
    mutations: {
        SET_FILTERS(state, filters) {
            state.activeFilters = Array.isArray(filters) ? [...filters] : [];
        },
        TOGGLE_FILTER(state, filterId) {
            const idx = state.activeFilters.indexOf(filterId);
            if (idx >= 0) state.activeFilters.splice(idx, 1);
            else state.activeFilters.push(filterId);
        },
        SET_CUSTOMER_ALLERGENS(state, allergens) {
            state.customerAllergens = Array.isArray(allergens) ? [...allergens] : [];
        },
        RESET(state) {
            state.activeFilters = [];
            state.customerAllergens = [];
        },
        SET_HYDRATED(state, value) {
            state.hydrated = !!value;
        },
    },
    actions: {
        init({ commit }) {
            try {
                const raw = window.localStorage.getItem(STORAGE_KEY_FILTERS);
                const filters = raw ? JSON.parse(raw) : [];
                commit('SET_FILTERS', sanitizeFilterIds(Array.isArray(filters) ? filters : []));

                const rawAllergens = window.localStorage.getItem(STORAGE_KEY_ALLERGENS);
                const allergens = rawAllergens ? JSON.parse(rawAllergens) : [];
                commit('SET_CUSTOMER_ALLERGENS', Array.isArray(allergens) ? allergens : []);
            } catch (e) {
                commit('SET_FILTERS', []);
                commit('SET_CUSTOMER_ALLERGENS', []);
            }
            commit('SET_HYDRATED', true);
        },
        toggle({ commit, state }, filterId) {
            if (!KIOSK_FILTERS.includes(filterId)) return;
            commit('TOGGLE_FILTER', filterId);
            try {
                window.localStorage.setItem(STORAGE_KEY_FILTERS, JSON.stringify(state.activeFilters));
            } catch (_) { /* private mode */ }
        },
        setCustomerAllergens({ commit, state }, allergens) {
            commit('SET_CUSTOMER_ALLERGENS', allergens);
            try {
                window.localStorage.setItem(STORAGE_KEY_ALLERGENS, JSON.stringify(state.customerAllergens));
            } catch (_) { /* private mode */ }
        },
        reset({ commit }) {
            commit('RESET');
            try {
                window.localStorage.removeItem(STORAGE_KEY_FILTERS);
                window.localStorage.removeItem(STORAGE_KEY_ALLERGENS);
            } catch (_) { /* private mode */ }
        },
    },
};
