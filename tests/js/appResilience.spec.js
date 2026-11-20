import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    demarrerGardeDeConnexion, estErreurDeChunk, ecranExclu, libelleEtat,
    SONDE_OK_MS, SONDE_ECHEC_MS, PANNE_LONGUE_MS, INACTIVITE_RECHARGE_MS, MAX_RECHARGEMENTS, VERSION_MS, BLANC_MS, REVEIL_LONG_MS,
} from '../../resources/js/shared/app-resilience.js';

/**
 * [GARDE DE CONNEXION 2026-10-19 · owner « je rafraîchis plusieurs fois pour que ça fonctionne, sur tous les écrans »]
 * Chaque cause de blocage a ici son test : coupure longue, nouvelle version, fichier du site introuvable (chunk), réveil
 * de la tablette, écran blanc — et le garde-fou qui interdit de recharger sous les doigts ou en boucle.
 */

let garde;
let reseau; // le serveur répond-il ?
let manifeste;
let recharger;
const pastille = () => document.querySelector('[data-testid="connexion-pastille"]');

const demarrer = (extra = {}) => {
    garde = demarrerGardeDeConnexion({
        sonde: async () => reseau,
        manifeste: async () => manifeste,
        recharger,
        ...extra,
    });
};
// laisse passer les promesses en attente après avoir avancé l'horloge
const avancer = async (ms) => { await vi.advanceTimersByTimeAsync(ms); };

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<div id="app"><div>écran</div></div>';
    window.sessionStorage.clear();
    window.localStorage.clear();
    reseau = true;
    manifeste = '{"/js/app.js":"/js/app.js?id=AAA"}';
    recharger = vi.fn();
});
afterEach(() => {
    if (garde) garde.arreter();
    garde = null;
    vi.useRealTimers();
});

describe('fonctions pures', () => {
    it('reconnaît un fichier du site introuvable (version périmée)', () => {
        for (const m of ['Loading chunk 123 failed.', 'ChunkLoadError: Loading chunk 9 failed', 'Failed to fetch dynamically imported module: /js/x.js', 'Importing a module script failed.']) {
            expect(estErreurDeChunk(m), m).toBe(true);
        }
        expect(estErreurDeChunk(new Error('Loading chunk 5 failed'))).toBe(true);
        expect(estErreurDeChunk('TypeError: x is undefined')).toBe(false);
        expect(estErreurDeChunk(null)).toBe(false);
    });
    it('la borne est exclue, les autres écrans non', () => {
        expect(ecranExclu('/kiosk/idle')).toBe(true);
        expect(ecranExclu('/kiosk')).toBe(true);
        for (const p of ['/admin/pos', '/kds', '/admin/kitchen-display-system', '/admin/labels/orders']) expect(ecranExclu(p), p).toBe(false);
    });
    it('libellés lisibles', () => {
        expect(libelleEtat('ok')).toBe('Connecté');
        expect(libelleEtat('reconnexion')).toBe('Reconnexion…');
        expect(libelleEtat('hors-ligne', 0)).toBe('Pas de connexion');
        expect(libelleEtat('hors-ligne', 3 * 60000)).toBe('Pas de connexion · 3 min');
    });
});

describe('pastille de connexion', () => {
    it('affiche « Connecté » au démarrage', () => {
        demarrer();
        expect(pastille().textContent).toBe('Connecté');
        expect(pastille().getAttribute('role')).toBe('status');
    });

    it('passe en « Reconnexion… » au 1er échec puis « Pas de connexion » au 3e, et revient seule', async () => {
        demarrer();
        reseau = false;
        await avancer(SONDE_OK_MS);
        expect(garde.etat()).toBe('reconnexion');
        expect(pastille().textContent).toBe('Reconnexion…');
        await avancer(SONDE_ECHEC_MS * 2);
        expect(garde.etat()).toBe('hors-ligne');
        expect(pastille().textContent).toMatch(/^Pas de connexion/);
        reseau = true;
        await avancer(SONDE_ECHEC_MS);
        expect(garde.etat()).toBe('ok');
        expect(pastille().textContent).toBe('Connecté');
    });

    it('ne fait aucune pastille quand on la désactive', () => {
        demarrer({ pastille: false });
        expect(pastille()).toBeNull();
    });
});

describe('retour après une coupure', () => {
    it('coupure COURTE : aucun rechargement', async () => {
        demarrer();
        reseau = false;
        await avancer(SONDE_OK_MS + SONDE_ECHEC_MS * 2);
        reseau = true;
        await avancer(SONDE_ECHEC_MS + INACTIVITE_RECHARGE_MS);
        expect(recharger).not.toHaveBeenCalled();
    });

    it('coupure LONGUE : l\'écran se recharge tout seul quand il est libre', async () => {
        demarrer();
        reseau = false;
        await avancer(SONDE_OK_MS);
        await avancer(PANNE_LONGUE_MS + 10000);
        reseau = true;
        await avancer(SONDE_ECHEC_MS + INACTIVITE_RECHARGE_MS + 1000);
        expect(recharger).toHaveBeenCalledTimes(1);
    });

    it('coupure longue mais écran EN USAGE : jamais de rechargement sous les doigts, un bandeau à la place', async () => {
        demarrer();
        reseau = false;
        // le caissier travaille pendant toute la panne et après : un geste toutes les 20 s
        const toucher = async (ms) => { for (let t = 0; t < ms; t += 20000) { window.dispatchEvent(new Event('pointerdown')); await avancer(20000); } };
        await toucher(SONDE_OK_MS + PANNE_LONGUE_MS + 20000);
        reseau = true;
        await toucher(120000);
        expect(recharger).not.toHaveBeenCalled();
        expect(document.querySelector('[data-testid="resilience-bandeau"]')).not.toBeNull();
    });

    it('…puis recharge dès que plus personne n\'y touche', async () => {
        demarrer();
        reseau = false;
        const toucher = async (ms) => { for (let t = 0; t < ms; t += 20000) { window.dispatchEvent(new Event('pointerdown')); await avancer(20000); } };
        await toucher(SONDE_OK_MS + PANNE_LONGUE_MS + 20000);
        reseau = true;
        await toucher(60000);
        expect(recharger).not.toHaveBeenCalled();
        await avancer(INACTIVITE_RECHARGE_MS + 40000); // plus aucun geste
        expect(recharger).toHaveBeenCalledTimes(1);
    });

    it('une fois le rechargement lancé, il n\'est pas redemandé en rafale (page lente à revenir)', async () => {
        demarrer();
        await avancer(1000);
        manifeste = '{"/js/app.js":"/js/app.js?id=CCC"}';
        await avancer(VERSION_MS + INACTIVITE_RECHARGE_MS + 1000);
        const n = recharger.mock.calls.length;
        await avancer(10000);
        expect(recharger.mock.calls.length).toBe(n);
    });
});

describe('moments où l\'on ne recharge JAMAIS', () => {
    const lancerCoupureLongue = async () => {
        demarrer();
        reseau = false;
        await avancer(SONDE_OK_MS + PANNE_LONGUE_MS + 10000);
        reseau = true;
        await avancer(SONDE_ECHEC_MS + INACTIVITE_RECHARGE_MS + 5000);
    };

    it('fenêtre ouverte (paiement, confirmation)', async () => {
        document.body.insertAdjacentHTML('beforeend', '<div class="modal show"></div>');
        await lancerCoupureLongue();
        expect(recharger).not.toHaveBeenCalled();
    });

    it('écran de paiement caisse', async () => {
        document.body.insertAdjacentHTML('beforeend', '<div data-testid="pos-payment-mode-card"></div>');
        await lancerCoupureLongue();
        expect(recharger).not.toHaveBeenCalled();
    });

    it('saisie en cours dans un champ', async () => {
        document.body.insertAdjacentHTML('beforeend', '<input id="champ" type="text">');
        document.getElementById('champ').focus();
        await lancerCoupureLongue();
        expect(recharger).not.toHaveBeenCalled();
    });

    it('panier de caisse non vide', async () => {
        window.localStorage.setItem('pos_cart_v3:b1:u2', JSON.stringify({ items: [{ id: 1 }] }));
        await lancerCoupureLongue();
        expect(recharger).not.toHaveBeenCalled();
    });

    it('un panier VIDE ne bloque pas', async () => {
        window.localStorage.setItem('pos_cart_v3:b1:u2', JSON.stringify({ items: [] }));
        await lancerCoupureLongue();
        expect(recharger).toHaveBeenCalledTimes(1);
    });
});

describe('nouvelle version', () => {
    it('une nouvelle version publiée recharge l\'écran libre — sans clic', async () => {
        demarrer();
        await avancer(1000);
        manifeste = '{"/js/app.js":"/js/app.js?id=BBB"}';
        await avancer(VERSION_MS + INACTIVITE_RECHARGE_MS + 1000);
        // (≥ 1 : dans la vraie vie la page se recharge et l'état repart de zéro ; ici le simulacre ne recharge rien)
        expect(recharger).toHaveBeenCalled();
    });

    it('même version : rien', async () => {
        demarrer();
        await avancer(VERSION_MS * 5 + INACTIVITE_RECHARGE_MS);
        expect(recharger).not.toHaveBeenCalled();
    });

    it('serveur injoignable pendant la vérification : jamais d\'alerte à tort', async () => {
        demarrer();
        await avancer(1000);
        manifeste = null;
        await avancer(VERSION_MS * 3 + INACTIVITE_RECHARGE_MS);
        expect(recharger).not.toHaveBeenCalled();
    });
});

describe('fichier du site introuvable (version périmée après un déploiement)', () => {
    it('recharge immédiatement, même écran occupé (il est déjà cassé)', async () => {
        document.body.insertAdjacentHTML('beforeend', '<div class="modal show"></div>');
        demarrer();
        const e = new Event('unhandledrejection');
        e.reason = new Error('Loading chunk 42 failed.');
        window.dispatchEvent(e);
        expect(recharger).toHaveBeenCalledTimes(1);
    });

    it('une erreur ordinaire ne recharge rien', () => {
        demarrer();
        const e = new Event('unhandledrejection');
        e.reason = new Error('x is not a function');
        window.dispatchEvent(e);
        expect(recharger).not.toHaveBeenCalled();
    });

    it('garde-fou anti-boucle : au plus 3 rechargements en 10 min (mémorisés entre deux chargements), puis un bandeau', () => {
        for (let i = 0; i < MAX_RECHARGEMENTS + 3; i += 1) {
            // chaque tour = la page vient de se recharger : l'état JS repart de zéro, pas le sessionStorage
            if (garde) garde.arreter();
            demarrer();
            const e = new Event('unhandledrejection');
            e.reason = new Error('Loading chunk 1 failed');
            window.dispatchEvent(e);
        }
        expect(recharger).toHaveBeenCalledTimes(MAX_RECHARGEMENTS);
        expect(document.querySelector('[data-testid="resilience-bandeau"]').textContent).toMatch(/n’arrive pas/);
    });
});

describe('réveil de la tablette et écran blanc', () => {
    it('réveil après une longue veille : recharge l\'écran libre', async () => {
        demarrer();
        Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        await avancer(REVEIL_LONG_MS + 1000);
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        await avancer(1000);
        expect(recharger).toHaveBeenCalledTimes(1);
    });

    it('courte absence : rien', async () => {
        demarrer();
        Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        await avancer(60000);
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        await avancer(INACTIVITE_RECHARGE_MS);
        expect(recharger).not.toHaveBeenCalled();
    });

    it('écran resté blanc : l\'application ne s\'est jamais affichée → rechargement', async () => {
        document.body.innerHTML = '<div id="app"></div>';
        demarrer();
        await avancer(BLANC_MS + 500);
        expect(recharger).toHaveBeenCalledTimes(1);
    });

    it('application affichée : le chien de garde ne fait rien', async () => {
        demarrer();
        await avancer(BLANC_MS + 500);
        expect(recharger).not.toHaveBeenCalled();
    });
});

describe('arrêt propre', () => {
    it('arreter() retire la pastille, le bandeau et toute minuterie', async () => {
        demarrer();
        garde.arreter();
        expect(pastille()).toBeNull();
        reseau = false;
        await avancer(SONDE_OK_MS * 5);
        expect(recharger).not.toHaveBeenCalled();
    });
});

import { versionApplicationNative, estPlusRecente, surveillerMiseAJourApplication } from '../../resources/js/shared/app-resilience.js';

describe('mise à jour de l\'application tablette (dans l\'application, un appui)', () => {
    const UA = 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36 LeCayenneEtiquettes/1.1.0';

    it('lit la version dans l\'agent utilisateur ; un navigateur ordinaire n\'en a pas', () => {
        expect(versionApplicationNative(UA)).toBe('1.1.0');
        expect(versionApplicationNative('Mozilla/5.0 Chrome/120')).toBeNull();
        expect(versionApplicationNative(undefined)).toBeNull();
    });

    it('compare des NOMBRES : 1.10.0 est plus récente que 1.9.0', () => {
        expect(estPlusRecente('1.10.0', '1.9.0')).toBe(true);
        expect(estPlusRecente('1.2.0', '1.1.0')).toBe(true);
        expect(estPlusRecente('1.1.0', '1.1.0')).toBe(false);
        expect(estPlusRecente('1.0.9', '1.1.0')).toBe(false);
        expect(estPlusRecente('2', '1.9.9')).toBe(true);
    });

    it('version plus ancienne : bandeau « Mettre à jour » ; un appui lance le téléchargement', async () => {
        const telecharger = vi.fn();
        const s = surveillerMiseAJourApplication({ userAgent: UA, lire: async () => ({ version_name: '1.2.0', url: '/etiquettes.apk' }), telecharger });
        await vi.advanceTimersByTimeAsync(10);
        const b = document.querySelector('[data-testid="maj-application"]');
        expect(b.textContent).toContain('1.2.0');
        document.querySelector('[data-testid="maj-application-bouton"]').click();
        expect(telecharger).toHaveBeenCalledWith('/etiquettes.apk');
        s.arreter();
        expect(document.querySelector('[data-testid="maj-application"]')).toBeNull();
    });

    it('déjà à jour, ou navigateur ordinaire, ou serveur muet : aucun bandeau', async () => {
        for (const cas of [
            { userAgent: UA, lire: async () => ({ version_name: '1.1.0' }) },
            { userAgent: 'Mozilla/5.0 Chrome/120', lire: async () => ({ version_name: '9.9.9' }) },
            { userAgent: UA, lire: async () => null },
        ]) {
            const s = surveillerMiseAJourApplication(cas);
            await vi.advanceTimersByTimeAsync(10);
            expect(document.querySelector('[data-testid="maj-application"]')).toBeNull();
            s.arreter();
        }
    });

    it('revérifie toutes les 30 minutes (une version publiée pendant que la tablette tourne)', async () => {
        let publie = { version_name: '1.1.0' };
        const s = surveillerMiseAJourApplication({ userAgent: UA, lire: async () => publie });
        await vi.advanceTimersByTimeAsync(10);
        expect(document.querySelector('[data-testid="maj-application"]')).toBeNull();
        publie = { version_name: '1.3.0', url: '/etiquettes.apk' };
        await vi.advanceTimersByTimeAsync(30 * 60 * 1000 + 100);
        expect(document.querySelector('[data-testid="maj-application"]')).not.toBeNull();
        s.arreter();
    });
});
