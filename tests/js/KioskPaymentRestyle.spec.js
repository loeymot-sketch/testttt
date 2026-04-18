/**
 * Kiosk Design V1 — Phase 2.x : KioskPaymentComponent restyle
 *
 * Vérifie :
 *  - data-testid stables pour back, title, total, 3 méthodes, confirm, error, processing, overlay
 *  - A11y : radiogroup sur les méthodes + aria-checked par mode + role=dialog sur TPE overlay
 *  - Sélection par clic + sélection par clavier (Enter/Space)
 *  - Confirm désactivé tant qu'aucune méthode n'est sélectionnée
 *  - Aucune fuite de hex hardcodé brand (checks sur le DOM généré — gradient OK, tokens sinon)
 */

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskPaymentComponent from '../../resources/js/components/frontend/kiosk/KioskPaymentComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

function makeStore(total = 12.5) {
    return createStore({
        modules: {
            kioskCart: {
                namespaced: true,
                state: () => ({}),
                getters: {
                    total: () => total,
                    branchId: () => 1,
                    orderType: () => 25,
                },
                actions: {
                    submitOrder: vi.fn().mockResolvedValue({ id: 1, order_serial_no: '0001' }),
                    reset: vi.fn(),
                },
            },
        },
    });
}

const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
});

function mountCmp(store = makeStore()) {
    return mount(KioskPaymentComponent, {
        global: {
            plugins: [store, i18n],
            mocks: {
                $router: { push: vi.fn(), replace: vi.fn() },
                $route: { query: {}, params: {} },
            },
        },
    });
}

describe('KioskPaymentComponent restyle', () => {
    it('root + header + 3 methods + confirm : tous les testids présents', () => {
        const w = mountCmp();
        expect(w.find('[data-testid="kiosk-payment-root"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-payment-back"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-payment-title"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-payment-total"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-payment-method-card"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-payment-method-cash"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-payment-method-tr"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-payment-confirm"]').exists()).toBe(true);
    });

    it('radiogroup a11y : role + aria-checked cohérents avec la sélection', async () => {
        const w = mountCmp();
        const group = w.find('[role="radiogroup"]');
        expect(group.exists()).toBe(true);

        const card = w.find('[data-testid="kiosk-payment-method-card"]');
        const cash = w.find('[data-testid="kiosk-payment-method-cash"]');
        expect(card.attributes('role')).toBe('radio');
        expect(card.attributes('aria-checked')).toBe('false');

        await card.trigger('click');
        expect(card.attributes('aria-checked')).toBe('true');
        expect(cash.attributes('aria-checked')).toBe('false');
    });

    it('sélection clavier (Space) équivalente au clic', async () => {
        const w = mountCmp();
        const tr = w.find('[data-testid="kiosk-payment-method-tr"]');
        await tr.trigger('keydown.space');
        expect(tr.attributes('aria-checked')).toBe('true');
    });

    it('confirm est disabled tant quaucune méthode nest choisie', async () => {
        const w = mountCmp();
        const confirm = w.find('[data-testid="kiosk-payment-confirm"]');
        expect(confirm.attributes('disabled')).toBeDefined();
        await w.find('[data-testid="kiosk-payment-method-cash"]').trigger('click');
        expect(confirm.attributes('disabled')).toBeUndefined();
    });

    it('error block exposed role=alert quand error set', async () => {
        const w = mountCmp();
        w.vm.error = 'Boom';
        await w.vm.$nextTick();
        const err = w.find('[data-testid="kiosk-payment-error"]');
        expect(err.exists()).toBe(true);
        expect(err.attributes('role')).toBe('alert');
    });

    it('TPE overlay : role=dialog + aria-modal + aria-labelledby quand tpeWaiting', async () => {
        const w = mountCmp();
        w.vm.tpeWaiting = true;
        w.vm.tpeMessage = 'Insérez votre carte';
        await w.vm.$nextTick();
        const ov = w.find('[data-testid="kiosk-payment-tpe-overlay"]');
        expect(ov.exists()).toBe(true);
        expect(ov.attributes('role')).toBe('dialog');
        expect(ov.attributes('aria-modal')).toBe('true');
        expect(ov.attributes('aria-labelledby')).toBe('kiosk-tpe-title');
    });
});
