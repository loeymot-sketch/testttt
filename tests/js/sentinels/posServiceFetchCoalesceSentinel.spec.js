/**
 * posServiceFetchCoalesceSentinel.spec.js
 *
 * ORIGINE — POSPERF-03-dup-oss (2026-07-22), sous le nom `posOssFetchCoalesceSentinel`.
 * `loadActiveOrdersStats()` et `loadReadyOrders()` tapaient le MÊME endpoint et étaient
 * appelées dos à dos à chaque tick de polling ET à chaque rafale Echo → DEUX GET identiques
 * par cycle. `_fetchOssOrdersOnce()` les fondait en une seule requête en vol.
 *
 * RENOMMÉ ET RECIBLÉ — GOAL CAISSE CONTRÔLE (2026-09-02).
 * La SOURCE a changé, la mécanique NON. La caisse ne lit plus `admin/oss-order` (l'écran de
 * statut client, dont le service filtre par type BORNE + À EMPORTER) mais `admin/pos-order`
 * borné à la journée de service — tous les canaux, avec la composition compacte.
 *
 * Pourquoi ce banc devait changer de nom plutôt que rester vert : il MIROITAIT le corps de la
 * méthode. Laissé tel quel, il aurait continué d'affirmer qu'un `orderStatusScreenOrder/lists`
 * était coalescé alors que plus aucune ligne de production ne l'appelle — un banc vert sur un
 * périmètre mort, c'est-à-dire pire que pas de banc.
 *
 * CE QUE CE BANC PROTÈGE, ET QUI N'A PAS CHANGÉ :
 *   1. une seule requête réseau par cycle, partagée par les deux consommateurs ;
 *   2. le créneau est libéré au règlement, succès COMME échec (sinon le poll reste bloqué) ;
 *   3. les deux chargeurs passent par le coalesceur et ne redispatchent jamais en direct.
 *
 * RECIBLÉ À NOUVEAU — GOAL G1 (2026-09-03).
 * Le point 4 ci-dessous épinglait `paginate` + `per_page: 100`. Cette borne CLIENT, posée sur le
 * tri `id desc` par défaut d'`OrderService::list`, jetait les commandes les PLUS ANCIENNES du
 * service dès la 101ᵉ — celles qui traînent, celles que le tiroir de contrôle existe pour
 * montrer — et rien ne le signalait. La borne vit maintenant côté SERVEUR
 * (`PosOrderController::serviceDay` : journée de service + états affichés), et elle s'avoue
 * (`meta.total` / `meta.truncated`). Épingler encore le plafond client aurait verrouillé le défaut.
 *
 * CE QU'IL PROTÈGE EN PLUS depuis 2026-09-02 :
 *   4. la requête ne porte AUCUN plafond d'affichage côté client ;
 *   5. elle demande la COMPOSITION (le tiroir de contrôle montre le contenu des commandes) ;
 *   6. elle ne commite PAS dans Vuex (`vuex: false`) : le store `posOrder/lists` appartient au
 *      tableau de suivi, la caisse n'a pas à le lui écraser sous les pieds.
 *
 * @FK-ID  POSPERF-03-dup-oss · GOAL-CAISSE-CONTROLE-2026-09-02
 */
import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SOURCE = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
    'utf8',
);

// Miroir verbatim de `_fetchServiceOrdersOnce`. Les assertions sur la source, plus bas,
// rougissent si la production s'écarte de cette forme.
function makeFetchOnce() {
    return function _fetchServiceOrdersOnce() {
        if (this._serviceFetchInFlight) {
            return this._serviceFetchInFlight;
        }
        const p = this.$store.dispatch('posOrder/serviceDay', {
            composition: 1,
            vuex: false,
        });
        this._serviceFetchInFlight = p;
        const release = () => {
            if (this._serviceFetchInFlight === p) {
                this._serviceFetchInFlight = null;
            }
        };
        p.then(release, release);
        return p;
    };
}

const contexte = (dispatch) => ({
    _serviceFetchInFlight: null,
    $store: { dispatch },
});

describe('PosComponent._fetchServiceOrdersOnce — dédoublonnage POSPERF-03', () => {
    it('deux appelants simultanés (statistiques + commandes prêtes) partagent UN SEUL envoi', async () => {
        let resolveFn;
        const dispatch = vi.fn(() => new Promise((r) => { resolveFn = r; }));
        const ctx = contexte(dispatch);
        const fn = makeFetchOnce();

        const a = fn.call(ctx); // chemin loadActiveOrdersStats
        const b = fn.call(ctx); // chemin loadReadyOrders, même tick synchrone

        expect(dispatch).toHaveBeenCalledTimes(1);
        expect(a).toBe(b);

        resolveFn({ data: { data: [] } });
        await a;
        expect(ctx._serviceFetchInFlight).toBeNull(); // créneau libéré au règlement
    });

    it('interroge le flux borné SERVEUR à la journée de service, sans plafond client', () => {
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: [] } }));
        const ctx = contexte(dispatch);
        makeFetchOnce().call(ctx);

        expect(dispatch).toHaveBeenCalledWith('posOrder/serviceDay', expect.objectContaining({
            composition: 1,
            vuex: false,
        }));
        // [GOAL G1 2026-09-03] Aucun plafond d'affichage ne repart du client : c'est lui qui
        // jetait les commandes les plus anciennes du service, en silence.
        const params = dispatch.mock.calls[0][1];
        expect(params.per_page).toBeUndefined();
        expect(params.paginate).toBeUndefined();
    });

    it('le tick suivant redemande — la coalescence ne franchit jamais un cycle', async () => {
        let n = 0;
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: [], _n: ++n } }));
        const ctx = contexte(dispatch);
        const fn = makeFetchOnce();

        await fn.call(ctx);
        await fn.call(ctx);
        expect(dispatch).toHaveBeenCalledTimes(2);
    });

    it('un envoi rejeté libère quand même le créneau (jamais de poll bloqué)', async () => {
        const dispatch = vi.fn(() => Promise.reject(new Error('boum')));
        const ctx = contexte(dispatch);
        const fn = makeFetchOnce();

        await fn.call(ctx).catch(() => {});
        expect(ctx._serviceFetchInFlight).toBeNull();
    });
});

describe('PosComponent.vue — câblage réel', () => {
    it('définit _fetchServiceOrdersOnce avec sa garde « requête en vol »', () => {
        expect(SOURCE).toMatch(/_fetchServiceOrdersOnce\s*\(\)\s*\{[\s\S]*?this\._serviceFetchInFlight[\s\S]*?\}/);
    });

    it('la requête est bornée SERVEUR, avec composition, sans plafond client ni commit Vuex', () => {
        const corps = SOURCE.match(/_fetchServiceOrdersOnce\s*\(\)\s*\{[\s\S]+?\n {8}\},/);
        expect(corps).not.toBeNull();
        expect(corps[0]).toMatch(/posOrder\/serviceDay/);
        expect(corps[0]).toMatch(/composition:\s*1/);
        expect(corps[0]).toMatch(/vuex:\s*false/);
        // [GOAL G1 2026-09-03] Le plafond client est mort et doit le rester : `per_page: 100`
        // sur un tri `id desc` jetait les commandes les PLUS ANCIENNES du service — celles qui
        // traînent — sans rien signaler. La borne vit désormais côté serveur, et elle s'avoue.
        // Ancré en début de ligne : la charge envoyée, jamais un mot cité dans un commentaire.
        expect(corps[0]).not.toMatch(/^\s*per_page\s*:/m);
        expect(corps[0]).not.toMatch(/^\s*paginate\s*:/m);
    });

    it('loadActiveOrdersStats passe par le coalesceur, jamais par un dispatch direct', () => {
        const corps = SOURCE.match(/async loadActiveOrdersStats\s*\(\)\s*\{[\s\S]+?\n {8}\},/);
        expect(corps).not.toBeNull();
        expect(corps[0]).toMatch(/this\._fetchServiceOrdersOnce\s*\(\s*\)/);
        expect(corps[0]).not.toMatch(/\$store\.dispatch\(/);
    });

    it('loadReadyOrders passe par le coalesceur, jamais par un dispatch direct', () => {
        const corps = SOURCE.match(/async loadReadyOrders\s*\(\)\s*\{[\s\S]+?\n {8}\},/);
        expect(corps).not.toBeNull();
        expect(corps[0]).toMatch(/this\._fetchServiceOrdersOnce\s*\(\s*\)/);
        expect(corps[0]).not.toMatch(/\$store\.dispatch\(/);
    });

    it('plus aucune lecture de admin/oss-order ne subsiste dans la caisse', () => {
        // C'est le flux qui rendait TROIS commandes sur neuf : son service filtre par type
        // (borne + à emporter), d'où la commande comptoir prête invisible et le badge « 3 »
        // affiché à 40 px d'un tableau qui annonçait « 7 actives ».
        expect(SOURCE).not.toMatch(/\$store\.dispatch\(\s*['"]orderStatusScreenOrder\/lists/);
        expect(SOURCE).not.toMatch(/getters\[\s*['"]orderStatusScreenOrder\/lists/);
    });

    it('les commandes prêtes ne sont plus filtrées par TYPE de commande', () => {
        // Le filtre existait parce que le flux amont n'apportait que borne + à emporter : il ne
        // retirait donc rien. Avec la nouvelle source, il AURAIT caché les commandes comptoir,
        // téléphone et web prêtes — soit exactement le défaut qu'on vient de corriger.
        const corps = SOURCE.match(/async loadReadyOrders\s*\(\)\s*\{[\s\S]+?\n {8}\},/);
        expect(corps[0]).not.toMatch(/allowedTypes/);
        expect(corps[0]).not.toMatch(/orderTypeEnum\./);
        // Le remboursement passerelle, lui, reste exclu : il garde souvent son statut cuisine.
        expect(corps[0]).toMatch(/paymentStatusEnum\.REFUNDED/);
    });
});
