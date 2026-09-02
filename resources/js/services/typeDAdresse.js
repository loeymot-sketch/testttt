import labelEnum from '../enums/modules/labelEnum';

/**
 * [ONB-11 2026-08-28] Reconnaître le type d'une adresse enregistrée.
 *
 * LE DÉFAUT DE FOND : c'est la chaîne TRADUITE qui est écrite en base.
 * `this.props.form.label = $t('label.home')` — donc « Accueil », « Home » ou
 * « Work » selon la langue affichée au moment de la saisie. La présentation sert
 * de donnée.
 *
 * Conséquence immédiate : huit écrans relisent cet enregistrement en le comparant
 * à la traduction COURANTE (`form.label === $t('label.home')`). Corriger un
 * libellé — et il fallait le corriger, « Work » était en anglais et « Accueil »
 * désigne une page d'accueil, pas un domicile — aurait fait basculer tous les
 * enregistrements existants sur « Autre » à l'édition.
 *
 * Cette fonction reconnaît donc les valeurs ACTUELLES **et** les valeurs
 * HISTORIQUES, dans toutes les langues. Elle ne corrige pas la cause : stocker un
 * identifiant plutôt qu'un libellé demande une migration de données, donc le gate
 * propriétaire G-DATA, qui est en attente. Elle rend le changement de libellé sûr
 * en attendant.
 *
 * @param {string|null|undefined} libelleStocke  ce que porte l'enregistrement
 * @returns {string} labelEnum.HOME | labelEnum.WORK | labelEnum.OTHER
 */

/** Valeurs connues pour chaque type, actuelles et historiques, toutes langues. */
const CONNUS = Object.freeze({
    [labelEnum.HOME]: [
        'domicile',      // français, depuis 2026-08-28
        'accueil',       // français, AVANT 2026-08-28 — traduction fautive, mais des
                         // enregistrements la portent : on doit continuer à la lire
        'home',          // anglais
        'zuhause',       // allemand
    ],
    [labelEnum.WORK]: [
        'travail',       // français, depuis 2026-08-28
        'work',          // anglais, ET français AVANT 2026-08-28 (non traduit)
        'arbeit',        // allemand
    ],
});

export function typeDAdresse(libelleStocke) {
    const valeur = String(libelleStocke ?? '').trim().toLowerCase();

    if (valeur === '') {
        return labelEnum.OTHER;
    }

    for (const [type, synonymes] of Object.entries(CONNUS)) {
        if (synonymes.includes(valeur)) {
            return type;
        }
    }

    // Tout le reste est un libellé libre saisi par le commerçant : « Chantier »,
    // « Chez ma mère ». Il est CONSERVÉ tel quel, seul le bouton radio bascule.
    return labelEnum.OTHER;
}

export default typeDAdresse;
