/**
 * [T-CAISSE-1TAP 2026-08-19 · GOAL owner] Ajout en un seul appui des produits qui
 * n'ont rien à demander au caissier.
 *
 * POURQUOI
 * --------
 * Observé en direct sur /admin/pos : ajouter un Coca-Cola 33cl — aucune option —
 * ouvrait une modale PLEIN ÉCRAN ne contenant qu'un champ « Instruction spéciale »
 * vide, puis exigeait un SECOND clic sur « Ajouter au panier ». Deux fois le
 * travail, et une modale bloquante, pour chaque boisson, dessert ou
 * accompagnement simple — en plein coup de feu.
 *
 * RÈGLE DE SÛRETÉ
 * ---------------
 * On ne saute le wizard QUE si le produit n'a strictement aucun choix à offrir.
 * Toute forme inattendue (objet nul, champ manquant, type inattendu) retombe sur
 * l'ouverture du wizard : un clic de trop est sans conséquence, un produit envoyé
 * en cuisine sans ses choix ne l'est pas.
 *
 * ÉCHAPPATOIRE : le caissier peut toujours rouvrir la ligne depuis le panier
 * (crayon ✎) pour ajouter une consigne particulière après coup.
 */

/** Vrai seulement si la valeur est un tableau réellement vide. */
function isEmptyArray(value) {
    return Array.isArray(value) && value.length === 0;
}

/**
 * Le produit peut-il aller au panier sans poser la moindre question ?
 *
 * Les trois sources d'options du wizard caisse sont `itemAttributes`
 * (viande, pain, sauce…), `extras` (crudités, suppléments) et `addons`
 * (formules : menu, frites seules, boisson seule).
 *
 * @param {object|null|undefined} item Produit normalisé par `normalizeLoadedItem`.
 * @returns {boolean}
 */
export function itemHasNoChoices(item) {
    if (!item || typeof item !== 'object') {
        return false;
    }

    return isEmptyArray(item.itemAttributes)
        && isEmptyArray(item.extras)
        && isEmptyArray(item.addons);
}

export default { itemHasNoChoices };
