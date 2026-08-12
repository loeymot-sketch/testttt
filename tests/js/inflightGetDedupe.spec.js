/**
 * [GOAL-OPS-SWAP W3 2026-08-12 — « trop de requêtes » sur la caisse]
 *
 * MESURE À L'OUVERTURE DE LA CAISSE (Playwright, origine correcte) :
 *   · 35 requêtes en 10 s à l'ouverture · 5 req/min au repos
 *   · dont 7 endpoints appelés DEUX FOIS, à 0-1 ms d'écart :
 *       /api/frontend/setting · /api/admin/default-access
 *       /api/admin/setting/company · /api/admin/pos/counter-collect/pending
 *       /api/admin/pos/web-orders/pending · /api/admin/pos/web-orders/paid
 *       /api/admin/users/address/2
 *
 * 0-1 ms d'écart = strictement simultanés, donc en vol en même temps, donc
 * fusionnables sans rien changer au comportement : les deux appelants
 * reçoivent la même réponse.
 *
 * POURQUOI CELA COMPTE : le plafond `throttle:api` est de 120/min en production
 * et il est PAR COMPTE (`by($request->user()?->id)`), pas par écran. Caisse (35)
 * + un F5 (35) + un second écran sous le même compte (35) = 105 ; le quatrième
 * franchit le mur et le caissier voit « Trop de requêtes ».
 *
 * ⛔ CE QU'IL NE FAUT SURTOUT PAS FAIRE : masquer le message. Il a été ajouté
 *    exprès (`bootstrap.js:52-64`) parce que la caisse avalait « 7+ HTTP 429 en
 *    silence » — classé P0, « le caissier n'avait aucun signal ». On supprime
 *    les requêtes, jamais l'alerte.
 */
import { describe, expect, it, vi } from 'vitest';
import { installInFlightGetDedupe } from '../../resources/js/shared/inflight-dedupe';

/** Faux axios minimal : un adaptateur qu'on peut compter et retarder. */
function faussAxios({ delai = 5, echec = false } = {}) {
    const appels = [];
    const instance = {
        defaults: {
            adapter: (config) => {
                appels.push(`${config.method} ${config.url}`);
                return new Promise((resolve, reject) => {
                    setTimeout(() => {
                        if (echec) {
                            const e = new Error('boum');
                            e.config = config;
                            e.response = { status: 500, data: { message: 'boum' } };
                            reject(e);
                        } else {
                            resolve({ status: 200, data: { valeur: appels.length }, config });
                        }
                    }, delai);
                });
            },
        },
        appels,
    };
    return instance;
}

const envoyer = (ax, config) => ax.defaults.adapter({ method: 'get', ...config });

describe('fusion des GET identiques EN VOL', () => {
    it('deux GET identiques simultanés ne partent QU’UNE fois sur le réseau', async () => {
        const ax = faussAxios();
        installInFlightGetDedupe(ax);

        const [a, b] = await Promise.all([
            envoyer(ax, { url: '/api/admin/setting/company' }),
            envoyer(ax, { url: '/api/admin/setting/company' }),
        ]);

        expect(ax.appels).toHaveLength(1);
        expect(a.data).toEqual(b.data);
        expect(a.status).toBe(200);
        expect(b.status).toBe(200);
    });

    it('NE MET RIEN EN CACHE — une fois la réponse rendue, un nouvel appel repart', async () => {
        const ax = faussAxios();
        installInFlightGetDedupe(ax);

        await envoyer(ax, { url: '/api/admin/pos/web-orders/paid' });
        await envoyer(ax, { url: '/api/admin/pos/web-orders/paid' });

        // Deux appels séquentiels = deux requêtes. Sinon la caisse afficherait
        // des commandes périmées, ce qui serait bien pire que le défaut corrigé.
        expect(ax.appels).toHaveLength(2);
    });

    it('ne fusionne JAMAIS une mutation (POST/PUT/DELETE)', async () => {
        const ax = faussAxios();
        installInFlightGetDedupe(ax);

        await Promise.all([
            ax.defaults.adapter({ method: 'post', url: '/api/admin/pos', data: { a: 1 } }),
            ax.defaults.adapter({ method: 'post', url: '/api/admin/pos', data: { a: 1 } }),
        ]);

        // Fusionner deux encaissements serait une perte de commande.
        expect(ax.appels).toHaveLength(2);
    });

    it('ne confond pas deux URL différant par leurs paramètres', async () => {
        const ax = faussAxios();
        installInFlightGetDedupe(ax);

        await Promise.all([
            envoyer(ax, { url: '/api/admin/item', params: { page: 1 } }),
            envoyer(ax, { url: '/api/admin/item', params: { page: 2 } }),
        ]);

        expect(ax.appels).toHaveLength(2);
    });

    it('propage l’erreur aux DEUX appelants', async () => {
        const ax = faussAxios({ echec: true });
        installInFlightGetDedupe(ax);

        const resultats = await Promise.allSettled([
            envoyer(ax, { url: '/api/admin/oss-order' }),
            envoyer(ax, { url: '/api/admin/oss-order' }),
        ]);

        expect(ax.appels).toHaveLength(1);
        expect(resultats.map((r) => r.status)).toEqual(['rejected', 'rejected']);
        // Un appelant qui reçoit un succès fantôme serait pire que deux requêtes.
        expect(resultats[1].reason?.response?.status).toBe(500);
    });

    it('laisse une échappatoire explicite (__noDedupe)', async () => {
        const ax = faussAxios();
        installInFlightGetDedupe(ax);

        await Promise.all([
            envoyer(ax, { url: '/api/admin/oss-order', __noDedupe: true }),
            envoyer(ax, { url: '/api/admin/oss-order', __noDedupe: true }),
        ]);

        expect(ax.appels).toHaveLength(2);
    });

    it('n’explose pas si l’instance n’a pas d’adaptateur', () => {
        expect(() => installInFlightGetDedupe({})).not.toThrow();
        expect(() => installInFlightGetDedupe(null)).not.toThrow();
    });
});

/**
 * ══ LE BANC QUI MANQUAIT — et son absence a produit une version INERTE ══
 *
 * La première version gardait `typeof axios.defaults.adapter !== 'function'`.
 * Or en axios 1.x cette valeur est un TABLEAU (`["xhr","http","fetch"]`) :
 * l'installation était donc sautée en silence. Les 7 bancs ci-dessus passaient
 * quand même, parce qu'ils utilisaient un faux axios avec une fonction.
 *
 * Preuve du défaut : la mesure Playwright de la rafale d'ouverture est restée
 * à 35 requêtes, strictement inchangée, après « correction ».
 *
 * Ces bancs-ci s'appuient sur le VRAI axios du projet. Sans eux, rien ne
 * garantit qu'une mise à jour d'axios ne rende pas à nouveau le module inerte.
 */
describe('installation sur le VRAI axios (anti-banc-creux)', () => {
    it('s’installe alors que defaults.adapter est un TABLEAU, pas une fonction', async () => {
        const { default: axios } = await import('axios');
        const instance = axios.create();

        // L'état réel du projet : un tableau de noms d'adaptateurs.
        expect(Array.isArray(instance.defaults.adapter) || typeof instance.defaults.adapter === 'string')
            .toBe(true);

        const pose = installInFlightGetDedupe(instance);

        expect(pose).toBe(true);
        expect(typeof instance.defaults.adapter).toBe('function');
        expect(instance.defaults.adapter.__inflightDedupe).toBe(true);
    });

    it('reste idempotent — deux installations n’empilent pas deux enveloppes', async () => {
        const { default: axios } = await import('axios');
        const instance = axios.create();

        installInFlightGetDedupe(instance);
        const premiere = instance.defaults.adapter;
        installInFlightGetDedupe(instance);

        expect(instance.defaults.adapter).toBe(premiere);
    });
});
