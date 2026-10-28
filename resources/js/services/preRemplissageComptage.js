/**
 * [ONB-08 2026-08-28] Valeur de départ du champ « comptage physique ».
 *
 * Le stock théorique d'une matière première est décrémenté à chaque vente SANS
 * plancher — choix assumé et documenté (`RawMaterialStockService::consume`).
 * Personne ne saisissant les réceptions, il dérive vers le négatif : la base de
 * travail affiche « Poulet −9600 g », « Oignon −1545 g ».
 *
 * Le panneau d'ajustement se pré-remplissait avec ce stock courant, puis refusait
 * la saisie trois lignes plus bas (garde `target_on_hand < 0`). Le commerçant
 * ouvrait le panneau, cliquait Enregistrer, et s'entendait dire que sa PROPRE
 * valeur pré-remplie était invalide, sans indication de ce qu'il fallait mettre à
 * la place.
 *
 * Zéro est aussi le bon point de départ métier : ce champ demande ce qu'on a
 * COMPTÉ sur l'étagère, et un comptage physique n'est jamais négatif.
 *
 * La règle vit ici, hors du composant, pour être EXERCÉE et non réimplémentée dans
 * son propre banc — la première version du test recopiait la formule et se
 * contentait de vérifier sa copie, ce qui serait resté vert si le composant avait
 * perdu la règle.
 *
 * @param {number|string|null|undefined} stockCourant  stock théorique, possiblement négatif
 * @returns {number}  valeur de départ, arrondie au millième, jamais négative
 */
export function preRemplissageComptage(stockCourant) {
    const n = Number(stockCourant);

    if (!Number.isFinite(n)) {
        return 0;
    }

    return Math.max(0, Math.round(n * 1000) / 1000);
}

export default preRemplissageComptage;
