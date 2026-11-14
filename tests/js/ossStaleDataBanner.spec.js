import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';
import PreparingAndReadyComponent from '../../resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue';
import ossSyncService from '../../resources/js/services/OssSyncService';

/**
 * [VG-2026-10-21 M6-003 · P0 erreur muette] L'écran de suivi des commandes du PERSONNEL
 * n'écoutait que `sync` et `ws_state` d'OssSyncService. Or le service émet `error` à chaque
 * sondage en échec (401 compris) : sur un jeton expiré (480 min) ou évincé, l'écran gardait des
 * numéros PÉRIMÉS, sans un mot, pendant que la pastille disait « Connecté » (elle ne décrit que
 * le transport WebSocket).
 *
 * Ce banc branche le VRAI singleton du service (seul `start()` est neutralisé pour ne pas armer
 * de minuterie) et émet ses vrais événements : il prouve le CÂBLAGE, pas une copie de la logique.
 */
function traduire(messages) {
    return (cle) => {
        const valeur = cle.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), messages);
        return typeof valeur === 'string' ? valeur : cle;
    };
}

function monter({ authStatus = true } = {}) {
    const store = {
        dispatch: vi.fn().mockResolvedValue({ data: { data: [] } }),
        getters: { authStatus, 'auth/authBranchId': 1 },
        state: { auth: { authBranchId: 1 } },
    };
    const router = { push: vi.fn().mockResolvedValue(undefined) };
    const wrapper = mount(PreparingAndReadyComponent, {
        attachTo: document.body,
        global: {
            mocks: { $store: store, $router: router, $t: traduire(fr) },
            stubs: { LoadingContentComponent: true },
        },
    });
    return { wrapper, store, router };
}

describe('OSS personnel — un sondage en échec se VOIT (M6-003)', () => {
    beforeEach(() => {
        vi.spyOn(ossSyncService, 'start').mockImplementation(() => {});
        vi.spyOn(ossSyncService, 'stop').mockImplementation(() => {});
    });
    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('401 → bandeau role="alert" « Session expirée — reconnexion nécessaire », en français', async () => {
        const { wrapper } = monter();
        await flushPromises();
        expect(wrapper.find('[data-testid="oss-stale-banner"]').exists()).toBe(false);

        ossSyncService._emit('error', { status: 401, backoffMs: 5000 });
        await flushPromises();

        const bandeau = wrapper.find('[data-testid="oss-stale-banner"]');
        expect(bandeau.exists(), 'aucun bandeau sur un 401 : l\'écran se tait').toBe(true);
        expect(bandeau.attributes('role')).toBe('alert');
        expect(bandeau.text()).toContain('Session expirée');
        expect(bandeau.text()).toContain('reconnexion nécessaire');
        // Aucune clé brute affichée (label.xxx non résolu)
        expect(bandeau.text()).not.toMatch(/label\.oss_/);
        wrapper.unmount();
    });

    it('403 → même message de session ; bouton « Se reconnecter » → déconnexion puis écran de connexion', async () => {
        const { wrapper, store, router } = monter();
        await flushPromises();
        ossSyncService._emit('error', { status: 403 });
        await flushPromises();

        const bandeau = wrapper.find('[data-testid="oss-stale-banner"]');
        expect(bandeau.text()).toContain('Session expirée');
        const bouton = wrapper.find('[data-testid="oss-stale-relogin"]');
        expect(bouton.exists()).toBe(true);
        await bouton.trigger('click');
        await flushPromises();
        expect(store.dispatch).toHaveBeenCalledWith('logout');
        expect(router.push).toHaveBeenCalledWith({ name: 'auth.login' });
        wrapper.unmount();
    });

    it('500 ou réseau → « Données non à jour » (sans parler de session), sans bouton de reconnexion', async () => {
        const { wrapper } = monter();
        await flushPromises();
        ossSyncService._emit('error', { status: 500 });
        await flushPromises();
        let bandeau = wrapper.find('[data-testid="oss-stale-banner"]');
        expect(bandeau.exists()).toBe(true);
        expect(bandeau.text()).toContain('Données non à jour');
        expect(bandeau.text()).not.toContain('Session expirée');
        expect(wrapper.find('[data-testid="oss-stale-relogin"]').exists()).toBe(false);

        ossSyncService._emit('error', { status: 0 });
        await flushPromises();
        bandeau = wrapper.find('[data-testid="oss-stale-banner"]');
        expect(bandeau.text()).toContain('Données non à jour');
        wrapper.unmount();
    });

    it('le prochain `sync` réussi efface le bandeau', async () => {
        const { wrapper } = monter();
        await flushPromises();
        ossSyncService._emit('error', { status: 401 });
        await flushPromises();
        expect(wrapper.find('[data-testid="oss-stale-banner"]').exists()).toBe(true);

        ossSyncService._emit('sync', { rows: [], status: 200 });
        await flushPromises();
        expect(wrapper.find('[data-testid="oss-stale-banner"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('lisible à 3 m : texte du bandeau ≥ 32 px (classe), comme les états vides du mur', async () => {
        const { wrapper } = monter();
        await flushPromises();
        ossSyncService._emit('error', { status: 500 });
        await flushPromises();
        const cls = wrapper.find('[data-testid="oss-stale-banner"]').attributes('class') || '';
        const m = cls.match(/text-\[(\d+)px\]/);
        expect(m, 'taille de texte explicite attendue').not.toBeNull();
        expect(Number(m[1])).toBeGreaterThanOrEqual(32);
        wrapper.unmount();
    });

    it('les clés existent en français ET en anglais (l\'anglais n\'est pas cassé)', () => {
        for (const cle of ['oss_stale_data', 'oss_stale_session', 'oss_stale_relogin']) {
            expect(typeof fr.label[cle], `fr label.${cle}`).toBe('string');
            expect(typeof en.label[cle], `en label.${cle}`).toBe('string');
        }
    });
});
