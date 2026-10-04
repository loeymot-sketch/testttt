/**
 * [P0-01 · RAPPORT_DEV_CAISSE_2026-09-24 — reproduit par l'écran le 2026-09-30]
 *
 * LE DÉFAUT
 * ---------
 * Le backend attribue un id de variation DIFFÉRENT par attribut pour le MÊME nom de
 * viande. Vérifié en base sur le Tacos XL (item 234) :
 *     Mexicanos     : 777 (Viande 1) · 784 (Viande 2) · 791 (Viande 3)
 *     Cordon Bleu   : 778            · 785            · 792
 *     Viande Hachée : 779            · 786            · 793
 * Une ligne de panier à trois viandes enregistre donc trois ids pris dans TROIS
 * attributs différents (777 / 785 / 793).
 *
 * Or l'assistant de caisse (`public/js/pos-wizard.js`, ZONE GELÉE — CLAUDE.md §7)
 * construit ses tuiles en dédoublonnant les variations PAR NOM sur l'ensemble des
 * attributs « Viande N », et ne conserve que l'id rencontré en PREMIER : ses tuiles sont
 * `v_777`, `v_778`, `v_779`. C'est sous cette clé, et elle seule, qu'il lit le compte
 * d'une viande.
 *
 * Restaurer une composition sous `v_785` / `v_793` désignait donc la bonne viande pour la
 * base, mais une tuile INEXISTANTE pour l'écran. Mesuré au navigateur : rouvrir
 * « Modifier » sur un Tacos XL à trois viandes n'en rallumait qu'UNE — tout en affichant
 * « 3/3 incluses », puisque le compteur, lui, somme toutes les clés. Le caissier voyait
 * une composition amputée et pouvait la valider telle quelle.
 *
 * LE REMÈDE
 * ---------
 * Normaliser la clé vers l'id CANONIQUE — celui du premier attribut viande portant ce nom
 * — dans l'ordre exact de déduplication du wizard. On ne change ni la viande, ni le prix,
 * ni la quantité : seulement la clé sous laquelle la surface d'affichage sait la lire.
 *
 * Fonction PURE et exportée à dessein : `buildWizardRestorePayload` est appelée non liée
 * par plusieurs bancs (`ItemComponent.methods.buildWizardRestorePayload(...)`), donc rien
 * sur ce chemin ne doit dépendre de `this`.
 */

/** Minuscules, sans accents, sans espaces de bord — même comparaison que le wizard. */
export function normaliserNomViande(valeur) {
    return String(valeur || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}

/**
 * Id sous lequel l'assistant de caisse AFFICHE cette viande.
 *
 * @param {object} item        produit chargé (itemAttributes + variations)
 * @param {string} nomViande   nom de la viande à retrouver
 * @param {number|string} idParDefaut  repli si aucun attribut ne correspond
 * @returns {number|string} l'id canonique, ou `idParDefaut`
 */
export function idViandeCanonique(item, nomViande, idParDefaut) {
    const cible = normaliserNomViande(nomViande);
    if (!cible || !item || !Array.isArray(item.itemAttributes) || !item.variations) {
        return idParDefaut;
    }

    const attributsViande = item.itemAttributes.filter((attribut) => {
        const nom = normaliserNomViande(attribut && attribut.name);
        return nom.includes('viande') || nom.includes('meat');
    });

    for (const attribut of attributsViande) {
        const liste = item.variations[attribut.id] || [];
        const trouvee = liste.find((v) => normaliserNomViande(v && v.name) === cible);
        if (trouvee) return trouvee.id;
    }

    // Repli explicite : mieux vaut l'ancien comportement qu'une clé inventée.
    return idParDefaut;
}
