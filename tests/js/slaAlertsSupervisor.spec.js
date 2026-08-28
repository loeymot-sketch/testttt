import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

import SlaAlertsComponent from '../../resources/js/components/admin/dashboard/SlaAlertsComponent.vue';

const response = (alerts) => ({ data: { data: alerts } });
const alert = (wait, serial = `S-${wait}`) => ({
    order_serial_no: serial,
    queue_number: `Q-${wait}`,
    time_preparing: wait,
    customer: `Client ${wait}`,
});

let wrapper;

const mountCockpit = (dispatch) => {
    wrapper = mount(SlaAlertsComponent, {
        global: {
            mocks: {
                $store: { dispatch },
            },
        },
    });
    return wrapper;
};

describe('SlaAlertsComponent — supervision fiable', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        vi.useRealTimers();
    });

    it('affiche un état de chargement au premier contrôle, sans faux état vide', async () => {
        let resolveRequest;
        const dispatch = vi.fn(() => new Promise((resolve) => { resolveRequest = resolve; }));
        mountCockpit(dispatch);

        expect(wrapper.get('[data-testid="sla-loading"]').text()).toContain('Contrôle');
        expect(wrapper.find('[data-testid="sla-empty"]').exists()).toBe(false);

        resolveRequest(response([]));
        await flushPromises();
        expect(wrapper.find('[data-testid="sla-loading"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="sla-empty"]').text()).toContain('Aucune préparation');
    });

    it('rend une erreur actionnable quand aucun relevé fiable n’existe', async () => {
        const dispatch = vi.fn().mockRejectedValue(new Error('network down'));
        mountCockpit(dispatch);
        await flushPromises();

        expect(wrapper.get('[data-testid="sla-error"]').text()).toContain('indisponible');
        expect(wrapper.get('[data-testid="sla-error"] button').text()).toBe('Réessayer');
        expect(wrapper.find('[data-testid="sla-empty"]').exists()).toBe(false);
    });

    it('classe les urgences par ancienneté, calcule la synthèse et borne le DOM à six lignes', async () => {
        const alerts = [18, 92, 31, 16, 45, 28, 67, 22].map((wait) => alert(wait));
        mountCockpit(vi.fn().mockResolvedValue(response(alerts)));
        await flushPromises();

        expect(wrapper.get('[data-testid="sla-alert-count"]').text()).toBe('8');
        expect(wrapper.get('[data-testid="sla-urgent-count"]').text()).toBe('4');
        expect(wrapper.get('[data-testid="sla-oldest-wait"]').text()).toBe('1 h 32 min');
        expect(wrapper.findAll('[data-testid="sla-alert-row"]')).toHaveLength(6);
        expect(wrapper.findAll('[data-testid="sla-alert-row"]')[0].text()).toContain('Q-92');
        expect(wrapper.get('[data-testid="sla-hidden-count"]').text()).toContain('+ 2');
    });

    it('conserve le dernier relevé mais retire toute promesse de fraîcheur après un échec', async () => {
        const dispatch = vi.fn()
            .mockResolvedValueOnce(response([alert(41)]))
            .mockRejectedValueOnce({ response: { data: { message: 'Sonde indisponible' } } });
        mountCockpit(dispatch);
        await flushPromises();

        await wrapper.get('button[aria-label="Actualiser les alertes SLA"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="sla-stale-warning"]').text()).toContain('Dernier relevé conservé');
        expect(wrapper.findAll('[data-testid="sla-alert-row"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="sla-empty"]').exists()).toBe(false);
    });

    it('signale un relevé périmé au-delà de 45 secondes', async () => {
        mountCockpit(vi.fn().mockResolvedValue(response([alert(20)])));
        await flushPromises();

        wrapper.vm.clock = wrapper.vm.lastSuccessfulAt + 46_000;
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.isStale).toBe(true);
        expect(wrapper.get('[data-testid="sla-stale-warning"]').text()).toContain('Relevé ancien');
        expect(wrapper.get('[data-testid="sla-freshness"]').text()).toContain('Dernier relevé');
    });

    it('actualise automatiquement toutes les quinze secondes et expose un bouton nommé', async () => {
        const dispatch = vi.fn().mockResolvedValue(response([]));
        mountCockpit(dispatch);
        await flushPromises();

        const refreshButton = wrapper.get('button[aria-label="Actualiser les alertes SLA"]');
        expect(refreshButton.attributes('type')).toBe('button');
        expect(refreshButton.get('i').classes()).toEqual(
            expect.arrayContaining(['fa-solid', 'fa-arrows-rotate'])
        );
        expect(refreshButton.get('i').classes()).not.toContain('lab-refresh');
        await vi.advanceTimersByTimeAsync(15_000);
        await flushPromises();
        expect(dispatch).toHaveBeenCalledTimes(2);
    });

    it('n’empile aucune requête quand une sonde dépasse l’intervalle de rafraîchissement', async () => {
        let resolveFirst;
        const dispatch = vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }))
            .mockResolvedValue(response([alert(19)]));
        mountCockpit(dispatch);

        await vi.advanceTimersByTimeAsync(30_000);
        expect(dispatch).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.refreshing).toBe(true);

        resolveFirst(response([alert(48)]));
        await flushPromises();
        expect(wrapper.vm.refreshing).toBe(false);
        expect(wrapper.get('[data-testid="sla-oldest-wait"]').text()).toBe('48 min');

        await vi.advanceTimersByTimeAsync(15_000);
        await flushPromises();
        expect(dispatch).toHaveBeenCalledTimes(2);
    });
});
