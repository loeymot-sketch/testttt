/**
 * KDS allergens helpers (Lot 2.I / G-4 + G-5).
 *
 * Pure functions intentionally extracted from KitchenDisplaySystemComponent
 * so they can be unit-tested without mounting the heavyweight component.
 *
 * The backend (KitchenDisplaySystemOrderService::normalizeAllergensForHash)
 * applies the SAME normalization rules so that:
 *   - null and [] hash identically (no-allergens lines merge)
 *   - duplicate / unsorted entries merge ([gluten, peanuts] vs [peanuts, gluten])
 *   - non-array shapes degrade to []
 * Keep both sides in sync if you change one.
 */

/**
 * @param {*} order
 * @returns {boolean}
 */
export function orderHasAllergens(order) {
    if (!order) return false;
    const items = order.orderItems || order.order_items;
    if (!Array.isArray(items)) return false;
    for (let i = 0; i < items.length; i++) {
        const snapshot = items[i] && items[i].allergens_snapshot;
        if (Array.isArray(snapshot) && snapshot.length > 0) {
            return true;
        }
    }
    return false;
}

/**
 * Deterministic display order: filter empty/null, dedupe, alphabetical sort.
 * @param {*} snapshot
 * @returns {string[]}
 */
export function sortedAllergens(snapshot) {
    if (!Array.isArray(snapshot)) return [];
    const cleaned = snapshot
        .filter((v) => v !== null && v !== undefined && v !== '')
        .map((v) => String(v));
    return Array.from(new Set(cleaned)).sort();
}

/**
 * Mirror of the backend `normalizeAllergensForHash` shape (without the sha1).
 * Useful for client-side parity checks in tests.
 * @param {*} snapshot
 * @returns {string[]}
 */
export function normalizeAllergensForHash(snapshot) {
    return sortedAllergens(snapshot);
}
