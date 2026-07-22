import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [UX-PARK-05 2026-07-22] "Écarter" est destructif (DELETE serveur, pas d'undo)
// et colle "Reprendre" dans une grille tactile 2 colonnes → 2-taps inline :
// 1er tap arme le bouton ("Confirmer ?", 3s, rien détruit), 2e tap dans la
// fenêtre dispatch `posParked/discard`, timeout = retour à l'état normal.

// alertService utilise vue-toastification (useToast) → inerte hors app réelle.
vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        default: vi.fn(),
        success: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
}));

import ParkedOrdersComponent from '../../resources/js/components/admin/pos/ParkedOrdersComponent.vue';

const makeStore = (parkedList) => ({
    getters: new Proxy({
        'posParked/list': parkedList,
        'frontendSetting/lists': { site_default_currency_code: 'EUR', site_default_language: 'fr' },
    }, { get(t, p) { return p in t ? t[p] : []; } }),
    dispatch: vi.fn(() => Promise.resolve()),
    commit: vi.fn(),
});

const mountWithStore = (parkedList) => {
    const store = makeStore(parkedList);
    const wrapper = shallowMount({
        ...ParkedOrdersComponent,
        watch: {}, // suppress the open watcher (auto-fetch) — list fed via store
    }, {
        props: { open: true },
        global: {
            stubs: { transition: false },
            mocks: {
                $store: store,
                $t: (key) => key, // clé brute = clé manquante → tf() doit servir le fallback FR
            },
        },
    });

    return { wrapper, store };
};

const discardCalls = (store) => store.dispatch.mock.calls
    .filter(([action]) => action === 'posParked/discard');

describe('ParkedOrdersComponent discard 2-taps confirm (UX-PARK-05)', () => {
    let parked;

    beforeEach(() => {
        vi.useFakeTimers();
        parked = [
            { id: 12, label: 'Famille Dupont', items_count: 3, preview_total: 24.5, created_at: new Date().toISOString() },
            { id: 34, label: 'Comptoir A', items_count: 1, preview_total: 9.9, created_at: new Date().toISOString() },
        ];
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.clearAllMocks();
    });

    it('1er tap : arme le bouton (Confirmer ? + aria-label) sans dispatcher discard', async () => {
        const { wrapper, store } = mountWithStore(parked);

        await wrapper.find('[data-testid="parked-discard-12"]').trigger('click');

        expect(discardCalls(store).length).toBe(0);

        const confirmBtn = wrapper.find('[data-testid="parked-discard-confirm-12"]');
        expect(confirmBtn.exists()).toBe(true);
        expect(confirmBtn.text()).toBe('Confirmer ?');
        expect(confirmBtn.attributes('aria-label')).toBe("Confirmer l'écartement");
        expect(confirmBtn.attributes('disabled')).toBeUndefined(); // reste focusable/cliquable
        expect(confirmBtn.classes()).toContain('parked-orders-action-danger-arm');

        // L'ancien testid ne coexiste pas sur cette carte (même élément, état bascule)
        expect(wrapper.find('[data-testid="parked-discard-12"]').exists()).toBe(false);
        // L'autre carte n'est PAS armée
        expect(wrapper.find('[data-testid="parked-discard-34"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="parked-discard-confirm-34"]').exists()).toBe(false);
    });

    it('2e tap dans la fenêtre : dispatch posParked/discard avec le bon id', async () => {
        const { wrapper, store } = mountWithStore(parked);

        await wrapper.find('[data-testid="parked-discard-12"]').trigger('click');
        await wrapper.find('[data-testid="parked-discard-confirm-12"]').trigger('click');

        expect(discardCalls(store)).toEqual([['posParked/discard', 12]]);
        // Désarmé après exécution
        expect(wrapper.vm.confirmingDiscardId).toBe(null);
    });

    it('timeout 3s sans 2e tap : retour à l\'état normal, aucun dispatch', async () => {
        const { wrapper, store } = mountWithStore(parked);

        await wrapper.find('[data-testid="parked-discard-12"]').trigger('click');
        expect(wrapper.find('[data-testid="parked-discard-confirm-12"]').exists()).toBe(true);

        vi.advanceTimersByTime(3000);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="parked-discard-confirm-12"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="parked-discard-12"]').exists()).toBe(true);
        expect(discardCalls(store).length).toBe(0);

        // Un tap APRÈS expiration ne fait que ré-armer — toujours pas de dispatch
        await wrapper.find('[data-testid="parked-discard-12"]').trigger('click');
        expect(discardCalls(store).length).toBe(0);
        expect(wrapper.find('[data-testid="parked-discard-confirm-12"]').exists()).toBe(true);
    });

    it('armement par-commande : taper Écarter sur une autre carte déplace l\'armement', async () => {
        const { wrapper, store } = mountWithStore(parked);

        await wrapper.find('[data-testid="parked-discard-12"]').trigger('click');
        await wrapper.find('[data-testid="parked-discard-34"]').trigger('click');

        expect(wrapper.find('[data-testid="parked-discard-confirm-12"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="parked-discard-12"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="parked-discard-confirm-34"]').exists()).toBe(true);
        expect(discardCalls(store).length).toBe(0);
    });

    it('unmount pendant la fenêtre : le timer est nettoyé (0 timer orphelin)', async () => {
        const { wrapper } = mountWithStore(parked);

        await wrapper.find('[data-testid="parked-discard-12"]').trigger('click');
        expect(vi.getTimerCount()).toBe(1);

        wrapper.unmount();
        expect(vi.getTimerCount()).toBe(0);
    });
});
