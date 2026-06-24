import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';

import axios from 'axios';
import alertService from '../../resources/js/services/alertService';

vi.mock('axios');

vi.mock('vue3-print-nb', () => ({
    default: {
        directiveName: 'print',
        mounted() {},
    },
}));

// [PS-4 audit heal 2026-05-18] Stub alertService so we can assert the NF525
// audit-chain warning is surfaced to the operator when audit_emitted=false.
vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        success: vi.fn(),
        warning: vi.fn(),
        info: vi.fn(),
        error: vi.fn(),
        default: vi.fn(),
    },
}));

import ReceiptComponent from '../../resources/js/components/admin/pos/ReceiptComponent.vue';

const baseGetters = {
    'company/lists': { company_name: 'FK' },
    'backendGlobalState/branchShow': { address: '1 rue', phone: '0102030405' },
    'posOrder/orderItems': [],
    'frontendLanguage/show': { display_mode: 0 },
};

const storeMock = {
    getters: new Proxy(baseGetters, {
        get(target, property) {
            return property in target ? target[property] : {};
        },
    }),
    dispatch: vi.fn(() => Promise.resolve()),
    commit: vi.fn(),
};

const mountReceipt = (printCount) => shallowMount(ReceiptComponent, {
    props: {
        order: {
            id: 42,
            order_serial_no: 'A001',
            receipt_print_count: printCount,
        },
    },
    global: {
        mocks: {
            $store: storeMock,
            $t: (k) => k,
        },
        directives: {
            print: { mounted() {} },
        },
    },
});

describe('ReceiptComponent print policy (W9.D)', () => {
    beforeEach(() => {
        axios.post = vi.fn(() => Promise.resolve({
            data: { order_id: 42, receipt_print_count: 1, is_duplicata: false, audit_emitted: true },
        }));
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('calls the increment endpoint when the operator clicks print', async () => {
        const wrapper = mountReceipt(0);

        await wrapper.find('[data-testid="receipt-print-client"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledTimes(1);
        // [K-001 abuse-e2e 2026-06-03] print-receipt is in idempotency.required_routes,
        // so the call MUST carry a fresh-per-click X-Idempotency-Key (else 422 in prod).
        // Assert the full 3-arg signature so a future regression dropping the header fails.
        expect(axios.post).toHaveBeenCalledWith(
            'admin/pos/orders/42/print-receipt',
            {},
            expect.objectContaining({
                headers: expect.objectContaining({
                    'X-Idempotency-Key': expect.stringMatching(/^pos-print-receipt-42-\d+$/),
                }),
            }),
        );
    });

    it('does not call the FISCAL increment endpoint for kitchen print (non-fiscal; may call the ESC/POS print-kitchen endpoint)', async () => {
        // [PRINT-SAGA 2026-06-24] Kitchen ticket is NOT a fiscal document, so it must
        // never hit the fiscal `print-receipt` increment route. It MAY call the
        // non-fiscal `print-kitchen` route to drive a thermal (SAGA) print.
        axios.post = vi.fn(() => Promise.resolve({ data: { order_id: 42, printed_escpos: false } }));

        const wrapper = mountReceipt(0);

        await wrapper.find('[data-testid="receipt-print-kitchen"]').trigger('click');
        await flushPromises();

        const hitFiscal = axios.post.mock.calls.some((c) => String(c[0]).includes('print-receipt'));
        expect(hitFiscal).toBe(false);
        const hitKitchen = axios.post.mock.calls.some((c) => String(c[0]).includes('print-kitchen'));
        expect(hitKitchen).toBe(true);
    });

    it('updates the local DUPLICATA marker BEFORE triggering the iframe print', async () => {
        axios.post = vi.fn(() => Promise.resolve({
            data: { order_id: 42, receipt_print_count: 2, is_duplicata: true, audit_emitted: true },
        }));

        const wrapper = mountReceipt(1); // already printed once

        await wrapper.find('[data-testid="receipt-print-client"]').trigger('click');
        await flushPromises();

        // localPrintCount should reflect the server response (2),
        // which makes effectiveOrder.receipt_print_count === 2.
        expect(wrapper.vm.localPrintCount).toBe(2);
        expect(wrapper.vm.effectiveOrder.receipt_print_count).toBe(2);
    });

    it('still triggers the print even if the increment endpoint fails (operational continuity)', async () => {
        axios.post = vi.fn(() => Promise.reject(new Error('network down')));

        const wrapper = mountReceipt(0);
        const hiddenTrigger = wrapper.find('[data-testid="receipt-hidden-print-button-client"]');
        const clickSpy = vi.spyOn(hiddenTrigger.element, 'click');

        await wrapper.find('[data-testid="receipt-print-client"]').trigger('click');
        await flushPromises();

        // Optimistic local bump even when the API rejects
        expect(wrapper.vm.localPrintCount).toBe(1);
        // Hidden v-print button still receives a programmatic click
        expect(clickSpy).toHaveBeenCalled();
    });

    /*
     * [W9-AUDIT TEST-3] effectiveOrder reactivity on parent prop change.
     *
     * After the print POST completes, the parent store often refetches the
     * order (background sync) and pushes a new `order` prop with the updated
     * receipt_print_count. The effectiveOrder computed MUST prefer the higher
     * of (localPrintCount, props.order.receipt_print_count) so the DUPLICATA
     * badge does not flicker back to "no badge" during the brief window when
     * the parent prop overrides the local optimistic value.
     */
    it('effectiveOrder follows parent prop changes after the local bump', async () => {
        const wrapper = mountReceipt(0);

        await wrapper.setProps({
            order: {
                id: 42,
                order_serial_no: 'A001',
                receipt_print_count: 3,
            },
        });

        expect(wrapper.vm.effectiveOrder.receipt_print_count).toBe(3);
    });

    it('blocks re-entrant clicks while a print is in flight', async () => {
        let resolve;
        axios.post = vi.fn(() => new Promise((r) => { resolve = r; }));

        const wrapper = mountReceipt(0);
        const trigger = wrapper.find('[data-testid="receipt-print-client"]');

        await trigger.trigger('click');
        await trigger.trigger('click');
        await trigger.trigger('click');

        expect(axios.post).toHaveBeenCalledTimes(1);

        resolve({ data: { receipt_print_count: 1, is_duplicata: false, audit_emitted: true } });
        await flushPromises();
    });

    /*
     * [PS-4 audit heal 2026-05-18 / RED-PS4-005 + UX-PS4-002]
     * When PosReceiptPrintController emits the receipt-print row but the
     * audit-chain write fails (lock contention / cache outage / DB hiccup),
     * the response carries `audit_emitted: false`. The print still succeeds
     * so the cashier can hand the paper to the customer (operational
     * continuity), BUT the operator MUST be alerted so SIEM / fiscal ops
     * can investigate the gap. Pre-heal the UI silently swallowed the
     * signal.
     */
    it('surfaces a manager-visible warning when audit_emitted is false (NF525 SIEM signal)', async () => {
        alertService.warning.mockClear();
        axios.post = vi.fn(() => Promise.resolve({
            data: { order_id: 42, receipt_print_count: 1, is_duplicata: false, audit_emitted: false },
        }));

        const wrapper = mountReceipt(0);

        await wrapper.find('[data-testid="receipt-print-client"]').trigger('click');
        await flushPromises();

        expect(alertService.warning).toHaveBeenCalledTimes(1);
        expect(alertService.warning.mock.calls[0][0]).toBe('pos.receipt_audit_chain_warning');
    });

    it('does NOT warn when audit_emitted is true (happy path)', async () => {
        alertService.warning.mockClear();
        axios.post = vi.fn(() => Promise.resolve({
            data: { order_id: 42, receipt_print_count: 1, is_duplicata: false, audit_emitted: true },
        }));

        const wrapper = mountReceipt(0);

        await wrapper.find('[data-testid="receipt-print-client"]').trigger('click');
        await flushPromises();

        expect(alertService.warning).not.toHaveBeenCalled();
    });
});
