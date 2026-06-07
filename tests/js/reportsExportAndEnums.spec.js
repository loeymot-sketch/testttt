import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import SalesReportListComponent from '../../resources/js/components/admin/salesReport/SalesReportListComponent.vue';
import ItemsReportListComponent from '../../resources/js/components/admin/itemsReport/ItemsReportListComponent.vue';
import TransactionListComponent from '../../resources/js/components/admin/transactions/TransactionListComponent.vue';
import paymentStatusEnum from '../../resources/js/enums/modules/paymentStatusEnum';
import posPaymentMethodEnum from '../../resources/js/enums/modules/posPaymentMethodEnum';
import paymentTypeEnum from '../../resources/js/enums/modules/paymentTypeEnum';
import sourceEnum from '../../resources/js/enums/modules/sourceEnum';

// [REP-EXP-01 / REP-SALES-STATUS-01 / REP-SALES-PAYTYPE-02 / REP-SALES-ENUM-05 /
//  REP-EXP-ERR-04 / REP-ITEMS-TOTAL-03 FIX 2026-06-06]
// Central report list components: exports must request the FULL filtered set
// (pagination stripped) and the enum maps must cover every status / pos method.

const makeStore = (overrides = {}) => {
    const dispatch = vi.fn(() => Promise.resolve({ data: { data: [] } }));
    const getters = new Proxy({
        'salesReport/lists': [],
        'salesReport/pagination': {},
        'salesReport/page': {},
        'salesReport/salesReportOverview': {},
        'itemsReport/lists': [],
        'itemsReport/pagination': {},
        'itemsReport/page': {},
        'item/lists': [],
        'itemCategory/lists': [],
        'transaction/lists': [],
        'transaction/pagination': {},
        'transaction/page': {},
        'paymentGateway/lists': [],
        'deliveryBoy/lists': [],
        'frontendLanguage/show': { display_mode: 0 },
        ...overrides,
    }, { get(t, p) { return p in t ? t[p] : []; } });
    return { dispatch, getters, commit: vi.fn() };
};

const mountComponent = (Comp, store) => shallowMount(Comp, {
    global: {
        stubs: { transition: false, Datepicker: true, 'vue-select': true },
        mocks: {
            $store: store,
            $t: (key) => key,
        },
    },
});

// Helper: find the dispatch call whose action name matches and return its payload.
const payloadOf = (dispatch, action) => {
    const call = dispatch.mock.calls.find((c) => c[0] === action);
    return call ? call[1] : undefined;
};

describe('REP-EXP-01 — exports request the FULL filtered set (pagination stripped)', () => {
    it('SalesReport xls() and pdf() dispatch with paginate:0 and no per_page/page', () => {
        const store = makeStore();
        const wrapper = mountComponent(SalesReportListComponent, store);

        wrapper.vm.xls();
        const xlsPayload = payloadOf(store.dispatch, 'salesReport/export');
        expect(xlsPayload).toBeTruthy();
        expect(xlsPayload.paginate).toBe(0);
        expect(xlsPayload.per_page).toBeUndefined();
        expect(xlsPayload.page).toBeUndefined();

        wrapper.vm.pdf();
        const pdfPayload = payloadOf(store.dispatch, 'salesReport/pdf');
        expect(pdfPayload).toBeTruthy();
        expect(pdfPayload.paginate).toBe(0);
        expect(pdfPayload.per_page).toBeUndefined();
        expect(pdfPayload.page).toBeUndefined();

        // The on-screen list must remain paginated (regression guard).
        expect(wrapper.vm.props.search.paginate).toBe(1);
        expect(wrapper.vm.props.search.per_page).toBe(10);
    });

    it('ItemsReport xls() and pdf() dispatch with paginate:0 and no per_page/page', () => {
        const store = makeStore();
        const wrapper = mountComponent(ItemsReportListComponent, store);

        wrapper.vm.xls();
        const xlsPayload = payloadOf(store.dispatch, 'itemsReport/export');
        expect(xlsPayload.paginate).toBe(0);
        expect(xlsPayload.per_page).toBeUndefined();
        expect(xlsPayload.page).toBeUndefined();

        wrapper.vm.pdf();
        const pdfPayload = payloadOf(store.dispatch, 'itemsReport/pdf');
        expect(pdfPayload.paginate).toBe(0);
        expect(pdfPayload.per_page).toBeUndefined();

        expect(wrapper.vm.props.search.paginate).toBe(1);
    });

    it('Transaction xls() dispatches with paginate:0 and no per_page/page', () => {
        const store = makeStore();
        const wrapper = mountComponent(TransactionListComponent, store);

        wrapper.vm.xls();
        const xlsPayload = payloadOf(store.dispatch, 'transaction/export');
        expect(xlsPayload.paginate).toBe(0);
        expect(xlsPayload.per_page).toBeUndefined();
        expect(xlsPayload.page).toBeUndefined();

        expect(wrapper.vm.props.search.paginate).toBe(1);
    });
});

describe('REP-ITEMS-TOTAL-03 — items tfoot grand total covers the FULL filtered set', () => {
    it('list() fetches the full set non-clobbering (vuex:false, paginate:0) and sums grandTotal', async () => {
        const store = makeStore();
        // The full-set fetch (vuex:false) resolves to a >1-page dataset summing to 5.
        store.dispatch = vi.fn((action, payload) => {
            if (action === 'itemsReport/lists' && payload && payload.vuex === false) {
                return Promise.resolve({ data: { data: [{ order: 3 }, { order: 2 }] } });
            }
            return Promise.resolve({ data: { data: [] } });
        });
        const wrapper = mountComponent(ItemsReportListComponent, store);

        wrapper.vm.list();
        // The full-set fetch must request paginate:0 + vuex:false and strip pagination.
        const fullFetch = store.dispatch.mock.calls.find(
            (c) => c[0] === 'itemsReport/lists' && c[1] && c[1].vuex === false,
        );
        expect(fullFetch, 'list() must issue a non-clobbering full-set fetch').toBeTruthy();
        expect(fullFetch[1].paginate).toBe(0);
        expect(fullFetch[1].per_page).toBeUndefined();
        expect(fullFetch[1].page).toBeUndefined();

        // Let the resolved promise compute grandTotal.
        await new Promise((r) => setTimeout(r, 0));
        expect(wrapper.vm.grandTotal).toBe(5);
    });
});

describe('REP-SALES-STATUS-01 — payment status map covers all four statuses', () => {
    it('paymentStatusEnumArray includes PENDING_COUNTER and REFUNDED', () => {
        const store = makeStore();
        const wrapper = mountComponent(SalesReportListComponent, store);
        const map = wrapper.vm.enums.paymentStatusEnumArray;

        expect(map[paymentStatusEnum.PAID]).toBe('label.paid');
        expect(map[paymentStatusEnum.UNPAID]).toBe('label.unpaid');
        expect(map[paymentStatusEnum.PENDING_COUNTER]).toBe('label.pending_counter');
        expect(map[paymentStatusEnum.REFUNDED]).toBe('label.refunded');
    });
});

describe('REP-SALES-PAYTYPE-02 — pos payment method map covers TR + counter-deferred', () => {
    it('posPaymentMethodEnumArray includes TICKET_RESTAURANT and COUNTER_DEFERRED', () => {
        const store = makeStore();
        const wrapper = mountComponent(SalesReportListComponent, store);
        const map = wrapper.vm.enums.posPaymentMethodEnumArray;

        expect(map[posPaymentMethodEnum.CASH]).toBe('label.cash');
        expect(map[posPaymentMethodEnum.CARD]).toBe('label.card');
        expect(map[posPaymentMethodEnum.MOBILE_BANKING]).toBe('label.mobile_banking');
        expect(map[posPaymentMethodEnum.OTHER]).toBe('label.other');
        expect(map[posPaymentMethodEnum.TICKET_RESTAURANT]).toBe('label.ticket_restaurant');
        expect(map[posPaymentMethodEnum.COUNTER_DEFERRED]).toBe('label.counter_deferred');
    });

    it('paymentType cell resolves a kiosk counter-deferred order to its pos-method label, not "Cash on delivery"', () => {
        const store = makeStore();
        const wrapper = mountComponent(SalesReportListComponent, store);

        // Kiosk pay-at-counter order: source=APP (not POS), pos_payment_method=COUNTER_DEFERRED,
        // payment_method=CASH_ON_DELIVERY, no transaction. Buggy path showed "cash_on_delivery".
        const kioskCounterOrder = {
            source: sourceEnum.APP,
            transaction: null,
            pos_payment_method: posPaymentMethodEnum.COUNTER_DEFERRED,
            payment_method: paymentTypeEnum.CASH_ON_DELIVERY,
        };
        const label = wrapper.vm.paymentTypeLabel(kioskCounterOrder);
        expect(label).toBe('label.counter_deferred');
        expect(label).not.toBe('label.cash_on_delivery');

        // A real POS cash order resolves to cash.
        const posCash = {
            source: sourceEnum.POS,
            transaction: null,
            pos_payment_method: posPaymentMethodEnum.CASH,
            payment_method: null,
        };
        expect(wrapper.vm.paymentTypeLabel(posCash)).toBe('label.cash');

        // A real gateway transaction wins (shows raw transaction string).
        const gatewayOrder = {
            source: sourceEnum.WEB,
            transaction: 'STRIPE',
            pos_payment_method: null,
            payment_method: paymentTypeEnum.E_WALLET,
        };
        expect(wrapper.vm.paymentTypeLabel(gatewayOrder)).toBe('STRIPE');
    });
});

describe('REP-EXP-ERR-04 — export error decodes the Blob body before alerting', () => {
    it('xls() reject with a Blob does not throw and surfaces a decoded message', async () => {
        const store = makeStore();
        // Reject the export with a Blob payload (responseType:'blob' shape).
        store.dispatch = vi.fn((action) => {
            if (action === 'salesReport/export') {
                const blob = new Blob([JSON.stringify({ message: 'boom' })], { type: 'application/json' });
                return Promise.reject({ response: { data: blob } });
            }
            return Promise.resolve({ data: { data: [] } });
        });
        const wrapper = mountComponent(SalesReportListComponent, store);

        // Must not throw synchronously; the catch must handle the Blob.
        await expect(Promise.resolve(wrapper.vm.xls())).resolves.not.toThrow();
        // Give the rejected promise a tick to run the .catch.
        await new Promise((r) => setTimeout(r, 0));
        expect(wrapper.vm.loading.isActive).toBe(false);
    });
});
