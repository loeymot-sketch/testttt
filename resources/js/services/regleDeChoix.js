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
    const bas = Number.isFinite(Number(min)) ? Math.max(0, Number(min)) : 0;
    const hautBrut = Number.isFinite(Number(max)) ? Number(max) : 1;
    // Un maximum de 0 ou inférieur au minimum n'a pas de sens : on l'aligne sur le
    // minimum plutôt que d'afficher une plage impossible au commerçant.
    const haut = Math.max(bas, hautBrut > 0 ? hautBrut : 1);

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
