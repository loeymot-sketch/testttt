/**
 * screenWakeLock.js — [ETIQUETTES 2026-10-16] Garde l'écran de la tablette allumé pendant l'impression
 * automatique.
 *
 * Pourquoi : une tablette POSÉE finit par s'endormir ; Chrome ralentit alors les minuteries (une fois par
 * minute au mieux) puis les suspend, et l'impression automatique — qui repose sur un sondage toutes les 5 s —
 * s'arrête sans bruit. L'API Screen Wake Lock (Chrome Android) l'empêche tant que l'écran d'étiquettes est ouvert.
 *
 * Le verrou est relâché par le navigateur quand l'onglet passe en arrière‑plan : on le reprend au retour.
 * Aucune fonction ne lève d'exception : sur un navigateur sans l'API on le DIT, l'impression automatique
 * fonctionne tant que l'écran reste allumé (réglage de veille de la tablette).
 */
export function createWakeLock(nav = typeof navigator !== 'undefined' ? navigator : undefined, doc = typeof document !== 'undefined' ? document : undefined) {
    let sentinel = null;
    let wanted = false;
    const supported = Boolean(nav && nav.wakeLock && typeof nav.wakeLock.request === 'function');

    async function request() {
        if (!supported || !wanted || sentinel) return;
        try {
            const got = await nav.wakeLock.request('screen');
            // release() a pu être appelé pendant l'attente : on ne garde pas un verrou que plus personne ne veut.
            if (!wanted) {
                if (got && typeof got.release === 'function') { try { await got.release(); } catch (_) { /* rien */ } }
                return;
            }
            sentinel = got;
            if (sentinel && typeof sentinel.addEventListener === 'function') {
                sentinel.addEventListener('release', () => { sentinel = null; });
            }
        } catch (_) {
            sentinel = null; // refusé (batterie faible, onglet masqué) : on réessaiera au retour
        }
    }

    function onVisible() {
        if (doc && doc.visibilityState === 'visible') request();
    }

    return {
        isSupported: () => supported,
        isActive: () => Boolean(sentinel),
        async acquire() {
            wanted = true;
            if (doc && doc.addEventListener) doc.addEventListener('visibilitychange', onVisible);
            await request();
            return Boolean(sentinel);
        },
        async release() {
            wanted = false;
            if (doc && doc.removeEventListener) doc.removeEventListener('visibilitychange', onVisible);
            const s = sentinel;
            sentinel = null;
            if (s && typeof s.release === 'function') {
                try { await s.release(); } catch (_) { /* déjà relâché */ }
            }
        },
    };
}
