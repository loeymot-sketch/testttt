/**
 * [GOAL CAISSE/CUISINE #6 2026-10-02] Facturation des options de FORMULE affichées en caisse.
 *
 * Le wizard caisse (gelé) affiche des options PAYANTES sur une formule frites :
 *   - « Grande Portion »  +1,00 €
 *   - « Cheddar Fondu »   +1,00 €
 *   - 2ᵉ sauce frites et suivantes : +0,50 € chacune
 * mais ne les transmet que comme TEXTE (`menu_extras`, instruction). La ligne addon partait avec
 * `item_extras: []` : le backend (PricingService, SSOT) facture tout id reçu — il n'en recevait
 * aucun. Affiché ≠ facturé.
 *
 * Ce module transforme les choix du wizard en IDS d'extras réels. Le client n'envoie JAMAIS de
 * prix : seulement des identifiants que PricingService résout et facture lui-même.
 */
import { normalizeId } from './posNormalizeIds';

const norm = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim();

/** Extras de formule à facturer pour une ligne addon, d'après les choix du wizard (menu_restore). */
export function formulaOptionExtras(menuRestore, catalogAddon, lineItemId) {
    const mr = menuRestore && typeof menuRestore === 'object' ? menuRestore : {};
    const catalog = Array.isArray(catalogAddon && catalogAddon.addon_item_extras) ? catalogAddon.addon_item_extras : [];
    const wanted = [];
    if (mr.fritesGrande) wanted.push('grande portion');
    if (mr.fritesCheddar) wanted.push('cheddar fondu');

    const out = [];
    wanted.forEach((name) => {
        const found = catalog.find((extra) => norm(extra && extra.name) === name);
        const id = found ? normalizeId(found.id) : null;
        if (id !== null) {
            // [GOAL REMARQUES 2026-10-03 · R-041] `unit_price` = prix CATALOGUE, pour l'affichage et pour
            // savoir si l'option est « offrable » (même règle que les extras du produit). Il n'est JAMAIS
            // envoyé : la ligne de commande ne transmet que id / item_id / name / quantity.
            out.push({ id, item_id: lineItemId, name: found.name, quantity: 1, unit_price: Number(found.price) || 0 });
        }
    });
    return out;
}

/** Nombre de sauces frites FACTURÉES : la 1ʳᵉ est offerte, chaque suivante est payante. */
export function extraFritesSauceQuantity(menuRestore) {
    const order = menuRestore && Array.isArray(menuRestore.sauceFritesOrder) ? menuRestore.sauceFritesOrder : [];
    return Math.max(0, order.length - 1);
}

/** Extra générique « Sauce supplémentaire » du produit parent (porte la 2ᵉ sauce et au-delà). */
export function findSauceSupplementExtra(extras) {
    const list = Array.isArray(extras) ? extras : [];
    return list.find((extra) => /sauce\s*suppl/i.test(String((extra && extra.name) || ''))) || null;
}
