/**
 * [W5-PERF B1 2026-07-06] Sentinelle des paths vuex-persistedstate.
 *
 * Mesuré AVANT : chaque mutation posCart déclenchait la sérialisation de TOUT
 * l'état persisté (clé `vuex`, ~19 Ko) → ~9-12 écritures localStorage ≈
 * 135-228 Ko JSON PAR AJOUT PANIER caisse. Or le module posCart persiste DÉJÀ
 * lui-même chaque mutation sous sa clé scopée caissier
 * `pos_cart_v3:b<branch>:u<user>` (TTL 2 h) et se réhydrate via
 * posCart/setScope (PosComponent.applyPosBranchScope au mount).
 *
 * Contrat verrouillé :
 *   1. "posCart" ABSENT des paths persistedstate (persistence unique = module) ;
 *   2. filter() saute la sérialisation sur les mutations posCart/* ;
 *   3. getState purge un snapshot posCart legacy de la clé `vuex` (jamais de
 *      réhydratation non scopée au boot) ;
 *   4. le module posCart garde bien SA persistence scopée + restore
 *      (pos_cart_v3 / saveCartToStorage / hydrateFromScope) — condition posée
 *      par verdicts.md B1 pour retirer le path ;
 *   5. baseline des autres paths INCHANGÉE (sentinelle anti-régression : en
 *      retirer un = perte de persistence silencieuse, en ajouter un = grossit
 *      chaque écriture).
 */
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const storeIndexSource = readFileSync(
    resolve(process.cwd(), 'resources/js/store/index.js'),
    'utf8',
);
const posCartSource = readFileSync(
    resolve(process.cwd(), 'resources/js/store/modules/posCart.js'),
    'utf8',
);

function extractPaths() {
    // La fermeture réelle du tableau est `],\n        })` — un `]` nu apparaît
    // avant dans les tags de commentaires ([W5-PERF …], [ADR-007 …]).
    const match = storeIndexSource.match(/paths:\s*\[([\s\S]*?)\],\s*\}\)/);
    expect(match, 'bloc paths: [] introuvable dans store/index.js').toBeTruthy();
    // Strip les commentaires (ils citent des chaînes entre guillemets, ex.
    // « "posCart" RETIRÉ », « "Sur place" ») avant de scanner les entrées.
    const withoutComments = match[1]
        .split('\n')
        .map((line) => line.replace(/\/\/.*$/, ''))
        .join('\n');
    return [...withoutComments.matchAll(/"([^"]+)"/g)].map((m) => m[1]);
}

describe('vuex-persistedstate — posCart retiré de la double persistence', () => {
    it('"posCart" ne figure plus dans les paths', () => {
        expect(extractPaths()).not.toContain('posCart');
    });

    it('les mutations posCart/* sont filtrées (aucune sérialisation full-state par ajout)', () => {
        const match = storeIndexSource.match(/filter:\s*(\(mutation\)\s*=>[^\n]+),/);
        expect(match, 'option filter introuvable dans createPersistedState').toBeTruthy();
        // eslint-disable-next-line no-eval
        const filter = eval(match[1]);
        expect(filter({ type: 'posCart/lists' })).toBe(false);
        expect(filter({ type: 'posCart/subtotal' })).toBe(false);
        expect(filter({ type: 'posCart/discount' })).toBe(false);
        expect(filter({ type: 'auth/lists' })).toBe(true);
        expect(filter({ type: 'kioskCart/items' })).toBe(true);
        expect(filter(undefined)).toBe(true); // défensif : mutation absente ⇒ persiste
    });

    it('getState purge un snapshot posCart legacy de la clé vuex (pas de réhydratation non scopée)', () => {
        expect(storeIndexSource).toMatch(/delete parsed\.posCart/);
    });
});

describe('module posCart — la persistence scopée caissier qui remplace le path (verdicts.md B1)', () => {
    it('clé scopée pos_cart_v3:b<branch>:u<user> + TTL', () => {
        expect(posCartSource).toMatch(/pos_cart_v3/);
        expect(posCartSource).toMatch(/POS_CART_TTL_MS/);
    });

    it('restore câblé : setScope → _applyPosCartScope → hydrateFromScope', () => {
        expect(posCartSource).toMatch(/setScope:\s*function/);
        expect(posCartSource).toMatch(/_applyPosCartScope/);
        expect(posCartSource).toMatch(/hydrateFromScope/);
    });

    it('chaque mutation du panier persiste via saveCartToStorage (écriture scopée, pas full-state)', () => {
        const mutationWrites = (posCartSource.match(/saveCartToStorage\(state\)/g) || []).length;
        expect(mutationWrites).toBeGreaterThanOrEqual(8);
    });

    it('applyPosBranchScope résout le userId via les getters VALIDES (bugfix W5 : auth non-namespacé)', () => {
        // Découvert en prouvant le restore e2e : `getters['auth/authInfo']`
        // renvoyait TOUJOURS undefined (module auth non-namespacé) → setScope
        // recevait userId:null → la clé pos_cart_v3 n'était JAMAIS écrite ni
        // relue. Le scope doit résoudre l'id via le getter GLOBAL authInfo
        // et/ou l'état direct — même cascade défensive que authBranchId().
        const posSource = readFileSync(
            resolve(process.cwd(), 'resources/js/components/admin/pos/PosComponent.vue'),
            'utf8',
        );
        const match = posSource.match(/applyPosBranchScope\(branchId\) \{([\s\S]*?)\n {8}\},/);
        expect(match, 'applyPosBranchScope introuvable').toBeTruthy();
        expect(match[1]).toMatch(/getters\.authInfo/);
        expect(match[1]).toMatch(/state\?\.auth\?\.authInfo/);
        expect(match[1]).not.toMatch(/userId:\s*authInfo\.id \|\| null/); // l'ancien câblage mort
    });
});

describe('sentinelle — baseline des paths persistés (verrouillée W5)', () => {
    const BASELINE = [
        'auth',
        'globalState',
        'frontendCart',
        'frontendSignup',
        'GuestSignup',
        'tableCart',
        'kioskCart.branchId',
        'kioskCart.orderRef',
        'kioskCart.queueNumber',
        'kioskCart.idempotencyKey',
        'kioskCart.items',
        'kioskCart.loyaltyDiscount',
        'kioskCart.loyaltyCustomer',
        'kioskCart.orderType',
        'kioskCart.kioskToken',
        'kioskCart.kioskMachineId',
        'kioskSettings.contrast',
        'kioskSettings.pmr',
        'kioskSettings.audio',
        'kioskSettings.keyboardEnabled',
        'kioskSettings.idleMs',
        'kioskSettings.confirmMs',
        'kioskSettings.receiptMs',
        'kioskSettings.consentAnalytics',
        'kioskSettings.consentLoyalty',
    ];

    it('les paths persistés == baseline exacte (ni ajout ni retrait silencieux)', () => {
        expect(extractPaths().sort()).toEqual([...BASELINE].sort());
    });

    it('kioskSettings.locale reste exclu (ADR-007 FR-lock)', () => {
        expect(extractPaths()).not.toContain('kioskSettings.locale');
    });
});
