/**
 * [KDS-TABLETTE 2026-10-19 · owner] Mode « tablette » de l'écran cuisine.
 *
 * Contexte : un SECOND poste de cuisine (pain, sauces, crudités) tourne sur une petite
 * tablette de 7-8 pouces en paysage. Le grand écran reste inchangé ; la tablette n'a besoin
 * QUE des commandes — ni barre d'administration, ni menu, ni logo, ni boutons, ni bande
 * « récemment servies », ni bouton « Prêt » (la validation se fait sur le grand écran).
 *
 * Activation (par ordre de priorité) :
 *   1. `?tablette=1` (ou la route courte `/kds-tablette`) active ET mémorise sur ce poste ;
 *   2. `?tablette=0` désactive et mémorise le REFUS (l'appareil ne repasse plus en tablette
 *      tout seul — c'est l'issue de secours si la détection se trompe) ;
 *   3. sans paramètre, la mémoire du poste décide ;
 *   4. sans mémoire : DÉTECTION AUTOMATIQUE d'un appareil tablette (voir `appareilTablette`).
 *
 * La détection ne repose JAMAIS sur la largeur de la fenêtre : l'écran principal mesure lui
 * aussi 1280×720 par moments, une règle « petite fenêtre = tablette » l'aurait dépouillé de
 * ses boutons. Elle exige un appareil TACTILE SANS SOURIS (le grand écran a une souris) ET un
 * petit écran physique.
 *
 * Le stockage peut être indisponible (mode privé, quota) : le paramètre d'URL vaut alors pour
 * la page courante, la détection automatique reste active.
 */
export const KDS_CLE_TABLETTE = 'kds.tablette';

/** Largeur minimale (px) d'une fiche pour que son contenu reste lisible. */
export const KDS_TABLETTE_LARGEUR_FICHE_MIN = 380;

/** Côté court maximal (px CSS) de l'écran PHYSIQUE d'une tablette : 7-8" ≈ 600, 10" ≈ 800. */
export const KDS_TABLETTE_ECRAN_COURT_MAX = 900;

/**
 * Cet appareil est-il une tablette ? Tactile sans souris (pointeur grossier, pas de survol —
 * valable aussi quand Chrome est en « version pour ordinateur ») ET écran physique de petite
 * taille. Un PC, même à écran tactile, garde une souris (survol) : il n'est jamais pris pour
 * une tablette. Toute information manquante ou en erreur → faux (grand écran).
 */
export function appareilTablette(win) {
    try {
        if (!win || typeof win.matchMedia !== 'function' || !win.screen) return false;
        const tactileSansSouris = win.matchMedia('(pointer: coarse)').matches
            && win.matchMedia('(hover: none)').matches;
        if (!tactileSansSouris) return false;
        const cote = Math.min(Number(win.screen.width), Number(win.screen.height));
        return Number.isFinite(cote) && cote > 0 && cote <= KDS_TABLETTE_ECRAN_COURT_MAX;
    } catch (e) {
        return false;
    }
}

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
        try { win.localStorage.setItem(KDS_CLE_TABLETTE, '0'); } catch (e) { /* page courante seulement */ }
        return false;
    }
    let memoire = null;
    try {
        memoire = win.localStorage.getItem(KDS_CLE_TABLETTE);
    } catch (e) { /* mémoire illisible : détection */ }
    if (memoire === '1') return true;
    if (memoire === '0') return false;
    return appareilTablette(win);
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
