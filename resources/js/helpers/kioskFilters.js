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
    const set = new Set((customerAllergens || []).map((c) => String(c).toLowerCase()));
    return itemCodes.filter((c) => set.has(c));
}

/**
 * Extrait la liste des codes allergènes d'un item peu importe la forme :
 *  - allergens: [{ code: 'lait' }, ...] (pivot API — codes FR UE)
 *  - allergens: ['lait', 'gluten']     (shortcut)
 *  - allergen_flags: {'lait': true}    (legacy)
 *
 * @param {Object} item
 * @returns {Array<string>}
 */
export function extractAllergenCodes(item) {
    if (!item) return [];
    const allergens = item.allergens;
    if (typeof allergens === 'string') {
        return allergens
            .split(/[,;|]/)
            .map((s) => s.trim().toLowerCase())
            .filter(Boolean);
    }
    if (Array.isArray(allergens)) {
        return allergens
            .map((a) => {
                if (typeof a === 'string') return a.trim().toLowerCase();
                if (a && typeof a === 'object') {
                    if (typeof a.code === 'string') return a.code.trim().toLowerCase();
                    if (typeof a.name === 'string') return a.name.trim().toLowerCase();
                }
                return null;
            })
            .filter((v) => !!v);
    }
    if (item.allergen_flags && typeof item.allergen_flags === 'object') {
        return Object.keys(item.allergen_flags)
            .filter((k) => item.allergen_flags[k])
            .map((k) => k.toLowerCase());
    }
    return [];
}

/**
 * Ordre canonique UE 1169/2011 (codes FR) pour affichage / comparaison stable.
 * Aligné sur les clés consommées par KsAllergenBadge / API.
 */
export const KIOSK_ALLERGEN_CANONICAL_ORDER = Object.freeze([
    'gluten',
    'lait',
    'oeufs',
    'poissons',
    'crustaces',
    'mollusques',
    'fruits_a_coque',
    'arachides',
    'soja',
    'celeri',
    'moutarde',
    'sesame',
    'sulfites',
    'lupin',
    'anhydride_sulfureux',
]);

function sortAllergenCodesUnique(codes) {
    const uniq = [...new Set(codes)];
    const idx = (c) => {
        const i = KIOSK_ALLERGEN_CANONICAL_ORDER.indexOf(c);
        return i === -1 ? 1000 : i;
    };
    return uniq.sort((a, b) => {
        const ia = idx(a);
        const ib = idx(b);
        if (ia !== ib) return ia - ib;
        return String(a).localeCompare(String(b));
    });
}

/**
 * Fusionne allergènes de l'item de base + variations + extras sélectionnés.
 * Objets sans `allergens` → no-op (extractAllergenCodes → []).
 *
 * Si `selectedVariations` est `undefined` ou `null` (clé absente / JSON null),
 * seuls les allergènes de l'item de base sont retournés (rétrocompat).
 *
 * @param {Object|null} item
 * @param {Array|undefined} selectedVariations
 * @param {Array|undefined} selectedExtras
 * @returns {Array<string>}
 */
export function mergeAllergens(item, selectedVariations, selectedExtras) {
    if (selectedVariations === undefined || selectedVariations === null) {
        return sortAllergenCodesUnique(extractAllergenCodes(item));
    }
    const vars = Array.isArray(selectedVariations) ? selectedVariations : [];
    const extras = Array.isArray(selectedExtras) ? selectedExtras : [];
    const out = [];
    out.push(...extractAllergenCodes(item));
    vars.forEach((v) => {
        out.push(...extractAllergenCodes(v && typeof v === 'object' ? v : {}));
    });
    extras.forEach((e) => {
        out.push(...extractAllergenCodes(e && typeof e === 'object' ? e : {}));
    });
    return sortAllergenCodesUnique(out);
}

/**
 * Résout un objet variation catalogue par id (recherche dans `item.variations`).
 */
export function findVariationObjectById(item, variationId) {
    if (item == null || variationId == null || !item.variations || typeof item.variations !== 'object') {
        return null;
    }
    const vid = parseInt(String(variationId), 10);
    if (Number.isNaN(vid)) return null;
    const keys = Object.keys(item.variations);
    for (let i = 0; i < keys.length; i++) {
        const list = item.variations[keys[i]];
        if (!Array.isArray(list)) continue;
        const found = list.find((v) => v && v.id === vid);
        if (found) return found;
    }
    return null;
}

/**
 * Résout un extra catalogue par id.
 */
export function findExtraObjectById(item, extraId) {
    if (item == null || extraId == null || !Array.isArray(item.extras)) return null;
    const eid = parseInt(String(extraId), 10);
    if (Number.isNaN(eid)) return null;
    return item.extras.find((e) => e && e.id === eid) || null;
}

const UNDER_10_VAR_EUROS = 10;

function getVariationPriceEuros(variation) {
    if (variation == null) return null;
    if (typeof variation.base_price_cents === 'number') {
        return variation.base_price_cents / 100;
    }
    if (typeof variation.convert_price !== 'undefined' && variation.convert_price !== null) {
        const p = parseFloat(variation.convert_price);
        if (!Number.isNaN(p)) return p;
    }
    if (typeof variation.price !== 'undefined' && variation.price !== null) {
        const p = parseFloat(variation.price);
        if (!Number.isNaN(p)) return p;
    }
    return null;
}

/**
 * Filtres catalogue sur une variation / extra (wizard). Tolérant si les
 * drapeaux API manquent (undefined) — ne bloque que lorsque le champ contredit
 * explicitement le filtre actif.
 *
 * @param {Object|null|undefined} variation
 * @param {Array<string>} activeFilters
 * @returns {boolean}
 */
export function isVariationAllowedByFilters(variation, activeFilters = []) {
    if (!Array.isArray(activeFilters) || activeFilters.length === 0) return true;
    if (!variation) return true;
    const filters = activeFilters.filter((f) => KIOSK_FILTERS.includes(f));
    if (filters.length === 0) return true;

    for (const filterId of filters) {
        if (filterId === 'vegetarian' && variation.is_vegetarian === false) return false;
        if (filterId === 'halal' && variation.is_halal === false) return false;
        if (filterId === 'pork_free' && variation.is_pork_free === false) return false;
        if (filterId === 'spicy' && variation.is_spicy === false) return false;
        if (filterId === 'gluten_free') {
            if (variation.is_gluten_free === false) return false;
            const allergens = Array.isArray(variation.allergens)
                ? variation.allergens.map((a) =>
                    (typeof a === 'string' ? a : a?.code || a?.name || '').toLowerCase(),
                )
                : [];
            if (allergens.some((a) => a.includes('gluten'))) return false;
        }
        if (filterId === 'under_10') {
            const p = getVariationPriceEuros(variation);
            if (p !== null && p >= UNDER_10_VAR_EUROS) return false;
        }
    }
    return true;
}

export default {
    KIOSK_FILTERS,
    applyKioskFilters,
    getAllergenCollision,
    extractAllergenCodes,
    mergeAllergens,
    KIOSK_ALLERGEN_CANONICAL_ORDER,
    findVariationObjectById,
    findExtraObjectById,
    isVariationAllowedByFilters,
};
