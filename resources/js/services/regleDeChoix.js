/**
 * [ONB-10 2026-08-27] Traduire une contrainte de choix en phrase lisible.
 *
 * L'écran « Attribut d'articles » affichait la contrainte sous sa forme brute :
 * « 0 - 1 », « 1 - 1 ». C'est l'encodage min/max du développeur, pas une règle. Un
 * restaurateur qui compose sa carte doit lire « Facultatif · un seul choix », pas
 * déchiffrer deux nombres et un tiret dont l'ordre n'est écrit nulle part.
 *
 * La fonction est pure et vit hors du composant pour être exercée cas par cas
 * (regleDeChoix.spec.js) plutôt que constatée à l'œil.
 *
 * @param {number|null|undefined} min  nombre minimum de choix (0 = facultatif)
 * @param {number|null|undefined} max  nombre maximum de choix
 * @param {(cle: string, params?: object) => string} t  fonction de traduction ($t)
 * @returns {string}
 */
export function regleDeChoix(min, max, t) {
    // Les valeurs par défaut reprennent celles du formulaire de création
    // (min_select: 0, max_select: 1) pour qu'une ligne incomplète en base se lise
    // comme ce que le formulaire aurait produit, plutôt que comme « 0 - 0 ».
    // `Number(null)` et `Number('')` valent 0, tous deux finis : sans ce test
    // explicite, une valeur ABSENTE serait lue comme un zéro DÉLIBÉRÉ — et les deux
    // ne veulent pas dire la même chose du tout (voir plus bas). C'est le même piège
    // que celui rencontré sur le libellé de taxe le même jour.
    const absent = (v) => v === null || v === undefined || String(v).trim() === '';

    const bas = absent(min) || ! Number.isFinite(Number(min))
        ? 0
        : Math.max(0, Number(min));

    // Absent : on reprend le défaut du formulaire de création (max_select: 1), pour
    // qu'une ligne ancienne se lise comme ce que le formulaire aurait produit.
    const hautBrut = absent(max) || ! Number.isFinite(Number(max)) ? 1 : Number(max);

    // [ONB-02 2026-08-28 · ERREUR CORRIGÉE] Je traitais `max_select = 0` comme une
    // valeur absurde et j'affichais « un seul choix ». C'est faux : côté serveur,
    // `MultiVariationConstraint.php:233` fait `if ($max > 0 && $totalQty > $max)` —
    // **zéro signifie SANS PLAFOND**. L'écran annonçait donc une contrainte qui
    // n'existe pas, et mon propre test verrouillait l'erreur. Trouvé par un agent
    // adverse lancé sur mon travail.
    if (hautBrut <= 0) {
        return bas === 0
            ? t('label.choice_optional_unlimited')
            : t('label.choice_required_at_least', { n: bas });
    }

    // Un maximum inférieur au minimum reste une plage impossible : on l'aligne sur le
    // minimum plutôt que d'afficher « de 3 à 1 » au commerçant.
    const haut = Math.max(bas, hautBrut);

    if (haut === 1) {
        return bas === 0 ? t("label.choice_optional_one") : t("label.choice_required_one");
    }

    if (bas === 0) {
        return t("label.choice_optional_up_to", { n: haut });
    }

    if (bas === haut) {
        return t("label.choice_required_exactly", { n: haut });
    }

    return t("label.choice_required_range", { min: bas, max: haut });
}

export default regleDeChoix;
