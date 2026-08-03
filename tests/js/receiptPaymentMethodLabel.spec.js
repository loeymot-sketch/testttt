/**
 * [visual-round-3 P3 fix 2026-07-07] Receipt/detail payment-method label contract.
 *
 * Round 2 mapped raw backend payment_method slugs -> human FR labels on the LIST
 * surfaces only (TransactionListComponent). This round extends the SAME mapping
 * to the receipt/detail Vue components (OnlineOrder show/receipt, TableOrder
 * show/receipt, the three OrderDetails variants, table OrderReceipt) via a shared
 * helper so `order.transaction.payment_method` never renders as a raw machine slug
 * ("COUNTER_CASH", "CREDIT", "SPLIT", ...).
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import {
    paymentMethodLabel,
    paymentMethodLabelMixin,
} from '../../resources/js/helpers/paymentMethodLabel';

// FR label subset used by the helper + the mounted component under test.
const messages = {
    'label.cash': 'Espèces',
    'label.card': 'Carte',
    'label.caisse': 'Caisse',
    'label.mobile_banking': 'MFS',
    'label.ticket_restaurant': 'Ticket Restaurant',
    'label.other': 'Autre',
    'label.cash_on_delivery': 'Paiement à la livraison',
    'label.split_payment': 'Multi-paiement',
    // labels touched by OrderDetailsComponent data()/template
    'label.method': 'Mode',
    'label.status': 'Statut',
    'label.payment_info': 'Infos paiement',
    'label.paid': 'Payé',
    'label.unpaid': 'Non payé',
    'label.pending': 'En attente',
    'label.accept': 'Acceptée',
    'label.preparing': 'En préparation',
    'label.out_for_delivery': 'En livraison',
    'label.delivered': 'Livré',
    'label.canceled': 'Annulée',
    'label.rejected': 'Rejetée',
    'label.delivery': 'Livraison',
    'label.takeaway': 'À emporter',
    'label.dining_table': 'Sur place',
    'label.e_wallet': 'Portefeuille',
    'label.paypal': 'PayPal',
};
const $t = (key) => messages[key] || key;

describe('paymentMethodLabel — pure helper', () => {
    it('maps counter (borne encaissée en caisse) slugs to FR "(Caisse)" labels', () => {
        expect(paymentMethodLabel('COUNTER_CASH', $t)).toBe('Espèces (Caisse)');
        expect(paymentMethodLabel('counter_card', $t)).toBe('Carte (Caisse)'); // case-insensitive
        expect(paymentMethodLabel('COUNTER_MOBILE_BANKING', $t)).toBe('MFS (Caisse)');
        expect(paymentMethodLabel('COUNTER_TICKET_RESTAURANT', $t)).toBe('Ticket Restaurant (Caisse)');
        expect(paymentMethodLabel('COUNTER_OTHER', $t)).toBe('Autre (Caisse)');
    });

    it('maps gateway / pos slugs to FR labels (CREDIT -> Carte)', () => {
        expect(paymentMethodLabel('CASH', $t)).toBe('Espèces');
        expect(paymentMethodLabel('CARD', $t)).toBe('Carte');
        expect(paymentMethodLabel('CREDIT', $t)).toBe('Carte');
        expect(paymentMethodLabel('TICKET_RESTAURANT', $t)).toBe('Ticket Restaurant');
        expect(paymentMethodLabel('MOBILE_BANKING', $t)).toBe('MFS');
        expect(paymentMethodLabel('CASH_ON_DELIVERY', $t)).toBe('Paiement à la livraison');
    });

    it('maps split / mixed aggregate to the codebase FR term (Multi-paiement)', () => {
        expect(paymentMethodLabel('SPLIT', $t)).toBe('Multi-paiement');
        expect(paymentMethodLabel('MIXED', $t)).toBe('Multi-paiement');
    });

    it('humanises any unknown gateway slug (never leaks a raw machine token)', () => {
        expect(paymentMethodLabel('STRIPE', $t)).toBe('Stripe');
        expect(paymentMethodLabel('MY_GATEWAY', $t)).toBe('My Gateway');
    });

    it('renders an em-dash for empty / null / undefined', () => {
        expect(paymentMethodLabel(null, $t)).toBe('—');
        expect(paymentMethodLabel('', $t)).toBe('—');
        expect(paymentMethodLabel(undefined, $t)).toBe('—');
    });
});

describe('paymentMethodLabelMixin — wires this.$t', () => {
    it('exposes a this.$t-backed method', () => {
        const fn = paymentMethodLabelMixin.methods.paymentMethodLabel;
        expect(fn.call({ $t }, 'COUNTER_CASH')).toBe('Espèces (Caisse)');
    });
});

describe('receipt/detail component renders the FR label, not the raw slug', () => {
    async function mountDetails(payment_method) {
        const OrderDetailsComponent = (await import(
            '../../resources/js/components/admin/components/OrderDetailsComponent.vue'
        )).default;
        return mount(OrderDetailsComponent, {
            props: {
                order: {
                    status: 1, // PENDING (renders the payment block)
                    payment_status: 5, // PAID
                    payment_method: 1,
                    transaction: { payment_method, transaction_no: 'TX1' },
                },
                orderItems: {},
                orderBranch: {},
                orderAddress: {},
            },
            global: {
                mocks: { $t, $route: { params: {}, query: {} }, $router: { push: vi.fn() } },
                stubs: { LoadingComponent: true, OrderStatusComponent: true },
            },
        });
    }

    it('shows "Carte" for a raw CREDIT transaction slug', async () => {
        const wrapper = await mountDetails('CREDIT');
        expect(wrapper.text()).toContain('Carte');
        expect(wrapper.text()).not.toContain('CREDIT');
    });

    it('shows "Espèces (Caisse)" for COUNTER_CASH', async () => {
        const wrapper = await mountDetails('COUNTER_CASH');
        expect(wrapper.text()).toContain('Espèces (Caisse)');
        expect(wrapper.text()).not.toContain('COUNTER_CASH');
    });

    it('shows "Multi-paiement" for a SPLIT transaction (no raw slug)', async () => {
        const wrapper = await mountDetails('SPLIT');
        expect(wrapper.text()).toContain('Multi-paiement');
        expect(wrapper.text()).not.toContain('SPLIT');
    });
});
