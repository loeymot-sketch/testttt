/**
 * [visual-round-1 P3 fixes 2026-07-07] Gérance display/label contracts:
 *  - FIX 2 Transactions: raw enum (COUNTER_CASH) -> FR label + FR EUR amount.
 *  - FIX 3 Sales report: PENDING_COUNTER/REFUNDED payment-status badge no longer empty.
 *  - FIX 4 Delivery-boy cash: LIVREUR column resolves the name, not the raw id.
 *  - FIX 5 Historique: ACCEPT status badge reads the adjectival "Acceptée".
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';

// FR label subset used by the components under test.
const messages = {
    'label.cash': 'Espèces',
    'label.card': 'Carte',
    'label.caisse': 'Caisse',
    'label.mobile_banking': 'MFS',
    'label.ticket_restaurant': 'Ticket Restaurant',
    'label.other': 'Autre',
    'label.cash_on_delivery': 'Paiement à la livraison',
    'label.paid': 'Payé',
    'label.unpaid': 'Non payé',
    'label.pending_counter': 'À encaisser',
    'label.refunded': 'Remboursé',
    'label.accept': 'Acceptée',
    'label.preparing': 'En préparation',
    'label.prepared': 'Préparée',
    'label.delivered': 'Livré',
    'label.returned': 'Retournée',
};

const $t = (key) => messages[key] || key;

// Broad stubs so we only render each component's own template.
const commonStubs = {
    LoadingComponent: true,
    BreadcrumbComponent: true,
    TableLimitComponent: true,
    FilterComponent: true,
    ExportComponent: true,
    PrintComponent: true,
    ExcelComponent: true,
    PdfComponent: true,
    Datepicker: true,
    PaginationSMBox: true,
    PaginationBox: true,
    PaginationTextComponent: true,
    SmIconViewComponent: true,
    'vue-select': true,
};

const commonMocks = {
    $t,
    $route: { params: {}, query: {} },
    $router: { push: vi.fn() },
};

function langModule() {
    return {
        namespaced: true,
        getters: { show: () => ({ display_mode: 10 }) }, // LTR
    };
}

describe('FIX 2 — TransactionListComponent payment label + amount format', () => {
    beforeEach(() => vi.clearAllMocks());

    async function mountTransactions(transaction) {
        const TransactionListComponent = (await import(
            '../../resources/js/components/admin/transactions/TransactionListComponent.vue'
        )).default;

        const store = createStore({
            modules: {
                transaction: {
                    namespaced: true,
                    getters: {
                        lists: () => [transaction],
                        pagination: () => ({}),
                        page: () => ({}),
                    },
                    actions: { lists: vi.fn().mockResolvedValue({}) },
                },
                paymentGateway: {
                    namespaced: true,
                    getters: { lists: () => [] },
                    actions: { lists: vi.fn().mockResolvedValue({ data: { data: [] } }) },
                },
                defaultAccess: {
                    namespaced: true,
                    actions: { show: vi.fn().mockResolvedValue({ data: { data: { branch_id: 0 } } }) },
                },
                frontendLanguage: langModule(),
            },
        });

        return mount(TransactionListComponent, {
            global: { plugins: [store], mocks: commonMocks, stubs: commonStubs },
        });
    }

    it('renders COUNTER_CASH as "Espèces (Caisse)" and the amount as FR EUR', async () => {
        const wrapper = await mountTransactions({
            transaction_no: 'TX1', date: '2026-07-07', payment_method: 'COUNTER_CASH',
            order_serial_no: 'A0001', amount: '6.90', sign: '+',
        });
        const text = wrapper.text();
        expect(text).toContain('Espèces (Caisse)');
        expect(text).not.toContain('COUNTER_CASH');
        expect(text).toContain('6,90');
        expect(text).toContain('€');
        expect(text).not.toContain('6.90');
    });

    it('renders COUNTER_CARD as "Carte (Caisse)"', async () => {
        const wrapper = await mountTransactions({
            transaction_no: 'TX2', date: '2026-07-07', payment_method: 'COUNTER_CARD',
            order_serial_no: 'A0002', amount: '12.00', sign: '+',
        });
        expect(wrapper.text()).toContain('Carte (Caisse)');
        expect(wrapper.text()).not.toContain('COUNTER_CARD');
    });
});

describe('FIX 3 — SalesReportListComponent payment-status badge', () => {
    beforeEach(() => vi.clearAllMocks());

    async function mountSalesReport(paymentStatus) {
        const SalesReportListComponent = (await import(
            '../../resources/js/components/admin/salesReport/SalesReportListComponent.vue'
        )).default;

        const row = {
            order_serial_no: 'A0001', order_datetime: '2026-07-07', total_currency_price: '6,90 €',
            discount_currency_price: '0,00 €', delivery_charge_currency_price: '0,00 €',
            transaction: null, source: 30, pos_payment_method: 1, payment_method: 1,
            payment_status: paymentStatus,
        };

        const store = createStore({
            modules: {
                salesReport: {
                    namespaced: true,
                    getters: {
                        lists: () => [row],
                        pagination: () => ({}),
                        page: () => ({}),
                        salesReportOverview: () => ({}),
                    },
                    actions: {
                        lists: vi.fn().mockResolvedValue({}),
                        salesReportOverview: vi.fn().mockResolvedValue({}),
                    },
                },
                deliveryBoy: {
                    namespaced: true,
                    getters: { lists: () => [] },
                    actions: { lists: vi.fn().mockResolvedValue({ data: { data: [] } }) },
                },
                paymentGateway: {
                    namespaced: true,
                    getters: { lists: () => [] },
                    actions: { lists: vi.fn().mockResolvedValue({ data: { data: [] } }) },
                },
                frontendLanguage: langModule(),
            },
        });

        return mount(SalesReportListComponent, {
            global: { plugins: [store], mocks: commonMocks, stubs: commonStubs },
        });
    }

    it('renders "À encaisser" for a PENDING_COUNTER (15) row instead of an empty badge', async () => {
        const wrapper = await mountSalesReport(15);
        const badge = wrapper.find('.db-table-badge');
        expect(badge.exists()).toBe(true);
        expect(badge.text().trim()).toBe('À encaisser');
    });

    it('renders "Remboursé" for a REFUNDED (20) row', async () => {
        const wrapper = await mountSalesReport(20);
        expect(wrapper.find('.db-table-badge').text().trim()).toBe('Remboursé');
    });

    it('still renders "Payé" for a PAID (5) row', async () => {
        const wrapper = await mountSalesReport(5);
        expect(wrapper.find('.db-table-badge').text().trim()).toBe('Payé');
    });
});

describe('FIX 4 — DeliveryBoyCashSessionListComponent livreur name', () => {
    beforeEach(() => vi.clearAllMocks());

    it('resolves delivery_boy_id 10 to the person name, not the raw id', async () => {
        const axios = (await import('axios')).default;
        const getSpy = vi.spyOn(axios, 'get').mockImplementation((url) => {
            if (String(url).includes('cash-sessions')) {
                return Promise.resolve({
                    data: {
                        data: [{
                            id: 1, delivery_boy_id: 10, branch_id: 1, opening_amount: 50,
                            closing_amount: null, variance: null, status: 'open', opened_at: null,
                        }],
                        pagination: { total: 1, per_page: 20, current_page: 1, last_page: 1 },
                    },
                });
            }
            // admin/delivery-boy directory
            return Promise.resolve({ data: { data: [{ id: 10, name: 'Jean Livreur' }] } });
        });

        const DeliveryBoyCashSessionListComponent = (await import(
            '../../resources/js/components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionListComponent.vue'
        )).default;

        const wrapper = mount(DeliveryBoyCashSessionListComponent, {
            global: {
                mocks: commonMocks,
                stubs: { ...commonStubs, DeliveryBoyCashSessionFormComponent: true },
            },
        });

        // flush the two axios promises + reactive re-render
        await new Promise((r) => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        expect(getSpy).toHaveBeenCalled();
        const text = wrapper.text();
        expect(text).toContain('Jean Livreur');
        // the LIVREUR cell must not be the bare id
        const livreurCell = wrapper.findAll('td.db-table-body-td')[1];
        expect(livreurCell.text().trim()).toBe('Jean Livreur');
    });
});

describe('FIX 5 — fr.json order-status label is adjectival', () => {
    it('label.accept is the adjectival "Acceptée" (state), while button.accept stays the verb "Accepter"', async () => {
        const fr = (await import('../../resources/js/languages/fr.json')).default;
        expect(fr.label.accept).toBe('Acceptée');
        // the imperative action verb lives on a distinct key and must NOT change
        expect(fr.button.accept).toBe('Accepter');
    });
});

describe('FIX 5 — HistoriqueListComponent status badge is adjectival', () => {
    beforeEach(() => vi.clearAllMocks());

    it('renders the ACCEPT status as "Acceptée" (not the verb "Accepter")', async () => {
        const HistoriqueListComponent = (await import(
            '../../resources/js/components/admin/orderHistory/HistoriqueListComponent.vue'
        )).default;

        const order = {
            id: 1, order_serial_no: 'A0001', source_surface: 'pos', order_type: 20,
            queue_number: 1, customer_name: 'Client', total: 6.9, payment_status: 5,
            fiscal_sequence_no: 42, order_datetime: '2026-07-07', status: 4 /* ACCEPT */,
            status_name: 'Accept',
        };

        const store = createStore({
            modules: {
                orderHistory: {
                    namespaced: true,
                    getters: {
                        lists: () => [order],
                        pagination: () => ({}),
                        page: () => ({}),
                    },
                    actions: { lists: vi.fn().mockResolvedValue({}) },
                },
                frontendLanguage: langModule(),
            },
        });

        const wrapper = mount(HistoriqueListComponent, {
            global: { plugins: [store], mocks: commonMocks, stubs: commonStubs },
        });

        const text = wrapper.text();
        expect(text).toContain('Acceptée');
        expect(text).not.toContain('Accepter');
    });
});
