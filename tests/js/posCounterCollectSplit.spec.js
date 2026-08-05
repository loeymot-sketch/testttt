import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: { get: vi.fn().mockResolvedValue({ data: { data: [{ id: 9, name: 'TPE 1', status: 1 }] } }), post: vi.fn() },
}));

import axios from 'axios';
import Modal from '../../resources/js/components/admin/pos/PosCounterCollectModal.vue';

/**
 * [GOAL-8AXES V6 T-3.3.2 2026-08-05] Paiement MIXTE à l'encaissement — cas
 * owner : « 12 € en carte, il m'affiche le reste, je choisis espèces ».
 * Jumeau frontend de tests/Feature/Pos/CounterCollectSplitPaymentTest.php.
 */
describe('PosCounterCollectModal — mode MIXTE (montage réel)', () => {
    const mountModal = (order = { id: 42, total: 20.01, queue_number: 'A0042' }) =>
        mount(Modal, {
            props: { order },
            global: { mocks: { $t: (k) => k }, stubs: { PosV5Numpad: true } },
        });

    beforeEach(() => {
        vi.clearAllMocks();
        axios.get.mockResolvedValue({ data: { data: [{ id: 9, name: 'TPE 1', status: 1 }] } });
    });

    it('la tuile Paiement mixte est rendue', () => {
        const w = mountModal();
        expect(w.find('[data-testid="pos-counter-collect-mode-MIXTE"]').exists()).toBe(true);
    });

    it('le RESTE est affiché en direct et exact au centime', async () => {
        const w = mountModal();
        await w.find('[data-testid="pos-counter-collect-mode-MIXTE"]').trigger('click');
        w.vm.cashReceivedRaw = '12,00';
        await w.vm.$nextTick();
        expect(w.vm.mixteFirstAmount).toBeCloseTo(12.0, 5);
        expect(w.vm.mixteRemainder).toBeCloseTo(8.01, 5);
        expect(w.find('[data-testid="cc-mixte-remainder"]').exists()).toBe(true);
    });

    it('choisir Espèces en 1ʳᵉ partie bascule le reste en Carte (et inversement)', async () => {
        const w = mountModal();
        await w.find('[data-testid="pos-counter-collect-mode-MIXTE"]').trigger('click');
        await w.find('[data-testid="cc-mixte-first-cash"]').trigger('click');
        expect(w.vm.mixteFirstMode).toBe('CASH');
        expect(w.vm.mixteSecondMode).toBe('CARD');
        await w.find('[data-testid="cc-mixte-first-card"]').trigger('click');
        expect(w.vm.mixteSecondMode).toBe('CASH');
    });

    it('canConfirm exige 0 < tranche1 < total', async () => {
        const w = mountModal();
        await w.find('[data-testid="pos-counter-collect-mode-MIXTE"]').trigger('click');
        await w.vm.$nextTick(); // laisse loadActiveTerminal résoudre (TPE id 9)
        await w.vm.$nextTick();

        w.vm.cashReceivedRaw = '';
        await w.vm.$nextTick();
        expect(w.vm.canConfirm).toBe(false);

        w.vm.cashReceivedRaw = '20,01'; // = total → pas un split
        await w.vm.$nextTick();
        expect(w.vm.canConfirm).toBe(false);

        w.vm.cashReceivedRaw = '12,00';
        await w.vm.$nextTick();
        expect(w.vm.canConfirm).toBe(true);
    });

    it('onConfirm poste un payment_breakdown somme=total, TPE sur la tranche CARD, clé idempotence contenu-aware', async () => {
        axios.post.mockResolvedValue({ data: { data: { id: 42 } } });
        const w = mountModal();
        await w.find('[data-testid="pos-counter-collect-mode-MIXTE"]').trigger('click');
        await w.vm.$nextTick();
        await w.vm.$nextTick();
        w.vm.cashReceivedRaw = '12,00';
        await w.vm.$nextTick();

        await w.vm.onConfirm();

        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, body, opts] = axios.post.mock.calls[0];
        expect(url).toBe('admin/pos/counter-collect/42/confirm');
        expect(body.payment_breakdown).toHaveLength(2);
        const sum = body.payment_breakdown.reduce((a, t) => a + t.amount, 0);
        expect(sum).toBeCloseTo(20.01, 5);
        const card = body.payment_breakdown.find((t) => t.terminal_id !== undefined);
        expect(card.terminal_id).toBe(9);
        expect(body.received).toBeNull();
        expect(opts.headers['X-Idempotency-Key']).toContain('-mx-');
    });
});
