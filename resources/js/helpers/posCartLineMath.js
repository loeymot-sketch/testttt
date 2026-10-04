/**
 * Calculs partagés panier POS : ligne principale + pos_line_addons.
 * Une seule source de vérité pour éviter doublons / dérives entre store, caisse et checkout.
 */

export function parsePositiveInt(value, fallback) {
    const n = parseInt(value, 10);
    return n > 0 ? n : fallback;
}

/**
 * [GOAL CAISSE/CUISINE #5 2026-10-02] Valeur des extras OFFERTS d'une ligne (par unité d'article).
 * AFFICHAGE seulement : `unit_price` vient du catalogue chargé par le wizard, il n'est JAMAIS envoyé
 * au backend (PricingService reste l'autorité — l'offert part comme ids dans `item_extras_offered`).
 */
export function offeredValue(row) {
    const offered = row && Array.isArray(row.item_extras_offered) ? row.item_extras_offered : [];
    return offered.reduce((sum, e) => sum + (parseFloat(e && e.unit_price) || 0) * (parseInt(e && e.quantity, 10) || 0), 0);
}

/** Unité « principal » : convert + variations + extras (hors qty panier), moins l'offert */
export function rowUnitMain(row) {
    if (!row) return 0;
    if (row.line_type === 'manual_supplement') {
        return parseFloat(row.manual_amount) || 0;
    }
    return Math.max(
        0,
        (parseFloat(row.convert_price) || 0) +
        (parseFloat(row.item_variation_total) || 0) +
        (parseFloat(row.item_extra_total) || 0) -
        offeredValue(row)
    );
}

/** Unité addon regroupé (menu, etc.) — prix AUTORITATIF affiché au panier.
 *
 * [FIX 2026-07-04 owner] Le menu s'affichait au panier à
 * `convert_price + item_variation_total + item_extra_total` (rowUnitMain, ex. 3,00 €)
 * alors que le prix RÉEL — celui soumis, facturé et imprimé sur le ticket (= base de
 * données) — est `total_price` (= total_convert_price du wizard, ex. 2,50 €). L'écart
 * venait d'une VARIATION menu (ex. taille de frites) comptée à l'AFFICHAGE mais NON
 * facturée par le backend (option incluse dans le prix menu). On renvoie donc la valeur
 * AUTORITATIVE `total_price` → panier == wizard == ticket == base. Fallback sur la somme
 * des composants si `total_price` est absent (addons hérités / robustesse).
 */
export function rowUnitBundled(row) {
    if (!row) return 0;
    if (row.total_price !== undefined && row.total_price !== null && row.total_price !== '') {
        const authoritative = parseFloat(row.total_price);
        if (Number.isFinite(authoritative)) {
            // [GOAL REMARQUES 2026-10-03 · R-041] Une option de formule OFFERTE (Grande Portion…) est
            // facturée 0 par le serveur : le panier la retire aussi, sinon affiché ≠ facturé.
            return Math.max(0, authoritative - offeredValue(row));
        }
    }
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
