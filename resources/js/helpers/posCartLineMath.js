/**
 * Calculs partagés panier POS : ligne principale + pos_line_addons.
 * Une seule source de vérité pour éviter doublons / dérives entre store, caisse et checkout.
 */

export function parsePositiveInt(value, fallback) {
    const n = parseInt(value, 10);
    return n > 0 ? n : fallback;
}

/** Unité « principal » : convert + variations + extras (hors qty panier) */
export function rowUnitMain(row) {
    if (!row) return 0;
    return (
        (parseFloat(row.convert_price) || 0) +
        (parseFloat(row.item_variation_total) || 0) +
        (parseFloat(row.item_extra_total) || 0)
    );
}

/** Unité addon regroupé (même formule que la ligne commande addon) */
export function rowUnitBundled(row) {
    return rowUnitMain(row);
}

/** Total affiché / subtotal pour une entrée lists[] (principal × qty + Σ addons × qty_addon × qty_parent) */
export function computePosCartLineDisplayTotal(list) {
    if (!list) return 0;
    const qParent = parsePositiveInt(list.quantity, 1);
    let total = rowUnitMain(list) * qParent;
    const addons = Array.isArray(list.pos_line_addons) ? list.pos_line_addons : [];
    addons.forEach((a) => {
        const qAddon = parsePositiveInt(a.quantity, 1);
        total += rowUnitBundled(a) * qAddon * qParent;
    });
    return total;
}

/** Ligne principale pour JSON commande (quantité = qty article parent) */
export function mainOrderLineTotal(item, mainQty) {
    const q = parsePositiveInt(mainQty, 1);
    return rowUnitMain(item) * q;
}

/** Quantité et total commande pour un addon regroupé */
export function bundledOrderQuantityAndTotal(bundled, parentQty) {
    const qParent = parsePositiveInt(parentQty, 1);
    const perParent = parsePositiveInt(bundled.quantity, 1);
    const orderQty = perParent * qParent;
    const lineTotal = rowUnitBundled(bundled) * perParent * qParent;
    return { orderQty, lineTotal };
}
