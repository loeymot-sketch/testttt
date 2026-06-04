/**
 * counterCollectFrDecimalSentinel.spec.js — GOAL-D2 2026-05-23
 *
 * Locks the FR decimal pre-fill behavior introduced by GOAL-D2 fix on
 * PosCounterCollectModal:
 *   - cashReceivedRaw pre-fills with comma decimal ("8,50") on mount
 *   - cashReceivedNumber accepts BOTH "," and "." as decimal separator
 *   - canConfirm computes correctly at exact-change with comma pre-fill
 *
 * Surfaced by Wave Final 2026-05-23 S2-001 P2 (cashier saw "8.50" point
 * decimal instead of canonical FR "8,50" comma decimal in MONTANT REÇU
 * input). Owner decision D2 = fix-now.
 *
 * @FK-ID  GOAL-D2
 */
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Mock the appService + alertService transitive imports — they pull network
// helpers we don't need for pure component-state assertions.
vi.mock('../../../resources/js/services/appService', () => ({
    default: {
        handleError: vi.fn(),
    },
}));
vi.mock('../../../resources/js/services/alertService', () => ({
    default: {
        showSuccess: vi.fn(),
        showError: vi.fn(),
    },
}));

import PosCounterCollectModal from '../../../resources/js/components/admin/pos/PosCounterCollectModal.vue';

// Minimal i18n + numpad-stub global config, mirroring posPaymentMethodEnum
// expectations from posCounterCollectModalSentinel.spec.js.
const globalStubs = {
    mocks: { $t: (k) => k },
    stubs: { PosV5Numpad: true },
};

describe('PosCounterCollectModal — D2 FR decimal pre-fill (GOAL-D2)', () => {
    it('pre-fills cashReceivedRaw with comma decimal on mount', async () => {
        const wrapper = mount(PosCounterCollectModal, {
            props: { order: { id: 1, total: 8.50, queue_number: 'A0001' } },
            global: globalStubs,
        });
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.cashReceivedRaw).toBe('8,50');
    });

    it('parses comma-decimal cashReceivedRaw correctly in cashReceivedNumber', async () => {
        const wrapper = mount(PosCounterCollectModal, {
            props: { order: { id: 1, total: 8.50, queue_number: 'A0001' } },
            global: globalStubs,
        });
        await wrapper.vm.$nextTick();
        wrapper.vm.cashReceivedRaw = '10,00';
        expect(wrapper.vm.cashReceivedNumber).toBe(10);
    });

    it('parses point-decimal cashReceivedRaw correctly (backward compat)', async () => {
        const wrapper = mount(PosCounterCollectModal, {
            props: { order: { id: 1, total: 8.50, queue_number: 'A0001' } },
            global: globalStubs,
        });
        await wrapper.vm.$nextTick();
        wrapper.vm.cashReceivedRaw = '10.00';
        expect(wrapper.vm.cashReceivedNumber).toBe(10);
    });

    it('canConfirm computes correctly with comma pre-fill at exact change', async () => {
        const wrapper = mount(PosCounterCollectModal, {
            props: { order: { id: 1, total: 8.50, queue_number: 'A0001' } },
            global: globalStubs,
        });
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.canConfirm).toBe(true);
    });
});
