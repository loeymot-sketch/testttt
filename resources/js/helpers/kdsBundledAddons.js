/**
 * [T-KDS-MENU-DOUBLON 2026-08-19 · GOAL owner] Repli des lignes de formule déjà
 * décrites sous leur produit parent, pour l'écran cuisine et le ticket cuisine.
 *
 * LE PROBLÈME
 * -----------
 * Une formule ajoutée à un produit est enregistrée DEUX FOIS :
 *   · dans l'`instruction` du produit parent (« + Menu (Frites + Boisson)
 *     (+2,50 €) / ↳ Sauce frites: Mayonnaise / BOISSON: … ») ;
 *   · comme `order_item` distinct portant le PRIX de la formule.
 * La seconde ligne est indispensable en comptabilité, mais sur l'écran cuisine
 * elle réaffiche une information déjà présente sous le sandwich — c'est le
 * « c'est écrit deux fois la même chose » rapporté par le propriétaire.
 *
 * POURQUOI CE REPLI EST PUREMENT VISUEL
 * ------------------------------------
 * Aucune écriture, aucun impact sur le prix, la TVA, `composition_snapshot` ou la
 * chaîne fiscale : on filtre une liste au moment du rendu.
 *
 * POURQUOI ON SE FIE À L'INSTRUCTION
 * ----------------------------------
 * `order_items` n'a AUCUNE colonne de lien parent (schéma vérifié le 2026-08-19)
 * et `composition_snapshot.addons` du parent est vide. Le seul signal fiable
 * existant est le « + <nom de la formule> » que le wizard écrit lui-même dans
 * l'instruction du parent (public/js/pos-wizard.js, buildTicketInstruction).
 *
 * RÈGLE DE SÛRETÉ
 * ---------------
 * On ne replie QUE ce qu'un parent revendique, et seulement à hauteur de la
 * quantité revendiquée. Une formule commandée SEULE reste toujours affichée :
 * la masquer signifierait que la cuisine ne la prépare pas.
 *
 * Un jumeau PHP existe pour le ticket imprimé — les deux doivent rester alignés.
 */

/** Normalise un libellé pour comparaison : sans accent, sans casse, espaces réduits. */
function normalizeLabel(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

/**
 * Extrait les noms de formules revendiqués par l'instruction d'une ligne.
 *
 * Le wizard écrit « + Menu (Frites + Boisson) (+2,50 €) ». On retire la
 * parenthèse de PRIX finale (celle qui commence par « + ») sans toucher au nom,
 * qui peut lui-même contenir des parenthèses.
 *
 * @param {string|null|undefined} instruction
 * @returns {string[]} noms normalisés
 */
function claimedAddonNames(instruction) {
    const text = typeof instruction === 'string' ? instruction : '';
    const names = [];

    text.split('\n').forEach((rawLine) => {
        const line = rawLine.trim();
        if (!line.startsWith('+')) return;

        const withoutPlus = line.slice(1).trim();
        // Retire une parenthèse de prix FINALE : « (+2,50 €) ».
        const withoutPrice = withoutPlus.replace(/\s*\(\s*\+[^()]*\)\s*$/, '').trim();
        if (withoutPrice) names.push(normalizeLabel(withoutPrice));
    });

    return names;
}

/**
 * Retire (ou décrémente) les lignes de formule déjà décrites sous leur parent.
 *
 * @param {Array<object>|null|undefined} orderItems
 * @returns {Array<object>} nouvelle liste ; les objets modifiés sont des copies.
 */
export function collapseBundledAddonItems(orderItems) {
    const items = Array.isArray(orderItems) ? orderItems : [];
    if (items.length === 0) return [];

    // 1. Recenser ce que chaque ligne revendique, en quantité.
    //    Une ligne ne se revendique JAMAIS elle-même (garde anti-auto-suppression).
    const quota = new Map();
    items.forEach((item) => {
        const ownName = normalizeLabel(item && (item.item_name || item.name));
        const qty = Math.max(1, parseInt(item && item.quantity, 10) || 1);

        claimedAddonNames(item && item.instruction).forEach((claimed) => {
            if (claimed === ownName) return;
            quota.set(claimed, (quota.get(claimed) || 0) + qty);
        });
    });

    if (quota.size === 0) return items.slice();

    // 2. Consommer le quota sur les lignes correspondantes.
    const out = [];
    items.forEach((item) => {
        const name = normalizeLabel(item && (item.item_name || item.name));
        const remaining = quota.get(name) || 0;
        if (remaining <= 0) {
            out.push(item);
            return;
        }

        const qty = Math.max(1, parseInt(item && item.quantity, 10) || 1);
        const consumed = Math.min(remaining, qty);
        quota.set(name, remaining - consumed);

        const left = qty - consumed;
        if (left <= 0) return; // entièrement décrite par son parent → repliée
        out.push({ ...item, quantity: left });
    });

    return out;
}

export default { collapseBundledAddonItems };
