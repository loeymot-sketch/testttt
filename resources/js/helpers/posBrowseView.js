/**
 * [POS-CATEGORY-FIRST 2026-06-23 /goal] POS "category-first" browse-view helpers.
 *
 * Owner direction (/goal): the cashier's FIRST POS screen must show the grid of
 * categories — not the full product list. Picking a category drills into that
 * category's products; a back control (and an auto-return after each cart line)
 * brings the cashier back to the category grid.
 *
 * These are pure functions so the view decision stays independently
 * unit-testable without mounting PosComponent.vue (Vuex / Router / Swiper /
 * 50+ imports). See tests/js/posBrowseView.spec.js. Pattern mirror of
 * resources/js/helpers/posLoyaltyMainCta.js.
 *
 * NB: this changes only WHICH region renders (category grid vs product grid).
 * The pricing SSOT, the catalog getters and the category-filter logic in
 * PosComponent are untouched.
 */

/**
 * Is `categoryId` a real, drilled-in category (id > 0) — i.e. NOT the landing
 * sentinel ('' / null / undefined / 0 / '0')?
 */
function hasRealCategory(categoryId) {
    if (categoryId === '' || categoryId === null || typeof categoryId === 'undefined') {
        return false;
    }
    return Number(categoryId) > 0;
}

/**
 * Decide which browse region the POS main column should render.
 *
 * @param {{categoryId?: *, searchName?: *}} state — current `props.search`.
 * @returns {'categories'|'products'}
 *   - 'categories' : landing — render the full category grid (the hub).
 *   - 'products'   : a real category is selected OR a search is active —
 *                     render the (category-/search-filtered) product grid.
 */
export function resolvePosBrowseMode(state) {
    const s = state || {};
    const hasSearch = typeof s.searchName === 'string' && s.searchName.trim() !== '';
    return (hasRealCategory(s.categoryId) || hasSearch) ? 'products' : 'categories';
}

/**
 * The real categories rendered as grid tiles on the landing screen.
 * Drops the id=0 "Toutes les catégories" sentinel and any null/garbage entries.
 * Keeps NON-featured categories too — the grid is the full hub, not the
 * pill-strip featured allowlist.
 *
 * @param {Array} categories — `posCategory/lists`.
 * @returns {Array}
 */
export function browseCategoryTiles(categories) {
    if (!Array.isArray(categories)) {
        return [];
    }
    return categories.filter((c) => c && Number(c.id) > 0);
}

/**
 * Resolve the drilled-in category object (for the back-bar header label).
 *
 * @param {Array} categories — `posCategory/lists`.
 * @param {*} categoryId — current `props.search.item_category_id`.
 * @returns {Object|null} the matching category, or null on landing / no match.
 */
export function activeBrowseCategory(categories, categoryId) {
    if (!Array.isArray(categories) || !hasRealCategory(categoryId)) {
        return null;
    }
    const target = Number(categoryId);
    return categories.find((c) => c && Number(c.id) === target) || null;
}

export default { resolvePosBrowseMode, browseCategoryTiles, activeBrowseCategory };
