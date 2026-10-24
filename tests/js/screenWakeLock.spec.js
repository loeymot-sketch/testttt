/**
 * [ETIQUETTES 2026-10-16] Écran maintenu allumé pendant l'impression automatique : une tablette posée qui
 * s'endort ralentit les minuteries de Chrome, et l'impression automatique s'arrêterait sans bruit.
 */
import { describe, it, expect } from 'vitest';
import { createWakeLock } from '../../resources/js/helpers/screenWakeLock.js';

function fakeNav() {
    const calls = { request: 0, released: 0 };
    const sentinels = [];
    const nav = {
        wakeLock: {
            async request(type) {
                calls.request += 1;
                expect(type).toBe('screen');
                const handlers = {};
                const s = {
                    addEventListener: (n, fn) => { handlers[n] = fn; },
                    release: async () => { calls.released += 1; handlers.release && handlers.release(); },
                    _drop: () => handlers.release && handlers.release(),
                };
                sentinels.push(s);
                return s;
            },
        },
    };
    return { nav, calls, sentinels };
}

function fakeDoc() {
    const handlers = {};
    return {
        visibilityState: 'visible',
        addEventListener: (n, fn) => { handlers[n] = fn; },
        removeEventListener: (n) => { delete handlers[n]; },
        fire: (n) => handlers[n] && handlers[n](),
        has: (n) => Boolean(handlers[n]),
    };
}

describe('screenWakeLock', () => {
    it('sans API : non supporté, aucune exception, acquire() renvoie false', async () => {
        const w = createWakeLock({}, fakeDoc());
        expect(w.isSupported()).toBe(false);
        expect(await w.acquire()).toBe(false);
        await w.release();
        expect(w.isActive()).toBe(false);
    });

    it('acquire verrouille l’écran, release le relâche', async () => {
        const { nav, calls } = fakeNav();
        const w = createWakeLock(nav, fakeDoc());
        expect(await w.acquire()).toBe(true);
        expect(w.isActive()).toBe(true);
        await w.release();
        expect(w.isActive()).toBe(false);
        expect(calls.released).toBe(1);
    });

    it('un deuxième acquire ne demande pas un deuxième verrou', async () => {
        const { nav, calls } = fakeNav();
        const w = createWakeLock(nav, fakeDoc());
        await w.acquire();
        await w.acquire();
        expect(calls.request).toBe(1);
    });

    it('le navigateur relâche le verrou en arrière‑plan : il est repris au retour sur l’onglet', async () => {
        const { nav, calls, sentinels } = fakeNav();
        const doc = fakeDoc();
        const w = createWakeLock(nav, doc);
        await w.acquire();
        sentinels[0]._drop(); // relâché par le navigateur
        expect(w.isActive()).toBe(false);
        doc.fire('visibilitychange');
        await new Promise((r) => setTimeout(r, 0));
        expect(calls.request).toBe(2);
        expect(w.isActive()).toBe(true);
    });

    it('après release, le retour sur l’onglet ne reprend plus rien', async () => {
        const { nav, calls } = fakeNav();
        const doc = fakeDoc();
        const w = createWakeLock(nav, doc);
        await w.acquire();
        await w.release();
        expect(doc.has('visibilitychange')).toBe(false);
        doc.fire('visibilitychange');
        expect(calls.request).toBe(1);
    });

    it('un refus du navigateur (batterie faible) ne lève pas', async () => {
        const nav = { wakeLock: { request: async () => { throw new Error('NotAllowedError'); } } };
        const w = createWakeLock(nav, fakeDoc());
        expect(await w.acquire()).toBe(false);
        expect(w.isActive()).toBe(false);
    });

    it('release() pendant que le navigateur répond encore : le verrou arrivé en retard est relâché aussitôt', async () => {
        let resolveRequest;
        const sentinel = { addEventListener() {}, released: 0, async release() { this.released += 1; } };
        const nav = { wakeLock: { request: () => new Promise((r) => { resolveRequest = () => r(sentinel); }) } };
        const w = createWakeLock(nav, fakeDoc());
        const acquiring = w.acquire();
        await w.release();
        resolveRequest();
        await acquiring;
        expect(w.isActive()).toBe(false);
        expect(sentinel.released).toBe(1);
    });
});
