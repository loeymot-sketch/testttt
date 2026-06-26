/**
 * Zoom automatique de la page CAISSE (POS).
 *
 * L'owner veut un peu plus de contenu visible (catégories + produits + panier +
 * encaissement) SANS que tout devienne minuscule. Un zoom 0.67 (= Chrome 67 %)
 * testé sur l'écran 16" réel s'est avéré TROP petit / étroit → on retient un zoom
 * léger 0.9 (≈ taille normale, juste un peu compact). Ce helper l'applique
 * AUTOMATIQUEMENT : appliqué au montage du PosComponent, retiré au démontage →
 * scopé à la caisse uniquement (n'affecte PAS les autres pages admin).
 *
 * Zone NON-frozen : on n'édite AUCUN fichier frozen. Le wizard Vanilla frozen
 * (pos-wizard.js/.css) n'est pas modifié — il hérite simplement du zoom du body,
 * exactement comme sous le zoom navigateur que l'owner utilisait déjà.
 *
 * `zoom` est supporté nativement par Chrome/Edge/Safari (la caisse tourne sous
 * Chrome). Valeur surchargeable EN LIVE sans redéploiement via la console :
 *   localStorage.setItem('caisse_zoom', '0.85')  // puis recharger la caisse
 * Bornes acceptées : 0.3 → 1 (1 = taille 100 % normale).
 */

export const CAISSE_ZOOM = 0.9;

/**
 * Résout le zoom à appliquer : override localStorage `caisse_zoom` s'il est
 * valide (entre 0.3 et 1), sinon la valeur par défaut testée par l'owner.
 */
export function resolveCaisseZoom(storage) {
    try {
        const raw = storage && typeof storage.getItem === 'function'
            ? storage.getItem('caisse_zoom')
            : null;
        const n = Number(raw);
        if (Number.isFinite(n) && n >= 0.3 && n <= 1) {
            return n;
        }
    } catch (e) {
        /* accès storage refusé → défaut */
    }
    return CAISSE_ZOOM;
}

/** Applique le zoom sur le body. Défensif : no-op si doc/body absent. */
export function applyCaisseZoom(doc, zoom) {
    if (!doc || !doc.body) {
        return;
    }
    const z = (typeof zoom === 'number' && zoom > 0) ? zoom : CAISSE_ZOOM;
    doc.body.style.zoom = String(z);
    if (typeof doc.body.setAttribute === 'function') {
        doc.body.setAttribute('data-caisse-zoom', String(z));
    }
}

/** Retire le zoom (sortie de la caisse) pour ne pas affecter les autres pages. */
export function clearCaisseZoom(doc) {
    if (!doc || !doc.body) {
        return;
    }
    doc.body.style.zoom = '';
    if (typeof doc.body.removeAttribute === 'function') {
        doc.body.removeAttribute('data-caisse-zoom');
    }
}
