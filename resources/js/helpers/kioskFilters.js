/**
 * FoodKing Kiosk — Helpers de filtrage produits (DATA_CONTRACT §9.3).
 *
 * Les filtres sont appliqués côté client sur des items déjà renvoyés par
 * le backend (lui seul fait le vrai filtrage par `branch_id` / `is_active` —
 * invariant SSOT). Ils ne modifient jamais la requête réseau.
 *
 * Filtres acceptés :
 *   - 'vegetarian'   → item.is_vegetarian === true
 *   - 'halal'        → item.is_halal === true
 *   - 'pork_free'    → item.is_pork_free === true
 *   - 'gluten_free'  → item.is_gluten_free === true
 *   - 'spicy'        → item.is_spicy === true
 *   - 'under_10'     → prix effectif < 10€ (compare sur convert_price ou price)
 *
 * Les items legacy sans flags (is_* undefined) sont traités comme `false`
 * pour les filtres positifs (vegetarian, halal, …) afin de ne pas fausser
 * les choix client ; la grille affiche alors 0 résultat pour un filtre actif.
 */

export const KIOSK_FILTERS = Object.freeze([
    'vegetarian',
    'halal',
    'pork_free',
    'gluten_free',
    'spicy',
    'under_10',
]);

const UNDER_10_CENTS = 1000;
const UNDER_10_EUROS = 10;

function getItemPriceEuros(item) {
    // Tolérance pour trois formats : convert_price (legacy), price (DB),
    // base_price_cents (DATA_CONTRACT V2). On privilégie toujours le plus fin.
    if (item == null) return null;
    if (typeof item.base_price_cents === 'number') {
        return item.base_price_cents / 100;
    }
    if (typeof item.convert_price !== 'undefined' && item.convert_price !== null) {
        const p = parseFloat(item.convert_price);
        if (!Number.isNaN(p)) return p;
    }
    if (typeof item.price !== 'undefined' && item.price !== null) {
        const p = parseFloat(item.price);
        if (!Number.isNaN(p)) return p;
    }
    return null;
}

function matchFilter(item, filter, customerAllergens = []) {
    if (!item || !filter) return true;
    switch (filter) {
        case 'vegetarian':  return item.is_vegetarian === true;
        case 'halal':       return item.is_halal === true;
        case 'pork_free':   return item.is_pork_free === true;
        case 'gluten_free': return item.is_gluten_free === true;
        case 'spicy':       return item.is_spicy === true;
        case 'under_10': {
            const p = getItemPriceEuros(item);
            if (p === null) return false;
            return p < UNDER_10_EUROS;
        }
        default:
            return true;
    }
}

/**
 * Applique l'ensemble des filtres actifs (AND). Retourne un nouveau tableau.
 * N'applique le filtre d'allergènes client qu'au niveau information (pas de
 * masquage auto — WCAG : l'utilisateur doit voir les infos, c'est le badge
 * KsAllergenBadge qui alerte en rouge lors de collision).
 *
 * @param {Array} items
 * @param {Array<string>} activeFilters
 * @returns {Array}
 */
export function applyKioskFilters(items, activeFilters = []) {
    if (!Array.isArray(items) || items.length === 0) return [];
    const filters = (activeFilters || []).filter((f) => KIOSK_FILTERS.includes(f));
    if (filters.length === 0) return items.slice();
    return items.filter((it) => filters.every((f) => matchFilter(it, f)));
}

/**
 * Collision : retourne les codes allergènes présents dans `item.allergens`
 * ET dans `customerAllergens`. Utilisé pour le badge d'alerte.
 *
 * @param {Object} item
 * @param {Array<string>} customerAllergens
 * @returns {Array<string>}
 */
export function getAllergenCollision(item, customerAllergens = []) {
    const itemCodes = extractAllergenCodes(item);
    const set = new Set(customerAllergens || []);
    return itemCodes.filter((c) => set.has(c));
}

/**
 * Extrait la liste des codes allergènes d'un item peu importe la forme :
 *  - allergens: [{ code: 'milk' }, ...] (pivot API)
 *  - allergens: ['milk', 'gluten']     (shortcut)
 *  - allergen_flags: {'milk': true}    (legacy)
 *
 * @param {Object} item
 * @returns {Array<string>}
 */
export function extractAllergenCodes(item) {
    if (!item) return [];
    if (Array.isArray(item.allergens)) {
        return item.allergens
            .map((a) => {
                if (typeof a === 'string') return a;
                if (a && typeof a === 'object' && typeof a.code === 'string') return a.code;
                return null;
            })
            .filter((v) => !!v);
    }
    if (item.allergen_flags && typeof item.allergen_flags === 'object') {
        return Object.keys(item.allergen_flags).filter((k) => item.allergen_flags[k]);
    }
    return [];
}

export default {
    KIOSK_FILTERS,
    applyKioskFilters,
    getAllergenCollision,
    extractAllergenCodes,
};
