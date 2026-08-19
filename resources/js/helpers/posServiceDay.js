/**
 * [T-SUIVI-MINUIT 2026-08-19 · GOAL owner] Fenêtre « journée de service » du
 * tableau de suivi des commandes.
 *
 * POURQUOI
 * --------
 * Le tableau ne chargeait que le JOUR CALENDAIRE courant. Le Cayenne servant
 * tard, une commande prise à 23 h 50 DISPARAISSAIT du tableau à 00 h 00 — alors
 * que la cuisine était encore dessus. Le caissier ne pouvait plus ni la suivre,
 * ni la marquer livrée, ni l'annuler. C'est l'une des deux causes du rapport
 * terrain « je n'arrive pas à annuler les commandes passées il y a quelques
 * heures » (l'autre, la machine à états, est traitée séparément).
 *
 * PRINCIPE
 * --------
 * Tant que l'heure de bascule n'est pas franchie (5 h du matin par défaut), la
 * VEILLE reste affichée avec le jour courant. Passé cette heure, le comportement
 * est strictement identique à avant : un seul jour. La décision « board du jour »,
 * documentée pour des raisons de charge, est donc préservée — on ne l'élargit que
 * pendant la poignée d'heures où elle coupe le service en deux.
 */

/** Heure de bascule par défaut : 5 h du matin, bien après la fermeture. */
const DEFAULT_SERVICE_DAY_START_HOUR = 5;

/** Formate une date locale en `AAAA-MM-JJ` (jamais d'UTC : le service est local). */
function toLocalDateString(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

/**
 * Bornes de la journée de service en cours.
 *
 * @param {Date}   [now]        Instant de référence (injectable pour les tests).
 * @param {number} [startHour]  Heure de bascule, 0-23. Hors bornes → valeur par défaut.
 * @returns {{from: string, to: string}} Dates locales `AAAA-MM-JJ`.
 */
export function serviceDayRange(now = new Date(), startHour = DEFAULT_SERVICE_DAY_START_HOUR) {
    const reference = now instanceof Date && !Number.isNaN(now.getTime()) ? now : new Date();

    const hour = Number.isInteger(startHour) && startHour >= 0 && startHour <= 23
        ? startHour
        : DEFAULT_SERVICE_DAY_START_HOUR;

    const to = toLocalDateString(reference);

    if (reference.getHours() >= hour) {
        return { from: to, to };
    }

    // Avant la bascule : le service de la veille n'est pas terminé.
    // `setDate` gère seul les changements de mois et d'année.
    const previous = new Date(reference.getTime());
    previous.setDate(previous.getDate() - 1);

    return { from: toLocalDateString(previous), to };
}

export default { serviceDayRange };
