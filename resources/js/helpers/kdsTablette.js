/**
 * [KDS-TABLETTE 2026-10-19 · owner] Mode « tablette » de l'écran cuisine.
 *
 * Contexte : un SECOND poste de cuisine (pain, sauces, crudités) tourne sur une petite
 * tablette de 7-8 pouces en paysage. Le grand écran reste inchangé ; la tablette n'a besoin
 * QUE des commandes — ni barre d'administration, ni menu, ni logo, ni boutons, ni bande
 * « récemment servies », ni bouton « Prêt » (la validation se fait sur le grand écran).
 *
 * Activation EXPLICITE, jamais par la taille de la fenêtre : l'écran principal mesure lui
 * aussi 1280×720 par moments, une détection automatique l'aurait dépouillé de ses boutons.
 *   - `?tablette=1` (ou la route courte `/kds-tablette`) active ET mémorise sur ce poste ;
 *   - `?tablette=0` désactive et efface la mémoire ;
 *   - sans paramètre, la mémoire du poste décide.
 *
 * Le stockage peut être indisponible (mode privé, quota) : dans ce cas le paramètre d'URL
 * vaut pour la page courante, et l'on retombe sur « pas de mode tablette » — le grand écran
 * n'est jamais touché par une panne de stockage.
 */
export const KDS_CLE_TABLETTE = 'kds.tablette';

/** Largeur minimale (px) d'une fiche pour que son contenu reste lisible. */
export const KDS_TABLETTE_LARGEUR_FICHE_MIN = 380;

export function modeTabletteActif(win = typeof window !== 'undefined' ? window : null) {
    if (!win) return false;
    let parametre = null;
    try {
        parametre = new URLSearchParams(win.location.search || '').get('tablette');
    } catch (e) { /* adresse illisible : on retombe sur la mémoire */ }

    if (parametre === '1') {
        try { win.localStorage.setItem(KDS_CLE_TABLETTE, '1'); } catch (e) { /* page courante seulement */ }
        return true;
    }
    if (parametre === '0') {
        try { win.localStorage.removeItem(KDS_CLE_TABLETTE); } catch (e) { /* rien à effacer */ }
        return false;
    }
    try {
        return win.localStorage.getItem(KDS_CLE_TABLETTE) === '1';
    } catch (e) {
        return false;
    }
}

/**
 * Combien de fiches côte à côte sur CETTE largeur : autant que possible sans descendre sous
 * la largeur minimale lisible. Une tablette de 1280 px en montre 3, une de 960 px en montre 2,
 * jamais moins d'une. Les fiches à venir se découvrent en faisant glisser au doigt.
 */
export function fichesParEcranTablette(largeur) {
    const l = Number(largeur);
    if (!Number.isFinite(l) || l <= 0) return 2;
    return Math.max(1, Math.floor(l / KDS_TABLETTE_LARGEUR_FICHE_MIN));
}
