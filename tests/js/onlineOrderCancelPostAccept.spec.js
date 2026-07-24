import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount, mount } from '@vue/test-utils';

/**
 * [P2-2 2026-07-24] Refuser/annuler une commande web APRÈS acceptation.
 * Source: reports/goal-global-validation-2026-07-24/ACCES-caisse-gestion-findings.md
 *
 * Avant le heal : le bloc refus-avec-motif (OnlineOrderReasonComponent) n'était rendu
 * que si status===PENDING → une web ACCEPTÉE non-finale n'avait plus AUCUN bouton pour
 * l'annuler depuis l'écran « Détails » (seul le tracker POS le permettait).
 *
 * Après : un CTA « Annuler (motif) » (OnlineOrderReasonComponent réutilisé, status=CANCELED)
 * apparaît pour une commande dans un statut ACTIF non-terminal (ACCEPT/PREPARING/PREPARED/
 * OUT_FOR_DELIVERY), respecte la garde D-1 (jamais depuis un statut terminal ni DELIVERED),
 * et le motif est ré-affiché pour CANCELED comme pour REJECTED.
 */

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        modalShow: vi.fn(),
        modalHide: vi.fn(),
        statusClass: () => '',
        orderStatusClass: () => '',
        textShortener: (t) => t || '',
        acceptOrder: vi.fn(() => Promise.resolve()),
        confirmCashPayment: vi.fn(() => Promise.resolve()),
    },
}));

vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn(), successFlip: vi.fn(),
    },
}));

import OnlineOrderShowComponent from '../../resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue';
import OnlineOrderReasonComponent from '../../resources/js/components/admin/onlineOrders/OnlineOrderReasonComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';
import orderTypeEnum from '../../resources/js/enums/modules/orderTypeEnum';
import paymentStatusEnum from '../../resources/js/enums/modules/paymentStatusEnum';

const baseOrder = (status) => ({
    id: 42,
    order_serial_no: 'A0042',
    order_datetime: '2026-07-24 12:00',
    status,
    payment_status: paymentStatusEnum.UNPAID,
    payment_method: 1,
    order_type: orderTypeEnum.TAKEAWAY,
    transaction: null,
    is_advance_order: 0,
    reason: 'Client parti sans payer',
    subtotal_currency_price: '10,00 €',
    discount_currency_price: '0,00 €',
    delivery_charge_currency_price: '0,00 €',
    total_currency_price: '10,00 €',
    delivery_date: '', delivery_time: '',
});

const makeStore = (order) => ({
    getters: new Proxy({
        'onlineOrder/show': order,
        'onlineOrder/orderItems': [],
        'onlineOrder/orderUser': { name: 'Jean', email: 'j@x.fr', image: '' },
        'onlineOrder/orderAddress': {},
        'deliveryBoy/lists': [],
    }, { get(t, p) { return p in t ? t[p] : undefined; } }),
    dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })),
});

const mountShow = (status) => {
    const store = makeStore(baseOrder(status));
    const Test = { ...OnlineOrderShowComponent, mounted() {} };
    return shallowMount(Test, {
        global: {
            mocks: {
                $store: store,
                $t: (k) => k,
                $route: { params: { id: 42 } },
                $router: { push: vi.fn() },
            },
        },
    });
};

// The reused reason component with status=CANCELED is the "cancel CTA".
const cancelCtas = (wrapper) =>
    wrapper.findAllComponents(OnlineOrderReasonComponent)
        .filter((c) => Number(c.props('status')) === orderStatusEnum.CANCELED);

const rejectCtas = (wrapper) =>
    wrapper.findAllComponents(OnlineOrderReasonComponent)
        .filter((c) => Number(c.props('status')) === orderStatusEnum.REJECTED);

describe('P2-2 — cancel CTA on OnlineOrderShow for accepted (active non-terminal) web orders', () => {
    it.each([
        ['ACCEPT', orderStatusEnum.ACCEPT],
        ['PREPARING', orderStatusEnum.PREPARING],
        ['PREPARED', orderStatusEnum.PREPARED],
        ['OUT_FOR_DELIVERY', orderStatusEnum.OUT_FOR_DELIVERY],
    ])('shows the cancel-with-reason CTA when status is %s', (_name, status) => {
        const wrapper = mountShow(status);
        expect(cancelCtas(wrapper).length).toBe(1);
    });

    it('does NOT show the cancel CTA for DELIVERED (completed, D-1 guard)', () => {
        const wrapper = mountShow(orderStatusEnum.DELIVERED);
        expect(cancelCtas(wrapper).length).toBe(0);
    });

    it.each([
        ['CANCELED', orderStatusEnum.CANCELED],
        ['REJECTED', orderStatusEnum.REJECTED],
        ['RETURNED', orderStatusEnum.RETURNED],
    ])('does NOT show the cancel CTA for terminal status %s (D-1 guard)', (_name, status) => {
        const wrapper = mountShow(status);
        expect(cancelCtas(wrapper).length).toBe(0);
    });

    it('PENDING keeps the REJECT CTA (default) and shows NO cancel CTA', () => {
        const wrapper = mountShow(orderStatusEnum.PENDING);
        expect(rejectCtas(wrapper).length).toBe(1);
        expect(cancelCtas(wrapper).length).toBe(0);
    });

    it('re-displays the saved reason for CANCELED (not just REJECTED)', () => {
        expect(mountShow(orderStatusEnum.CANCELED).text()).toContain('Client parti sans payer');
        expect(mountShow(orderStatusEnum.REJECTED).text()).toContain('Client parti sans payer');
        // sanity: an active order does not render the terminal reason card
        expect(mountShow(orderStatusEnum.ACCEPT).text()).not.toContain('Client parti sans payer');
    });
});

describe('P2-2 — OnlineOrderReasonComponent is reusable (status-parametrised)', () => {
    const mountReason = (props = {}) => {
        const store = { dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })) };
        const wrapper = mount(OnlineOrderReasonComponent, {
            props,
            global: {
                stubs: { LoadingComponent: true },
                mocks: {
                    $store: store,
                    $t: (k) => k,
                    $route: { params: { id: 42 } },
                },
            },
        });
        return { wrapper, store };
    };

    it('defaults to REJECTED (back-compat with the PENDING reject flow)', async () => {
        const { wrapper, store } = mountReason();
        wrapper.vm.form.reason = 'Rupture de stock';
        await wrapper.vm.submitReason();
        expect(store.dispatch).toHaveBeenCalledWith('onlineOrder/changeStatus', {
            id: 42, status: orderStatusEnum.REJECTED, reason: 'Rupture de stock',
        });
    });

    it('dispatches CANCELED with the reason when status prop = CANCELED', async () => {
        const { wrapper, store } = mountReason({ status: orderStatusEnum.CANCELED, labelKey: 'button.cancel' });
        wrapper.vm.form.reason = 'Client parti';
        await wrapper.vm.submitReason();
        expect(store.dispatch).toHaveBeenCalledWith('onlineOrder/changeStatus', {
            id: 42, status: orderStatusEnum.CANCELED, reason: 'Client parti',
        });
    });

    it('renders the configurable trigger label', () => {
        const { wrapper } = mountReason({ status: orderStatusEnum.CANCELED, labelKey: 'button.cancel' });
        expect(wrapper.text()).toContain('button.cancel');
    });
});
