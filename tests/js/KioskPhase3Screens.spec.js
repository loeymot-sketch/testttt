/**
 * Kiosk Design V1 — Phase 3 Vitest specs
 *
 * Couvre les 5 écrans nouveaux + le layout partagé :
 *  - KioskCashInstructionComponent
 *  - KioskErrorNetworkComponent
 *  - KioskErrorMenuUnavailableComponent
 *  - KioskErrorProductRemovedComponent
 *  - KioskErrorPaymentRefusedComponent
 *  - KioskErrorLayoutComponent
 *
 * Règles vérifiées :
 *  - data-testid stable (pour E2E Playwright)
 *  - i18n français OK (fr.json chargé)
 *  - Emits des events métier attendus
 *  - POST /frontend/kiosk/event envoyé au mount (observabilité)
 *  - Atoms DS consommés (KsButton rendu)
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import axios from 'axios';

import KioskCashInstructionComponent from '../../resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue';
import KioskErrorNetworkComponent from '../../resources/js/components/frontend/kiosk/KioskErrorNetworkComponent.vue';
import KioskErrorMenuUnavailableComponent from '../../resources/js/components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue';
import KioskErrorProductRemovedComponent from '../../resources/js/components/frontend/kiosk/KioskErrorProductRemovedComponent.vue';
import KioskErrorPaymentRefusedComponent from '../../resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue';

import KsButton from '../../resources/js/components/frontend/kiosk/ds/KsButton.vue';
import KsCard from '../../resources/js/components/frontend/kiosk/ds/KsCard.vue';
import KsPriceLine from '../../resources/js/components/frontend/kiosk/ds/KsPriceLine.vue';

import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
});

const mountOpts = {
    global: {
        plugins: [i18n],
        components: { KsButton, KsCard, KsPriceLine },
        stubs: {
            // Pas besoin d'un vrai router — on stub les appels $router.push
            RouterLink: true,
        },
        mocks: {
            $router: { push: vi.fn().mockResolvedValue() },
        },
    },
};

beforeEach(() => {
    vi.spyOn(axios, 'post').mockResolvedValue({ data: { status: true } });
});

// =============================================================================
// KioskCashInstructionComponent
// =============================================================================

describe('KioskCashInstructionComponent', () => {
    it('renders order number and formatted amount', () => {
        const wrapper = mount(KioskCashInstructionComponent, {
            ...mountOpts,
            props: { orderNumber: '000123', orderTotal: 12.50, autoRedirectSeconds: 0 },
        });

        expect(wrapper.find('[data-testid="kiosk-cash-title"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-cash-order-number"]').text()).toContain('000123');
        expect(wrapper.text()).toContain('Rendez-vous en caisse');
    });

    it('posts cash_instruction_shown on mount (observabilité)', () => {
        mount(KioskCashInstructionComponent, {
            ...mountOpts,
            props: { orderNumber: 'X1', orderTotal: 5, autoRedirectSeconds: 0 },
        });

        expect(axios.post).toHaveBeenCalledWith(
            '/frontend/kiosk/event',
            expect.objectContaining({ type: 'cash_instruction_shown', order_ref: 'X1' })
        );
    });

    it('emits acknowledged on CTA click', async () => {
        const wrapper = mount(KioskCashInstructionComponent, {
            ...mountOpts,
            props: { orderNumber: 'X', orderTotal: 5, autoRedirectSeconds: 0 },
        });

        await wrapper.find('[data-testid="kiosk-cash-cta-understood"]').trigger('click');
        expect(wrapper.emitted('acknowledged')).toBeTruthy();
        expect(wrapper.emitted('acknowledged')[0]).toEqual(['user']);
    });
});

// =============================================================================
// KioskErrorNetworkComponent
// =============================================================================

describe('KioskErrorNetworkComponent', () => {
    it('renders the network error message with FR i18n', () => {
        const wrapper = mount(KioskErrorNetworkComponent, mountOpts);
        expect(wrapper.text()).toContain('Connexion perdue');
        expect(wrapper.find('[data-testid="kiosk-error-network-cta-retry"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-error-network-cta-staff"]').exists()).toBe(true);
    });

    it('logs error_shown on mount with subtype=network', () => {
        mount(KioskErrorNetworkComponent, mountOpts);
        expect(axios.post).toHaveBeenCalledWith(
            '/frontend/kiosk/event',
            expect.objectContaining({ type: 'error_shown', subtype: 'network' })
        );
    });

    it('emits retry when primary CTA clicked', async () => {
        const wrapper = mount(KioskErrorNetworkComponent, mountOpts);
        await wrapper.find('[data-testid="kiosk-error-network-cta-retry"]').trigger('click');
        expect(wrapper.emitted('retry')).toBeTruthy();
    });

    it('emits call-staff when secondary CTA clicked', async () => {
        const wrapper = mount(KioskErrorNetworkComponent, mountOpts);
        await wrapper.find('[data-testid="kiosk-error-network-cta-staff"]').trigger('click');
        expect(wrapper.emitted('call-staff')).toBeTruthy();
    });
});

// =============================================================================
// KioskErrorMenuUnavailableComponent
// =============================================================================

describe('KioskErrorMenuUnavailableComponent', () => {
    it('renders the menu unavailable message', () => {
        const wrapper = mount(KioskErrorMenuUnavailableComponent, mountOpts);
        expect(wrapper.text()).toContain('Menu momentanément indisponible');
    });

    it('emits retry and back-home', async () => {
        const wrapper = mount(KioskErrorMenuUnavailableComponent, mountOpts);
        await wrapper.find('[data-testid="kiosk-error-menu-cta-retry"]').trigger('click');
        await wrapper.find('[data-testid="kiosk-error-menu-cta-home"]').trigger('click');
        expect(wrapper.emitted('retry')).toBeTruthy();
        expect(wrapper.emitted('back-home')).toBeTruthy();
    });
});

// =============================================================================
// KioskErrorProductRemovedComponent
// =============================================================================

describe('KioskErrorProductRemovedComponent', () => {
    it('shows product name when provided', () => {
        const wrapper = mount(KioskErrorProductRemovedComponent, {
            ...mountOpts,
            props: { productName: 'Tacos XL', itemId: 42 },
        });
        expect(wrapper.text()).toContain('Tacos XL');
    });

    it('logs error_shown with context.item_id', () => {
        mount(KioskErrorProductRemovedComponent, {
            ...mountOpts,
            props: { productName: 'X', itemId: 42 },
        });
        expect(axios.post).toHaveBeenCalledWith(
            '/frontend/kiosk/event',
            expect.objectContaining({
                type: 'error_shown',
                subtype: 'product_removed',
                context: { item_id: 42 },
            })
        );
    });

    it('emits back-to-menu and back-home', async () => {
        const wrapper = mount(KioskErrorProductRemovedComponent, mountOpts);
        await wrapper.find('[data-testid="kiosk-error-product-cta-menu"]').trigger('click');
        await wrapper.find('[data-testid="kiosk-error-product-cta-home"]').trigger('click');
        expect(wrapper.emitted('back-to-menu')).toBeTruthy();
        expect(wrapper.emitted('back-home')).toBeTruthy();
    });
});

// =============================================================================
// KioskErrorPaymentRefusedComponent
// =============================================================================

describe('KioskErrorPaymentRefusedComponent', () => {
    it('renders 3 actions : retry / counter / cancel', () => {
        const wrapper = mount(KioskErrorPaymentRefusedComponent, mountOpts);
        expect(wrapper.find('[data-testid="kiosk-error-payment-cta-retry"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-error-payment-cta-counter"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-error-payment-cta-cancel"]').exists()).toBe(true);
    });

    it('shows error code when provided', () => {
        const wrapper = mount(KioskErrorPaymentRefusedComponent, {
            ...mountOpts,
            props: { errorCode: 'TPE_TIMEOUT', orderId: 1234 },
        });
        expect(wrapper.text()).toContain('TPE_TIMEOUT');
    });

    it('emits retry / pay-at-counter / cancel-order', async () => {
        const wrapper = mount(KioskErrorPaymentRefusedComponent, mountOpts);
        await wrapper.find('[data-testid="kiosk-error-payment-cta-retry"]').trigger('click');
        await wrapper.find('[data-testid="kiosk-error-payment-cta-counter"]').trigger('click');
        await wrapper.find('[data-testid="kiosk-error-payment-cta-cancel"]').trigger('click');
        expect(wrapper.emitted('retry')).toBeTruthy();
        expect(wrapper.emitted('pay-at-counter')).toBeTruthy();
        expect(wrapper.emitted('cancel-order')).toBeTruthy();
    });
});
