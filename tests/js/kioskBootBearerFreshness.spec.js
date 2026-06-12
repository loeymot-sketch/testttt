/**
 * [W-REM T-R3.2 BORNE-BOOT-401 2026-06-12] Bearer kiosk FRAIS avant la 1re
 * requête authentifiée — voie 100% NON-frozen (KioskAppComponent INTOUCHÉ).
 * -----------------------------------------------------------------------------
 * Constat (P3 boot borne, FINAL_REPORT uiux-caisse-borne) : au boot, Echo est
 * construit avec un snapshot EAGER du token localStorage. Si le token persisté
 * a été révoqué (rotation au relogin précédent / TTL 480 min), la 1re
 * souscription privée part avec ce token mort → `/api/broadcasting/auth` 401
 * one-shot, puis `/api/login` silencieux répare.
 *
 * Triple verrou non-frozen :
 *  1. kioskRoutes.js (requireKioskAuth) : au BOOT (from.name absent) avec
 *     auto-login configuré, on ROTATE le token AVANT next() → tout ce qui
 *     monte (dont la souscription Echo du shell frozen) part avec un bearer
 *     frais. Échec de rotation + token persisté présent → on procède quand
 *     même (dégradé offline, comportement antérieur).
 *  2. bootstrap.js : les wrappers Echo.private/encryptedPrivate/join
 *     rafraîchissent le header Authorization (_refreshEchoAuth) AVANT de
 *     souscrire — le header est lu au moment du subscribe, plus jamais le
 *     snapshot de construction.
 *  3. kioskCart.js SET_KIOSK_TOKEN : passe le token EXPLICITE à
 *     _refreshEchoAuth (vuex-persistedstate écrit localStorage APRÈS la
 *     mutation → le chemin localStorage était stale-by-one, pitfall documenté
 *     bootstrap.js:355).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const mockStore = vi.hoisted(() => ({
    state: { kioskCart: { kioskToken: null, orderRef: null } },
    getters: {
        'kioskCart/isEmpty': true,
        'kioskFilter/hydrated': true,
    },
    dispatch: () => Promise.resolve(),
}));

vi.mock('../../resources/js/store/index.js', () => ({ default: mockStore }));

import kioskRoutes from '../../resources/js/router/modules/kioskRoutes.js';

const kioskRootRoute = kioskRoutes.find((r) => r.path === '/kiosk');

function flush() {
    return new Promise((r) => setTimeout(r, 0));
}

describe('[BORNE-BOOT-401] rotation du bearer au boot (requireKioskAuth)', () => {
    beforeEach(() => {
        mockStore.dispatch = vi.fn().mockResolvedValue();
        mockStore.state.kioskCart.kioskToken = null;
        delete window.foodkingConfig;
    });

    it('boot + auto-login + token persisté (potentiellement révoqué) → kioskLogin AVANT next()', async () => {
        mockStore.state.kioskCart.kioskToken = 'stale-persisted-token';
        window.foodkingConfig = { kioskAutoLogin: { username: 'kiosk-lecayenne', password: 'kiosk123' } };
        const next = vi.fn();
        kioskRootRoute.beforeEnter({ name: 'kiosk.idle' }, { name: undefined }, next);
        await flush();
        expect(mockStore.dispatch).toHaveBeenCalledWith(
            'kioskCart/kioskLogin',
            { username: 'kiosk-lecayenne', password: 'kiosk123' },
        );
        expect(next).toHaveBeenCalledWith();
    });

    it('boot + auto-login + rotation ÉCHOUE + token persisté → next() quand même (dégradé, pas de régression offline)', async () => {
        mockStore.state.kioskCart.kioskToken = 'stale-persisted-token';
        mockStore.dispatch = vi.fn().mockRejectedValue(new Error('offline'));
        window.foodkingConfig = { kioskAutoLogin: { username: 'k', password: 'p' } };
        const next = vi.fn();
        kioskRootRoute.beforeEnter({ name: 'kiosk.idle' }, { name: undefined }, next);
        await flush();
        expect(next).toHaveBeenCalledWith();
    });

    it('boot + auto-login + rotation échoue + AUCUN token → kiosk.login', async () => {
        mockStore.dispatch = vi.fn().mockRejectedValue(new Error('offline'));
        window.foodkingConfig = { kioskAutoLogin: { username: 'k', password: 'p' } };
        const next = vi.fn();
        kioskRootRoute.beforeEnter({ name: 'kiosk.idle' }, { name: undefined }, next);
        await flush();
        expect(next).toHaveBeenCalledWith({ name: 'kiosk.login' });
    });

    it('navigation IN-APP (from nommé) + token → pas de re-login, next() direct', async () => {
        mockStore.state.kioskCart.kioskToken = 'valid-token';
        window.foodkingConfig = { kioskAutoLogin: { username: 'k', password: 'p' } };
        const next = vi.fn();
        kioskRootRoute.beforeEnter({ name: 'kiosk.categories' }, { name: 'kiosk.idle' }, next);
        await flush();
        expect(mockStore.dispatch).not.toHaveBeenCalledWith(
            'kioskCart/kioskLogin',
            expect.anything(),
        );
        expect(next).toHaveBeenCalledWith();
    });

    it('sans auto-login : token → next(), sinon kiosk.login (comportement historique)', async () => {
        const next = vi.fn();
        kioskRootRoute.beforeEnter({ name: 'kiosk.idle' }, { name: undefined }, next);
        await flush();
        expect(next).toHaveBeenCalledWith({ name: 'kiosk.login' });

        mockStore.state.kioskCart.kioskToken = 'tok';
        const next2 = vi.fn();
        kioskRootRoute.beforeEnter({ name: 'kiosk.idle' }, { name: undefined }, next2);
        await flush();
        expect(next2).toHaveBeenCalledWith();
    });
});

describe('[BORNE-BOOT-401] header Echo rafraîchi au moment du subscribe (bootstrap.js)', () => {
    const bootstrapSrc = readFileSync(resolve(process.cwd(), 'resources/js/bootstrap.js'), 'utf-8');

    it.each(['private', 'encryptedPrivate', 'join'])(
        'le wrapper Echo.%s appelle _refreshEchoAuth AVANT la souscription',
        (method) => {
            const re = new RegExp(
                `window\\.Echo\\.${method}\\s*=\\s*\\(\\.\\.\\.args\\)\\s*=>\\s*\\{[\\s\\S]{0,300}?_refreshEchoAuth[\\s\\S]{0,300}?_orig`,
            );
            expect(bootstrapSrc).toMatch(re);
        },
    );
});

describe('[BORNE-BOOT-401] SET_KIOSK_TOKEN passe le token explicite (stale-by-one localStorage)', () => {
    const kioskCartSrc = readFileSync(resolve(process.cwd(), 'resources/js/store/modules/kioskCart.js'), 'utf-8');

    it('_refreshEchoAuth(token) — plus jamais le chemin localStorage stale dans la mutation', () => {
        const mutation = kioskCartSrc.match(/SET_KIOSK_TOKEN\(state,[\s\S]{0,1200}?\n        \},/);
        expect(mutation).toBeTruthy();
        // Strip les commentaires // avant le match négatif (la prose peut
        // citer l'ancien appel sans argument).
        const code = mutation[0].replace(/^\s*\/\/.*$/gm, '');
        expect(code).toMatch(/_refreshEchoAuth\(token\)/);
        expect(code).not.toMatch(/_refreshEchoAuth\(\)/);
    });
});
