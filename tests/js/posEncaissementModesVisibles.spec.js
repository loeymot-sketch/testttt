import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';

vi.mock('axios', () => ({
    default: { get: vi.fn().mockResolvedValue({ data: { data: [{ id: 9, name: 'TPE 1', status: 1 }] } }), post: vi.fn() },
}));

import axios from 'axios';
import Modal from '../../resources/js/components/admin/pos/PosCounterCollectModal.vue';

// [GOAL CAISSE/CUISINE #2 2026-10-02] Écran « à encaisser » : boutons Espèces, Carte bleue,
// Titres-resto, Multi-paiement bien visibles ; une carte INFÉRIEURE au total ouvre le multi-paiement
// et le RESTE se règle avec le moyen de son choix (CB, espèces, titre-resto…).
const fr = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../resources/js/languages/fr.json'), 'utf8'));
const $t = (key) => key.split('.').reduce((o, k) => (o ? o[k] : undefined), fr) ?? key;

const mountModal = (order = { id: 42, total: 20.01, queue_number: 'A0042' }) =>
    mount(Modal, { props: { order }, global: { mocks: { $t }, stubs: { PosV5Numpad: true } } });

const flush = async (w) => { await w.vm.$nextTick(); await w.vm.$nextTick(); };

describe('encaissement — les 4 boutons demandés sont visibles et nommés', () => {
    beforeEach(() => vi.clearAllMocks());

    it.each([
        ['CASH', 'Espèces'],
        ['CARD', 'Carte bleue'],
        ['TICKET', 'Titres-resto'],
        ['MIXTE', 'Multi-paiement'],
    ])('bouton %s libellé « %s »', (id, label) => {
        const w = mountModal();
        const btn = w.find(`[data-testid="pos-counter-collect-mode-${id}"]`);
        expect(btn.exists()).toBe(true);
        expect(btn.find('.cc-mode-label').text()).toBe(label);
    });
});

describe('carte bleue partielle → le reste par un autre moyen', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.get.mockResolvedValue({ data: { data: [{ id: 9, name: 'TPE 1', status: 1 }] } });
    });

    const typeCardAmount = async (w, amount) => {
        await w.find('[data-testid="pos-counter-collect-mode-CARD"]').trigger('click');
        w.vm.cashFieldPristine = false; // saisie du caissier (numpad), pas le pré-remplissage
        w.vm.cashReceivedRaw = amount;
        await flush(w);
    };

    it('une carte < total bascule en Multi-paiement avec ce montant en 1ʳᵉ partie', async () => {
        const w = mountModal();
        await typeCardAmount(w, '12,00');

        expect(w.vm.selectedMode).toBe('MIXTE');
        expect(w.vm.mixteFirstMode).toBe('CARD');
        expect(w.vm.mixteFirstAmount).toBeCloseTo(12, 5);
        expect(w.vm.mixteRemainder).toBeCloseTo(8.01, 5);
        expect(w.find('[data-testid="cc-mixte-remainder-amount"]').text()).toContain('8,01');
    });

    it('le pré-remplissage (= total) ne déclenche PAS la bascule', async () => {
        const w = mountModal();
        await w.find('[data-testid="pos-counter-collect-mode-CARD"]').trigger('click');
        await flush(w);
        expect(w.vm.selectedMode).toBe('CARD');
        expect(w.vm.canConfirm).toBe(true);
    });

    it.each([
        ['CASH', 1],
        ['TICKET', 5],
        ['CARD', 2],
        ['MOBILE', 3],
    ])('le reste peut être réglé en %s', async (second, expectedMode) => {
        axios.post.mockResolvedValue({ data: { data: { id: 42 } } });
        const w = mountModal();
        await typeCardAmount(w, '12,00');

        await w.find(`[data-testid="cc-mixte-second-${second.toLowerCase()}"]`).trigger('click');
        expect(w.vm.canConfirm).toBe(true);
        await w.vm.onConfirm();

        const [url, body] = axios.post.mock.calls[0];
        expect(url).toBe('admin/pos/counter-collect/42/confirm');
        expect(body.payment_breakdown).toHaveLength(2);
        expect(body.payment_breakdown[0]).toMatchObject({ mode: 2, amount: 12 });
        expect(body.payment_breakdown[1].mode).toBe(expectedMode);
        const sum = body.payment_breakdown.reduce((a, t) => a + t.amount, 0);
        expect(sum).toBeCloseTo(20.01, 5);
    });

    it('un titre-resto en 1ʳᵉ partie et le reste en carte est aussi possible', async () => {
        axios.post.mockResolvedValue({ data: { data: { id: 42 } } });
        const w = mountModal();
        await w.find('[data-testid="pos-counter-collect-mode-MIXTE"]').trigger('click');
        await flush(w);
        await w.find('[data-testid="cc-mixte-first-ticket"]').trigger('click');
        await w.find('[data-testid="cc-mixte-second-card"]').trigger('click');
        w.vm.cashReceivedRaw = '9,00';
        await flush(w);

        expect(w.vm.canConfirm).toBe(true);
        await w.vm.onConfirm();
        const body = axios.post.mock.calls[0][1];
        expect(body.payment_breakdown.map((t) => t.mode)).toEqual([5, 2]);
        expect(body.payment_breakdown[1].terminal_id).toBe(9);
    });
});
