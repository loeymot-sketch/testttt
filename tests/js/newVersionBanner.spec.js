import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

/**
 * [Root cause 2026-09-24, owner : "la caisse... ça se met pas à jour tout le
 * temps... lorsque je l'ouvre sur un autre PC, je trouve une autre interface"]
 * Confirmé : aucun mécanisme ne détectait qu'un onglet tournait un ancien
 * bundle JS après un déploiement. Ce module compare périodiquement le vrai
 * mix-manifest.json servi à celui capturé au chargement de l'onglet.
 */
describe('startNewVersionWatcher', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        vi.resetModules();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('ne montre rien tant que le manifest ne change pas', async () => {
        const fetchMock = vi.fn(() => Promise.resolve({ ok: true, text: () => Promise.resolve('{"a":"1"}') }));
        vi.stubGlobal('fetch', fetchMock);
        const { startNewVersionWatcher } = await import('../../resources/js/shared/new-version-banner.js');

        await startNewVersionWatcher();
        await vi.advanceTimersByTimeAsync(3 * 60 * 1000);

        expect(document.querySelector('[data-testid="new-version-banner"]')).toBeNull();
    });

    it('affiche le bandeau quand le manifest a changé (déploiement détecté)', async () => {
        let call = 0;
        const fetchMock = vi.fn(() => {
            call += 1;
            const body = call === 1 ? '{"a":"1"}' : '{"a":"2"}';
            return Promise.resolve({ ok: true, text: () => Promise.resolve(body) });
        });
        vi.stubGlobal('fetch', fetchMock);
        const { startNewVersionWatcher } = await import('../../resources/js/shared/new-version-banner.js');

        await startNewVersionWatcher();
        await vi.advanceTimersByTimeAsync(3 * 60 * 1000);

        const banner = document.querySelector('[data-testid="new-version-banner"]');
        expect(banner, 'un bandeau doit apparaître dès qu\'un déploiement est détecté').not.toBeNull();
        expect(banner.textContent).toContain('Nouvelle version');
    });

    it('le bouton "Rafraîchir" recharge la page — jamais de rechargement automatique forcé', async () => {
        let call = 0;
        const fetchMock = vi.fn(() => {
            call += 1;
            const body = call === 1 ? '{"a":"1"}' : '{"a":"2"}';
            return Promise.resolve({ ok: true, text: () => Promise.resolve(body) });
        });
        vi.stubGlobal('fetch', fetchMock);
        const reload = vi.fn();
        vi.stubGlobal('location', { ...window.location, reload });
        const { startNewVersionWatcher } = await import('../../resources/js/shared/new-version-banner.js');

        await startNewVersionWatcher();
        await vi.advanceTimersByTimeAsync(3 * 60 * 1000);

        expect(reload, 'aucun rechargement automatique — seul un clic explicite doit recharger').not.toHaveBeenCalled();

        const button = document.querySelector('[data-testid="new-version-banner"] button');
        button.click();
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('une erreur réseau au premier appel ne montre jamais le bandeau à tort', async () => {
        const fetchMock = vi.fn(() => Promise.reject(new Error('network down')));
        vi.stubGlobal('fetch', fetchMock);
        const { startNewVersionWatcher } = await import('../../resources/js/shared/new-version-banner.js');

        await startNewVersionWatcher();
        await vi.advanceTimersByTimeAsync(3 * 60 * 1000);

        expect(document.querySelector('[data-testid="new-version-banner"]')).toBeNull();
    });
});
