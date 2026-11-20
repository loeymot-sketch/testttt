/**
 * [GARDE DE CONNEXION 2026-10-19 · owner « à chaque fois que je me déconnecte longtemps l'écran ne s'ouvre pas, je
 * rafraîchis plusieurs fois… je ne veux plus de ce blocage sur aucun écran : caisse, cuisine »]
 *
 * CE QUI BLOQUAIT (constaté, reproduit au navigateur)
 * ---------------------------------------------------
 *  1. Une session expirée posait un écran sombre « Session expirée — reconnexion… » qui ne partait JAMAIS : la
 *     navigation vers la page de connexion échouait en silence et l'écran restait figé derrière (voir
 *     session-expired-overlay.js, corrigé en même temps).
 *  2. Après un déploiement, un onglet resté ouvert demandait des fichiers (« chunks ») qui n'existent plus : écran
 *     blanc ou « chargement » infini jusqu'à un rechargement… qui servait parfois encore l'ancienne page.
 *  3. Une longue coupure réseau (tablette en veille, Wi‑Fi) laissait l'écran dans un état à mi-chemin, sans aucun
 *     indicateur, jusqu'à ce que quelqu'un rafraîchisse à la main.
 *  4. Le bandeau « Nouvelle version » exigeait un clic : sur un écran cuisine que personne ne touche, il ne
 *     disparaissait jamais.
 *
 * CE QUE FAIT CE MODULE (DOM pur : aucune dépendance Vue/Vuex, qui peuvent être dans un état incohérent)
 * ------------------------------------------------------------------------------------------------------
 *  - Une pastille discrète « ● Connecté / ● Reconnexion… / ● Pas de connexion » (état toujours lisible).
 *  - Sonde `/api/health/live` (sans base de données) : bascule en « reconnexion » au premier échec, « pas de
 *    connexion » au troisième ; retour automatique au premier succès.
 *  - Au retour après une panne de plus d'1 min 30, ou au réveil de la tablette, ou si une nouvelle version est
 *    publiée, ou si un fichier du site est introuvable : l'écran SE RECHARGE TOUT SEUL, mais seulement quand
 *    personne ne s'en sert (aucun geste depuis 60 s, aucune fenêtre ouverte, aucune saisie, aucun paiement).
 *    Sinon : un bandeau, et on retente toutes les 15 s jusqu'à ce que ce soit sûr.
 *  - Garde-fou anti-boucle : au plus 3 rechargements en 10 minutes, ensuite bandeau seulement.
 *
 * Tout est injectable (horloge, réseau, rechargement) pour être testé sans navigateur.
 */

export const SONDE_OK_MS = 15000;
export const SONDE_ECHEC_MS = 5000;
export const SONDE_TIMEOUT_MS = 6000;
export const ECHECS_AVANT_HORS_LIGNE = 3;
export const PANNE_LONGUE_MS = 90000;
export const INACTIVITE_RECHARGE_MS = 60000;
export const REVEIL_LONG_MS = 10 * 60 * 1000;
export const VERSION_MS = 60000;
export const NOUVEL_ESSAI_MS = 15000;
export const MAX_RECHARGEMENTS = 3;
export const FENETRE_RECHARGEMENTS_MS = 10 * 60 * 1000;
export const BLANC_MS = 25000;

const CLE_RECHARGEMENTS = 'fk_resilience_reloads';
const MOTIF_CHUNK = /Loading chunk [\w-]+ failed|ChunkLoadError|Loading CSS chunk|Importing a module script failed|Failed to fetch dynamically imported module|error loading dynamically imported module/i;

/** Un message d'erreur est-il l'échec de chargement d'un fichier du site (version périmée) ? */
export function estErreurDeChunk(raison) {
    const texte = typeof raison === 'string' ? raison : `${(raison && raison.name) || ''} ${(raison && raison.message) || ''}`;

    return MOTIF_CHUNK.test(texte);
}

/** Écrans exclus : la borne publique a sa propre gestion (et ses fichiers gelés). */
export function ecranExclu(chemin) {
    return /^\/kiosk(\/|$)/.test(String(chemin || ''));
}

/** Libellé de la pastille selon l'état. */
export function libelleEtat(etat, depuisMs = 0) {
    if (etat === 'ok') return 'Connecté';
    if (etat === 'reconnexion') return 'Reconnexion…';
    const min = Math.floor(depuisMs / 60000);

    return min >= 1 ? `Pas de connexion · ${min} min` : 'Pas de connexion';
}

/** Version de l'application tablette lue dans l'agent utilisateur (« LeCayenneEtiquettes/1.2.0 »), ou null (navigateur ordinaire). */
export function versionApplicationNative(userAgent) {
    const m = /LeCayenneEtiquettes\/(\d+(?:\.\d+){0,2})/.exec(String(userAgent || ''));

    return m ? m[1] : null;
}

/** « 1.10.0 » est plus récente que « 1.9.0 » : on compare des NOMBRES, jamais du texte. */
export function estPlusRecente(serveur, installee) {
    const lire = (v) => String(v || '').split('.').map((n) => parseInt(n, 10) || 0);
    const a = lire(serveur);
    const b = lire(installee);
    for (let i = 0; i < 3; i += 1) {
        if ((a[i] || 0) !== (b[i] || 0)) return (a[i] || 0) > (b[i] || 0);
    }

    return false;
}

/**
 * [MISE À JOUR DANS L'APPLICATION 2026-10-19 · owner « assure à chaque mise à jour simple et via l'app direct »]
 * Dans l'application tablette « Le Cayenne Étiquettes » (et seulement elle), compare sa version à celle publiée sur le
 * serveur (`/etiquettes-app.json`, écrite avec le fichier `/etiquettes.apk`) et propose un bandeau « Mettre à jour » :
 * un appui télécharge et installe la nouvelle version. Les mises à jour du SITE, elles, sont automatiques (le contenu
 * vient du serveur) : ce bandeau ne concerne que le petit programme Android lui-même.
 */
export function surveillerMiseAJourApplication(o = {}) {
    const win = o.win || (typeof window !== 'undefined' ? window : null);
    if (!win || !win.document) return { arreter() {} };
    const installee = versionApplicationNative(o.userAgent !== undefined ? o.userAgent : (win.navigator && win.navigator.userAgent));
    if (!installee) return { arreter() {} };

    const doc = win.document;
    const lire = o.lire || (async () => {
        try {
            const r = await win.fetch(`/etiquettes-app.json?_=${Date.now()}`, { cache: 'no-store', headers: { Accept: 'application/json' } });

            return r && r.ok ? await r.json() : null;
        } catch (e) {
            return null;
        }
    });
    const telecharger = o.telecharger || ((url) => { win.location.href = url; });
    let bandeau = null;
    let minuteur = null;
    let arrete = false;

    const verifier = async () => {
        if (arrete || bandeau) return;
        const publie = await lire();
        if (arrete || !publie || !estPlusRecente(publie.version_name, installee)) return;
        bandeau = doc.createElement('div');
        bandeau.setAttribute('role', 'status');
        bandeau.setAttribute('data-testid', 'maj-application');
        bandeau.style.cssText = [
            'position:fixed', 'left:50%', 'top:10px', 'transform:translateX(-50%)', 'z-index:2147482600',
            'background:#1a1a1a', 'color:#fff', 'display:flex', 'align-items:center', 'gap:12px',
            'padding:10px 16px', 'border-radius:10px', 'box-shadow:0 6px 20px rgba(0,0,0,.35)',
            'font:600 15px system-ui,-apple-system,sans-serif',
        ].join(';');
        const t = doc.createElement('span');
        t.textContent = `📲 Nouvelle version de l’application (${publie.version_name})`;
        const b = doc.createElement('button');
        b.type = 'button';
        b.setAttribute('data-testid', 'maj-application-bouton');
        b.textContent = 'Mettre à jour';
        b.style.cssText = 'background:#F4501E;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-weight:700;cursor:pointer';
        b.addEventListener('click', () => telecharger(publie.url || '/etiquettes.apk'));
        bandeau.appendChild(t);
        bandeau.appendChild(b);
        doc.body.appendChild(bandeau);
    };

    verifier();
    minuteur = win.setInterval(verifier, o.intervalleMs || 30 * 60 * 1000);
    const surReveil = () => { if (doc.visibilityState === 'visible') verifier(); };
    doc.addEventListener('visibilitychange', surReveil);

    return {
        arreter() {
            arrete = true;
            if (minuteur) win.clearInterval(minuteur);
            doc.removeEventListener('visibilitychange', surReveil);
            if (bandeau && bandeau.parentNode) bandeau.parentNode.removeChild(bandeau);
            bandeau = null;
        },
    };
}

/**
 * @param {object} [o]
 * @param {Window}   [o.win]        fenêtre (défaut : window)
 * @param {Function} [o.sonde]      () => Promise<boolean> — le serveur répond-il ?
 * @param {Function} [o.manifeste]  () => Promise<string|null> — contenu du mix-manifest servi
 * @param {Function} [o.recharger]  () => void
 * @param {Function} [o.maintenant] () => number (ms)
 * @param {boolean}  [o.pastille]   afficher la pastille (défaut : oui)
 * @returns {{arreter: Function, etat: Function, demanderRechargement: Function, sondeMaintenant: Function}}
 */
export function demarrerGardeDeConnexion(o = {}) {
    const win = o.win || (typeof window !== 'undefined' ? window : null);
    if (!win || !win.document) return { arreter() {}, etat: () => 'ok', demanderRechargement() {}, sondeMaintenant: () => Promise.resolve() };
    if (ecranExclu(win.location && win.location.pathname)) return { arreter() {}, etat: () => 'ok', demanderRechargement() {}, sondeMaintenant: () => Promise.resolve() };

    const doc = win.document;
    const maintenant = o.maintenant || (() => Date.now());
    const recharger = o.recharger || (() => win.location.reload());

    const sonde = o.sonde || (async () => {
        const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const t = ctrl ? setTimeout(() => ctrl.abort(), SONDE_TIMEOUT_MS) : null;
        try {
            const r = await win.fetch(`/api/health/live?_=${maintenant()}`, { cache: 'no-store', signal: ctrl ? ctrl.signal : undefined });

            return !!(r && r.ok);
        } catch (e) {
            return false;
        } finally {
            if (t) clearTimeout(t);
        }
    });
    const manifeste = o.manifeste || (async () => {
        try {
            const r = await win.fetch(`/mix-manifest.json?_=${maintenant()}`, { cache: 'no-store', headers: { Accept: 'application/json' } });

            return r && r.ok ? await r.text() : null;
        } catch (e) {
            return null;
        }
    });

    let etat = 'ok';
    let echecs = 0;
    let panneDepuis = null;
    let dernierGeste = maintenant();
    let masqueDepuis = null;
    let baseManifeste = null;
    let rechargementDemande = null; // motif en attente d'un moment sûr
    let dernierRechargement = -Infinity; // un rechargement vient d'être lancé : on ne le redemande pas 20 s durant
    let minuteurSonde = null;
    let minuteurVersion = null;
    let minuteurEssai = null;
    let minuteurBlanc = null;
    let arrete = false;
    let bandeau = null;
    let pastille = null;
    const ecouteurs = [];

    const ecouter = (cible, nom, fn, opts) => {
        cible.addEventListener(nom, fn, opts);
        ecouteurs.push([cible, nom, fn, opts]);
    };

    /* ---------------- pastille ---------------- */
    const couleurs = { ok: '#16A34A', reconnexion: '#F59E0B', 'hors-ligne': '#DC2626' };
    const dessiner = () => {
        if (o.pastille === false) return;
        if (!pastille) {
            pastille = doc.createElement('div');
            pastille.setAttribute('role', 'status');
            pastille.setAttribute('aria-live', 'polite');
            pastille.setAttribute('data-testid', 'connexion-pastille');
            pastille.style.cssText = [
                'position:fixed', 'left:10px', 'bottom:10px', 'z-index:2147482000', 'pointer-events:none',
                'display:flex', 'align-items:center', 'gap:6px', 'padding:4px 10px', 'border-radius:999px',
                'background:rgba(17,17,17,0.82)', 'color:#fff', 'font:600 12px system-ui,-apple-system,sans-serif',
                'letter-spacing:.01em', 'box-shadow:0 2px 8px rgba(0,0,0,.25)',
            ].join(';');
            doc.body.appendChild(pastille);
        }
        const depuis = panneDepuis === null ? 0 : maintenant() - panneDepuis;
        pastille.innerHTML = '';
        const point = doc.createElement('span');
        point.style.cssText = `width:9px;height:9px;border-radius:50%;background:${couleurs[etat]};display:inline-block`;
        const texte = doc.createElement('span');
        texte.className = 'cx-texte'; // la feuille de style du poste tablette le masque quand tout va bien
        texte.textContent = libelleEtat(etat === 'hors-ligne' ? 'hors-ligne' : etat, depuis);
        pastille.setAttribute('aria-label', texte.textContent);
        pastille.appendChild(point);
        pastille.appendChild(texte);
        pastille.setAttribute('data-etat', etat);
    };

    /* ---------------- bandeau (si on ne peut pas recharger tout de suite) ---------------- */
    const montrerBandeau = (texte) => {
        if (bandeau || arrete) return;
        bandeau = doc.createElement('div');
        bandeau.setAttribute('role', 'status');
        bandeau.setAttribute('data-testid', 'resilience-bandeau');
        bandeau.style.cssText = [
            'position:fixed', 'left:50%', 'bottom:46px', 'transform:translateX(-50%)', 'z-index:2147482500',
            'background:#1a1a1a', 'color:#fff', 'display:flex', 'align-items:center', 'gap:12px',
            'padding:10px 16px', 'border-radius:10px', 'box-shadow:0 6px 20px rgba(0,0,0,.3)',
            'font:600 14px system-ui,-apple-system,sans-serif',
        ].join(';');
        const t = doc.createElement('span');
        t.textContent = texte;
        const b = doc.createElement('button');
        b.type = 'button';
        b.textContent = 'Rafraîchir';
        b.style.cssText = 'background:#F4501E;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-weight:700;cursor:pointer';
        b.addEventListener('click', () => recharger());
        bandeau.appendChild(t);
        bandeau.appendChild(b);
        doc.body.appendChild(bandeau);
    };
    const retirerBandeau = () => {
        if (bandeau && bandeau.parentNode) bandeau.parentNode.removeChild(bandeau);
        bandeau = null;
    };

    /* ---------------- moment sûr pour recharger ---------------- */
    const rechargementsRecents = () => {
        try {
            const liste = JSON.parse(win.sessionStorage.getItem(CLE_RECHARGEMENTS) || '[]');

            return liste.filter((t) => maintenant() - t < FENETRE_RECHARGEMENTS_MS);
        } catch (e) {
            return [];
        }
    };
    const noterRechargement = () => {
        try {
            win.sessionStorage.setItem(CLE_RECHARGEMENTS, JSON.stringify([...rechargementsRecents(), maintenant()]));
        } catch (e) { /* stockage indisponible : le garde-fou retombe sur « jamais plus de 3 » par page */ }
    };

    const occupe = () => {
        // Une fenêtre ouverte, une saisie en cours ou un paiement : on ne coupe JAMAIS.
        if (doc.querySelector('.modal.show, [role="dialog"][aria-modal="true"], [data-testid^="pos-payment"], [data-testid="pos-counter-collect"]')) return true;
        const actif = doc.activeElement;
        if (actif && /^(INPUT|TEXTAREA|SELECT)$/.test(actif.tagName) && actif.type !== 'search') return true;
        try {
            // Caisse : un panier non vide survit au rechargement (stockage), mais ne se recharge pas sous les doigts.
            for (let i = 0; i < win.localStorage.length; i += 1) {
                const cle = win.localStorage.key(i) || '';
                if (cle.indexOf('pos_cart') === 0) {
                    const v = JSON.parse(win.localStorage.getItem(cle) || 'null');
                    const items = v && (v.items || (v.cart && v.cart.items));
                    if (Array.isArray(items) && items.length > 0) return true;
                }
            }
        } catch (e) { /* illisible : on ne bloque pas */ }

        return false;
    };
    const inactif = () => maintenant() - dernierGeste >= INACTIVITE_RECHARGE_MS;

    const tenterRechargement = (force = false) => {
        if (arrete || rechargementDemande === null) return;
        // Un rechargement vient d'être lancé : la page met un instant à revenir, on ne le redemande pas en rafale.
        if (maintenant() - dernierRechargement < 20000) return;
        // `force` (fichier du site introuvable, écran blanc) : l'écran est DÉJÀ cassé, le recharger ne coupe rien d'utile.
        const sur = force ? true : inactif() && !occupe();
        if (!sur) {
            montrerBandeau('🔄 Mise à jour prête — l’écran se rafraîchira dès qu’il est libre');
            if (!minuteurEssai) minuteurEssai = win.setTimeout(() => { minuteurEssai = null; tenterRechargement(false); }, NOUVEL_ESSAI_MS);

            return;
        }
        if (rechargementsRecents().length >= MAX_RECHARGEMENTS) {
            montrerBandeau('⚠️ L’écran n’arrive pas à se mettre à jour');
            return;
        }
        noterRechargement();
        dernierRechargement = maintenant();
        recharger();
    };

    const demanderRechargement = (motif = 'manuel', force = false) => {
        if (arrete) return;
        rechargementDemande = motif;
        tenterRechargement(force);
    };

    /* ---------------- sonde de connexion ---------------- */
    const planifierSonde = (ms) => {
        if (minuteurSonde) win.clearTimeout(minuteurSonde);
        if (arrete) return;
        minuteurSonde = win.setTimeout(() => { sondeMaintenant(); }, ms);
    };

    const sondeMaintenant = async () => {
        if (arrete) return;
        let ok = false;
        try {
            ok = win.navigator && win.navigator.onLine === false ? false : await sonde();
        } catch (e) {
            ok = false;
        }
        if (arrete) return;
        if (ok) {
            const etaitEnPanne = etat !== 'ok';
            const duree = panneDepuis === null ? 0 : maintenant() - panneDepuis;
            echecs = 0;
            etat = 'ok';
            panneDepuis = null;
            dessiner();
            planifierSonde(SONDE_OK_MS);
            if (etaitEnPanne && duree >= PANNE_LONGUE_MS) demanderRechargement('retour-panne');
            else if (etaitEnPanne) retirerBandeau();
        } else {
            echecs += 1;
            if (panneDepuis === null) panneDepuis = maintenant();
            etat = echecs >= ECHECS_AVANT_HORS_LIGNE || (win.navigator && win.navigator.onLine === false) ? 'hors-ligne' : 'reconnexion';
            dessiner();
            planifierSonde(SONDE_ECHEC_MS);
        }
    };

    /* ---------------- nouvelle version ---------------- */
    const verifierVersion = async () => {
        if (arrete) return;
        const courant = await manifeste();
        if (courant === null) return;
        if (baseManifeste === null) { baseManifeste = courant; return; }
        if (courant !== baseManifeste) demanderRechargement('nouvelle-version');
    };

    /* ---------------- gestes (inactivité) ---------------- */
    const geste = () => { dernierGeste = maintenant(); };
    ['pointerdown', 'touchstart', 'keydown', 'wheel', 'mousemove'].forEach((nom) => ecouter(win, nom, geste, { passive: true, capture: true }));

    /* ---------------- réveil / retour réseau ---------------- */
    ecouter(doc, 'visibilitychange', () => {
        if (doc.visibilityState === 'hidden') { masqueDepuis = maintenant(); return; }
        const absence = masqueDepuis === null ? 0 : maintenant() - masqueDepuis;
        masqueDepuis = null;
        dernierGeste = maintenant() - (absence >= REVEIL_LONG_MS ? INACTIVITE_RECHARGE_MS : 0); // réveil de tablette : personne ne s'en sert
        sondeMaintenant();
        verifierVersion();
        if (absence >= REVEIL_LONG_MS) demanderRechargement('reveil');
    });
    ecouter(win, 'online', () => { sondeMaintenant(); });
    ecouter(win, 'offline', () => { sondeMaintenant(); });

    /* ---------------- fichiers du site introuvables (version périmée) ---------------- */
    ecouter(win, 'unhandledrejection', (e) => { if (estErreurDeChunk(e && e.reason)) demanderRechargement('chunk', true); });
    ecouter(win, 'error', (e) => { if (estErreurDeChunk((e && e.error) || (e && e.message))) demanderRechargement('chunk', true); });

    /* ---------------- écran resté blanc ---------------- */
    minuteurBlanc = win.setTimeout(() => {
        const racine = doc.getElementById('app');
        if (racine && racine.children.length === 0) demanderRechargement('ecran-blanc', true);
    }, BLANC_MS);

    /* ---------------- démarrage ---------------- */
    const majApp = o.majApplication === false ? null : surveillerMiseAJourApplication({ win });
    dessiner();
    manifeste().then((m) => { if (baseManifeste === null) baseManifeste = m; }).catch(() => {});
    planifierSonde(SONDE_OK_MS);
    minuteurVersion = win.setInterval(verifierVersion, VERSION_MS);

    return {
        arreter() {
            arrete = true;
            [minuteurSonde, minuteurEssai, minuteurBlanc].forEach((t) => { if (t) win.clearTimeout(t); });
            if (minuteurVersion) win.clearInterval(minuteurVersion);
            ecouteurs.forEach(([c, n, f, op]) => c.removeEventListener(n, f, op));
            retirerBandeau();
            if (majApp) majApp.arreter();
            if (pastille && pastille.parentNode) pastille.parentNode.removeChild(pastille);
        },
        etat: () => etat,
        demanderRechargement,
        sondeMaintenant,
    };
}

export default demarrerGardeDeConnexion;
