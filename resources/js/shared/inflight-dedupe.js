import axiosModule from 'axios';

/**
 * [GOAL-OPS-SWAP W3 2026-08-12] Fusionne les GET **identiques et simultanés**.
 *
 * LE PROBLÈME, MESURÉ (Playwright, origine correspondant à `APP_URL`) :
 * ouvrir la caisse coûte **35 requêtes en 10 s** ; au repos elle est sobre
 * (5 req/min). Sur ces 35, **7 endpoints partent DEUX FOIS**, à **0-1 ms
 * d'écart** — donc strictement simultanés :
 *
 *   /api/frontend/setting · /api/admin/default-access · /api/admin/setting/company
 *   /api/admin/pos/counter-collect/pending · /api/admin/pos/web-orders/pending
 *   /api/admin/pos/web-orders/paid · /api/admin/users/address/2
 *
 * POURQUOI LE CAISSIER VOIT « Trop de requêtes » : le plafond `throttle:api`
 * vaut **120/min en production** et il est **PAR COMPTE**, pas par écran
 * (`RouteServiceProvider.php:57` → `by($request->user()?->id ?: $request->ip())`).
 * Caisse (35) + un F5 (35) + un second écran sous le même compte (35) = 105 ;
 * le quatrième franchit le mur. En local le plafond est à 1000
 * (`.env API_THROTTLE_PER_MINUTE`), ce qui MASQUE le défaut au développement.
 *
 * ⛔ CE QU'ON NE FAIT PAS : masquer le message. Il a été ajouté délibérément
 *    (`bootstrap.js:52-64`) parce que la caisse avalait « 7+ HTTP 429 en
 *    silence » — classé P0 : « le caissier n'avait aucun signal ». On retire
 *    les requêtes, jamais l'alerte.
 *
 * CE QUE CE MODULE EST — et n'est PAS :
 *   · il fusionne uniquement des requêtes **en vol au même instant** ;
 *   · il **ne met RIEN en cache** : dès qu'une réponse est rendue, la clé est
 *     libérée. Un appel ultérieur repart sur le réseau. Une caisse qui
 *     afficherait des commandes périmées serait bien pire que le défaut ;
 *   · il ne touche **jamais** une mutation (POST/PUT/PATCH/DELETE) : fusionner
 *     deux encaissements serait une commande perdue ;
 *   · il propage l'erreur à **tous** les appelants — jamais de succès fantôme.
 *
 * Les deux appelants fusionnés partagent le même objet de données, comme le
 * font les bibliothèques de déduplication usuelles. C'est sans effet ici : les
 * modules Vuex lisent `res.data` et le committent sans le muter.
 *
 * Échappatoire : passer `__noDedupe: true` dans la configuration axios.
 */

/** Clé d'identité d'une requête : méthode + URL + paramètres, rien d'autre. */
function cleDeRequete(config) {
    let params = '';
    try {
        const p = config.params;
        if (p && typeof p === 'object') {
            // Tri des clés : `{a:1,b:2}` et `{b:2,a:1}` sont la même requête.
            params = JSON.stringify(Object.keys(p).sort().reduce((acc, k) => {
                acc[k] = p[k];
                return acc;
            }, {}));
        } else if (p !== undefined && p !== null) {
            params = String(p);
        }
    } catch (_) {
        // Paramètres non sérialisables (FormData, cycles) : on ne fusionne pas.
        return null;
    }

    return `${String(config.method || 'get').toLowerCase()} ${config.url || ''} ${params}`;
}

/**
 * Résout l'adaptateur courant en FONCTION.
 *
 * ⚠️ PIÈGE QUI A COÛTÉ UNE PREMIÈRE VERSION INERTE : en axios 1.x,
 * `defaults.adapter` n'est PAS une fonction — c'est un tableau de noms,
 * `["xhr","http","fetch"]`. Une garde `typeof adapter !== 'function'` sautait
 * donc l'installation en silence, et le banc unitaire ne le voyait pas parce
 * qu'il utilisait un faux axios avec une fonction. Mesure de contrôle :
 * la rafale d'ouverture était restée à 35 requêtes, inchangée.
 *
 * `axios.getAdapter` (exposé depuis 1.x) fait la résolution correctement.
 *
 * @returns {Function|null}
 */
function resoudreAdaptateur(axios) {
    const brut = axios?.defaults?.adapter;

    if (typeof brut === 'function') {
        return brut;
    }
    if (!brut) {
        return null;
    }

    // `getAdapter` vit sur l'EXPORT PAR DÉFAUT d'axios, pas sur les instances
    // issues de `axios.create()` — d'où le repli sur le module importé. Sans ce
    // repli, le module s'installe dans l'application (qui utilise l'export par
    // défaut) mais PAS sur une instance créée : une asymétrie invisible.
    const resolveurs = [axios.getAdapter, axiosModule?.getAdapter];
    for (const resoudre of resolveurs) {
        if (typeof resoudre !== 'function') continue;
        try {
            const resolu = resoudre(brut);
            if (typeof resolu === 'function') return resolu;
        } catch (_) {
            // adaptateur introuvable dans cet environnement — essayer le suivant
        }
    }

    return null;
}

/**
 * @param {object} axios instance axios
 * @returns {boolean} true si l'enveloppe a été posée
 */
export function installInFlightGetDedupe(axios) {
    if (!axios || !axios.defaults) {
        return false;
    }
    if (typeof axios.defaults.adapter === 'function' && axios.defaults.adapter.__inflightDedupe) {
        return true; // déjà installé — ne pas empiler les enveloppes
    }

    const adaptateurOriginal = resoudreAdaptateur(axios);
    if (!adaptateurOriginal) {
        return false;
    }
    const enVol = new Map();

    const enveloppe = (config) => {
        const methode = String(config?.method || 'get').toLowerCase();

        if (methode !== 'get' || config?.__noDedupe === true) {
            return adaptateurOriginal(config);
        }

        const cle = cleDeRequete(config);
        if (cle === null) {
            return adaptateurOriginal(config);
        }

        const dejaEnVol = enVol.get(cle);
        if (dejaEnVol) {
            return dejaEnVol;
        }

        const promesse = adaptateurOriginal(config).finally(() => {
            // Libération DÈS le règlement : aucune mise en cache.
            enVol.delete(cle);
        });

        enVol.set(cle, promesse);

        return promesse;
    };

    enveloppe.__inflightDedupe = true;
    axios.defaults.adapter = enveloppe;

    return true;
}

export default installInFlightGetDedupe;
