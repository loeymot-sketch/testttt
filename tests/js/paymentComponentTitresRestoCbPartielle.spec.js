import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';

vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({
    default: { name: 'ReceiptComponent', template: '<div />' },
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: {
        currencyFormat: vi.fn((amount) => String(amount)),
        floatNumber: vi.fn(),
        modalHide: vi.fn(),
        modalShow: vi.fn(),
    },
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { error: vi.fn(), info: vi.fn(), success: vi.fn() },
}));
vi.mock('../../resources/js/services/kioskHardware', () => ({
    openDrawer: vi.fn(() => Promise.resolve()),
}));
vi.mock('../../resources/js/store/modules/posCart', () => ({
    normalizeCartForApi: vi.fn((items) => items),
}));
vi.mock('axios', () => ({
    default: { get: vi.fn().mockResolvedValue({ data: { data: [{ id: 9, name: 'TPE 1', status: 1 }] } }), post: vi.fn() },
}));

import PaymentComponent from '../../resources/js/components/admin/pos/PaymentComponent.vue';

// [GOAL CAISSE/CUISINE #2 2026-10-02] LOCK_PAYMENT_COMPONENT_TITRES_RESTO_CB_PARTIELLE_2026-10-02.md
// Écran de paiement caisse : 4 onglets nommés, Titres-resto en un geste, CB partielle → reste
// réglé autrement. Le prix reste celui du devis scellé : on ne teste ici que des montants d'ENCAISSEMENT.
const fr = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../resources/js/languages/fr.json'), 'utf8'));
const $t = (key) => key.split('.').reduce((o, k) => (o ? o[k] : undefined), fr) ?? key;

const store = {
    getters: { 'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: '€', site_currency_position: 5 } },
    dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })),
};

const mountPayment = (total = 20.01) => mount(PaymentComponent, {
    props: { props: { form: { total, pos_payment_method: 1, items: '[]' } } },
    global: { mocks: { $t, $store: store }, stubs: { PosV5Numpad: true, PosV5TrancheRow: true } },
});

describe('onglets de paiement', () => {
    beforeEach(() => vi.clearAllMocks());

    it.each([
        ['cash', 'Espèces'],
        ['card', 'Carte bleue'],
        ['ticket', 'Titres-resto'],
        ['multi', 'Multi-paiement'],
    ])('onglet %s « %s » visible', (id, label) => {
        const w = mountPayment();
        const btn = w.find(`[data-testid="pos-payment-mode-${id}"]`);
        expect(btn.exists()).toBe(true);
        expect(btn.text()).toContain(label);
    });

    it('la grille a 4 colonnes', () => {
        const w = mountPayment();
        expect(w.find('nav.pos-v5-payment-methods--4col').exists()).toBe(true);
    });
});

describe('Titres-resto en un geste', () => {
    it('crée UNE tranche Titres-resto du total, prête à confirmer', async () => {
        const w = mountPayment(20.01);
        await w.find('[data-testid="pos-payment-mode-ticket"]').trigger('click');

        expect(w.vm.paymentMode).toBe('multi');
        expect(w.vm.tranches).toHaveLength(1);
        expect(w.vm.tranches[0].mode).toBe(5);
        expect(w.vm.tranches[0].amount).toBeCloseTo(20.01, 5);
        expect(w.vm.canConfirmMulti).toBe(true);
        expect(w.vm.isTicketMode).toBe(true);
        expect(w.find('[data-testid="pos-payment-mode-ticket"]').classes()).toContain('is-active');
        expect(w.find('[data-testid="pos-payment-mode-multi"]').classes()).not.toContain('is-active');
    });

    it('ajouter une tranche sort du mode Titres-resto vers Multi-paiement', async () => {
        const w = mountPayment();
        await w.find('[data-testid="pos-payment-mode-ticket"]').trigger('click');
        w.vm.addTranche();
        await w.vm.$nextTick();
        expect(w.vm.isTicketMode).toBe(false);
        expect(w.find('[data-testid="pos-payment-mode-multi"]').classes()).toContain('is-active');
    });
});

describe('CB partielle → le reste par un autre moyen', () => {
    it('un montant carte < total ouvre le multi : carte + reste', async () => {
        const w = mountPayment(20.01);
        await w.find('[data-testid="pos-payment-mode-card"]').trigger('click');
        w.vm.selectedTerminalId = 9;
        await w.find('[data-testid="pos-payment-card-amount"]').setValue('12,00');
        expect(w.vm.canSplitCardRemainder).toBe(true);
        await w.find('[data-testid="pos-payment-card-split"]').trigger('click');

        expect(w.vm.paymentMode).toBe('multi');
        expect(w.vm.tranches).toHaveLength(2);
        expect(w.vm.tranches[0]).toMatchObject({ mode: 2, amount: 12, terminal_id: 9 });
        expect(w.vm.tranches[1].mode).toBe(1); // espèces par défaut, modifiable
        expect(w.vm.tranches[1].amount).toBeCloseTo(8.01, 5);
        expect(w.vm.canConfirmMulti).toBe(true);
        expect(w.vm.remainingDueEur).toBe(0);
    });

    it('le reste peut être changé en Titres-resto via la tranche', async () => {
        const w = mountPayment(20.01);
        await w.find('[data-testid="pos-payment-mode-card"]').trigger('click');
        await w.find('[data-testid="pos-payment-card-amount"]').setValue('12,00');
        await w.find('[data-testid="pos-payment-card-split"]').trigger('click');
        w.vm.updateTranche(1, { mode: 5 });
        expect(w.vm.tranches.map((t) => t.mode)).toEqual([2, 5]);
        expect(w.vm.canConfirmMulti).toBe(true);
    });

    it.each(['', '0', '20,01', '25', 'abc'])('montant « %s » : pas de découpage (carte pour le total)', async (raw) => {
        const w = mountPayment(20.01);
        await w.find('[data-testid="pos-payment-mode-card"]').trigger('click');
        await w.find('[data-testid="pos-payment-card-amount"]').setValue(raw);
        expect(w.vm.canSplitCardRemainder).toBe(false);
        expect(w.find('[data-testid="pos-payment-card-split"]').attributes('disabled')).toBeDefined();
        expect(w.vm.paymentMode).toBe('card');
    });

    it('carte pleine et espèces : chemin historique inchangé', async () => {
        const w = mountPayment(20.01);
        await w.find('[data-testid="pos-payment-mode-card"]').trigger('click');
        expect(w.vm.paymentMode).toBe('card');
        expect(w.vm.tranches).toEqual([]);
        await w.find('[data-testid="pos-payment-mode-cash"]').trigger('click');
        expect(w.vm.paymentMode).toBe('cash');
    });
});

describe('invariants de la zone gelée', () => {
    const src = fs.readFileSync(path.resolve(__dirname, '../../resources/js/components/admin/pos/PaymentComponent.vue'), 'utf8');

    it('les emits sont inchangés', () => {
        expect(src).toMatch(/emits:\s*\["payment-form:patch", "payment-form:reset", "order:confirmed"\]/);
    });
    it('aucun prix client : seules des tranches d\'encaissement sont construites', () => {
        const block = src.slice(src.indexOf('splitCardRemainder: function'), src.indexOf('addTranche: function'));
        expect(block).not.toMatch(/PricingService|unit_price|item_price|catalog/i);
    });
});
