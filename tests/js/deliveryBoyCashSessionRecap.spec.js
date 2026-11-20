import { describe, it, expect, vi, beforeEach } from 'vitest';
import axios from 'axios';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import Show from '../../resources/js/components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionShowComponent.vue';
import fr from '../../resources/js/languages/fr.json';
import en from '../../resources/js/languages/en.json';

/**
 * [LIVREUR CAISSE 2026-11-02] La fiche d'un service livreur (administration) montre le FOND DE DÉBUT, la répartition par moyen
 * (espèces / carte / titres-restaurant), les espèces attendues, les espèces comptées et l'ÉCART à la clôture.
 */
vi.mock('axios', () => ({ default: { get: vi.fn() } }));

const recap = {
    session_id: 7, status: 'closed', opening_amount: 50,
    by_mode: { cash: { amount: 37.5, count: 2 }, card: { amount: 37.5, count: 2 }, meal_voucher: { amount: 35, count: 2 } },
    total_collected: 110, deliveries_settled: 4, deliveries_delivered: 5,
    expected_cash: 87.5, counted_cash: 85, variance: -2.5, ledger_gap: 0,
    orders: [
        { order_id: 11, order_number: '0011', delivered_at: '2026-11-02 12:10:00', total: 27.5, paid_online: false, payments: [{ mode: 'cash', label: 'Espèces', amount: 10 }, { mode: 'card', label: 'Carte', amount: 17.5 }] },
        { order_id: 12, order_number: '0012', delivered_at: '2026-11-02 12:40:00', total: 22, paid_online: true, payments: [] },
    ],
};

function session(over = {}) {
    return {
        id: 7, branch_id: 1, delivery_boy_id: 9, status: 'closed', opening_amount: 50, closing_amount: 85,
        expected_closing_amount: null, variance: null, variance_reason: null, opened_at: null, closed_at: null,
        recap, movements: [], ...over,
    };
}

function mountShow(data) {
    axios.get.mockResolvedValue({ data: { data } });
    const i18n = createI18n({ legacy: true, locale: 'fr', messages: { fr, en } });
    return mount(Show, {
        global: { plugins: [i18n], stubs: { LoadingComponent: true, DeliveryBoyCashSessionFormComponent: true } },
        props: { sessionId: 7, canMutate: false },
    });
}

describe('DeliveryBoyCashSessionShowComponent — récap par moyen', () => {
    beforeEach(() => axios.get.mockReset());

    it('affiche le fond de début, les trois moyens, les espèces attendues, comptées et l\'écart', async () => {
        const w = mountShow(session());
        await flushPromises();
        const txt = (id) => w.find(`[data-testid="${id}"]`).text().replace(/\s/g, ' ');
        expect(txt('delivery-cash-recap-opening')).toContain('Fond de début');
        expect(txt('delivery-cash-recap-opening')).toMatch(/50,00/);
        expect(txt('delivery-cash-recap-cash')).toMatch(/Espèces.*37,50/);
        expect(txt('delivery-cash-recap-card')).toMatch(/Carte.*37,50/);
        expect(txt('delivery-cash-recap-meal_voucher')).toMatch(/Titres-restaurant.*35,00/);
        expect(txt('delivery-cash-recap-total')).toMatch(/110,00/);
        expect(txt('delivery-cash-recap-expected')).toMatch(/87,50/);
        expect(txt('delivery-cash-recap-counted')).toMatch(/85,00/);
        expect(txt('delivery-cash-recap-variance')).toMatch(/2,50/);
        expect(txt('delivery-cash-recap-variance')).toContain('Il manque de l\'argent');
        expect(w.find('[data-testid="delivery-cash-recap-order-11"]').text()).toMatch(/Espèces.*10,00.*Carte.*17,50/);
        expect(w.find('[data-testid="delivery-cash-recap-order-12"]').text()).toContain('Payée en ligne');
        expect(w.text()).not.toMatch(/label\.delivery_cash|undefined|NaN/); // aucun libellé brut
    });

    it('montre l\'écart en direct quand le service est clôturé par le livreur mais pas encore réconcilié', async () => {
        const w = mountShow(session({ variance: null }));
        await flushPromises();
        expect(w.find('[data-testid="delivery-cash-variance-live"]').exists()).toBe(true);
        expect(w.find('[data-testid="delivery-cash-variance-live"]').text()).toMatch(/2,50/);
    });

    it('une fiche sans récap (liste légère) n\'affiche pas le bloc', async () => {
        const w = mountShow(session({ recap: undefined }));
        await flushPromises();
        expect(w.find('[data-testid="delivery-cash-recap"]').exists()).toBe(false);
    });

    it('avertit quand les espèces des commandes ne concordent pas avec le livre de caisse', async () => {
        const w = mountShow(session({ recap: { ...recap, ledger_gap: 10 } }));
        await flushPromises();
        expect(w.find('[data-testid="delivery-cash-recap-gap"]').exists()).toBe(true);
    });
});
