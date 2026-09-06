/**
 * [ONB-10 2026-08-28] Ramener n'importe quelle valeur stockée à l'une des deux
 * que l'écran sait afficher.
 *
 * Trois conventions ont coexisté sur `printers.status` :
 *
 *   - l'énumération `App\Enums\Status` — ACTIVE = 5, INACTIVE = 10 — que TOUS les
 *     chemins d'impression consultent réellement (`EscPosPrinterService`,
 *     `KitchenTicketAutoPrinter`, les trois écouteurs d'impression) ;
 *   - l'ancien écran, qui écrivait 1 pour « actif » et 5 pour « archivé » —
 *     les deux conventions se croisaient donc exactement sur la valeur 5 ;
 *   - le schéma lui-même, dont la colonne est déclarée
 *     `unsignedTinyInteger('status')->default(1)` : une ligne insérée sans statut
 *     explicite vaut 1, une valeur qu'aucun des deux boutons radio ne porte.
 *
 * Sans normalisation, une valeur héritée ne correspondait à aucun bouton :
 * le formulaire d'édition s'ouvrait avec AUCUN statut coché. Le commerçant ne
 * pouvait pas lire l'état de son imprimante, et l'enregistrement renvoyait un 422
 * qu'il n'avait aucun moyen de comprendre.
 *
 * La collision sur 5 est irréductible : on ne peut pas savoir si un 5 vient de la
 * console (actif) ou de l'ancien écran (archivé). On tranche pour l'énumération,
 * parce que c'est elle qui décide à l'exécution si l'imprimante imprime.
 *
 * @param {number|string|null|undefined} valeur  statut tel que stocké en base
 * @returns {number}  5 (actif) ou 10 (archivé) — jamais autre chose
 */
export const STATUT_ACTIF = 5;
export const STATUT_ARCHIVE = 10;

export function statutImprimante(valeur) {
    const n = Number(valeur);

    // Absente ou illisible : on reprend le défaut du formulaire de création
    // (actif), qui est aussi l'intention du défaut de schéma.
    if (valeur === null || valeur === undefined || String(valeur).trim() === '' || !Number.isFinite(n)) {
        return STATUT_ACTIF;
    }

    // 10 est le seul « archivé » que l'énumération connaisse ; 0 était l'« inactif »
    // de l'ancien couple 0/1 accepté par la validation d'alors.
    if (n === STATUT_ARCHIVE || n === 0) {
        return STATUT_ARCHIVE;
    }

    return STATUT_ACTIF;
}

/** L'imprimante sera-t-elle retenue par les chemins d'impression ? */
export function imprimanteActive(valeur) {
    return statutImprimante(valeur) === STATUT_ACTIF;
}

export default statutImprimante;
