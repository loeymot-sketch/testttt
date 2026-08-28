/**
 * [ONB-06 2026-08-28] Traduire un nom de rôle pour l'affichage.
 *
 * Les rôles sont stockés en base sous leur nom technique anglais — « Branch
 * Manager », « POS Operator », « Stuff » — parce qu'un semoir ne peut pas être
 * rejoué sur une installation vivante. On traduit donc À L'AFFICHAGE.
 *
 * Cette fonction existait déjà, DUPLIQUÉE dans `RoleListComponent.vue` et
 * `RoleShowComponent.vue`. L'écran d'embauche, lui, ne l'utilisait pas : son menu
 * déroulant liait `label-by="name"` directement, et proposait à un restaurateur
 * français de choisir entre « Branch Manager », « POS Operator » et « Stuff ».
 *
 * C'est le geste d'ouverture du produit — donner un rôle à son premier salarié —
 * et il se faisait en devinant trois mots anglais, dont un est une faute de frappe
 * historique (« Stuff » pour « Staff »).
 *
 * Un rôle créé par le commerçant lui-même n'a pas de traduction : on rend alors son
 * nom tel quel, ce qui est exactement ce qu'il a écrit.
 *
 * @param {string|null|undefined} nom  nom technique du rôle
 * @param {(cle: string) => string} t  fonction de traduction ($t)
 * @returns {string}
 */
export function libelleRole(nom, t) {
    if (!nom) {
        return '';
    }

    // Les clés i18n ne peuvent pas porter de point : vue-i18n le lit comme un
    // séparateur de niveau.
    const cle = 'role.' + String(nom).replace(/[.]/g, '_');
    const traduit = t(cle);

    return traduit === cle ? nom : traduit;
}

/**
 * Rend une liste de rôles prête pour un menu déroulant, en remplaçant le nom
 * technique par son libellé français.
 *
 * @param {Array<{id: number, name: string}>} roles
 * @param {(cle: string) => string} t
 */
export function rolesLibelles(roles, t) {
    return (Array.isArray(roles) ? roles : []).map((role) => ({
        ...role,
        name: libelleRole(role.name, t),
    }));
}

export default libelleRole;
