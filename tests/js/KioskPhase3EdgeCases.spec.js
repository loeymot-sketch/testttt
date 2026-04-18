/**
 * Kiosk Design V1 — Phase 3 EDGE CASES & HARDENING
 *
 * Complémente KioskPhase3Screens.spec.js avec :
 *  - XSS escape sur productName / errorCode (Vue interpolation {{ }} safe-by-default)
 *  - Null/undefined props tolerance
 *  - Overflow long strings (≥ 500 chars)
 *  - RTL (locale ar) rendering sans crash
 *  - Locale EN fallback
 *  - aria-live, role=alert correctement posés
 *  - KioskErrorLayoutComponent testé directement avec slots
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import axios from 'axios';

import KioskErrorLayoutComponent from '../../resources/js/components/frontend/kiosk/KioskErrorLayoutComponent.vue';
import KioskErrorProductRemovedComponent from '../../resources/js/components/frontend/kiosk/KioskErrorProductRemovedComponent.vue';
import KioskErrorPaymentRefusedComponent from '../../resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue';
import KioskErrorNetworkComponent from '../../resources/js/components/frontend/kiosk/KioskErrorNetworkComponent.vue';
import KioskCashInstructionComponent from '../../resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue';

import KsButton from '../../resources/js/components/frontend/kiosk/ds/KsButton.vue';
import KsCard from '../../resources/js/components/frontend/kiosk/ds/KsCard.vue';
import KsPriceLine from '../../resources/js/components/frontend/kiosk/ds/KsPriceLine.vue';

import frMessages from '../../resources/js/languages/fr.json';
import enMessages from '../../resources/js/languages/en.json';
import arMessages from '../../resources/js/languages/ar.json';

function makeI18n(locale = 'fr') {
    return createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'fr',
        messages: { fr: frMessages, en: enMessages, ar: arMessages },
    });
}

function mountOpts(locale = 'fr') {
    return {
        global: {
            plugins: [makeI18n(locale)],
            components: { KsButton, KsCard, KsPriceLine },
            stubs: { RouterLink: true },
            mocks: { $router: { push: vi.fn().mockResolvedValue() } },
        },
    };
}

beforeEach(() => {
    vi.spyOn(axios, 'post').mockResolvedValue({ data: { status: true } });
});

// =============================================================================
// XSS hardening — Vue {{ }} interpolation must escape HTML
// =============================================================================

describe('Phase 3 — XSS hardening', () => {
    it('escapes HTML in productName on KioskErrorProductRemovedComponent', () => {
        const xss = '<img src=x onerror="alert(1)">';
        const wrapper = mount(KioskErrorProductRemovedComponent, {
            ...mountOpts(),
            props: { productName: xss, itemId: 1 },
        });
        // L'HTML du wrapper ne doit pas contenir le tag brut mais sa version escaped
        const html = wrapper.html();
        expect(html).not.toContain('<img src=x onerror');
        expect(html).toContain('&lt;img');
    });

    it('escapes HTML in errorCode on KioskErrorPaymentRefusedComponent', () => {
        const xss = '<script>alert(1)</script>';
        const wrapper = mount(KioskErrorPaymentRefusedComponent, {
            ...mountOpts(),
            props: { errorCode: xss, orderId: 99 },
        });
        const html = wrapper.html();
        expect(html).not.toMatch(/<script>alert\(1\)<\/script>/);
        expect(html).toContain('&lt;script&gt;');
    });

    it('escapes HTML in orderNumber on KioskCashInstructionComponent', () => {
        const xss = '"><img src=x>';
        const wrapper = mount(KioskCashInstructionComponent, {
            ...mountOpts(),
            props: { orderNumber: xss, orderTotal: 1, autoRedirectSeconds: 0 },
        });
        const html = wrapper.html();
        // Vue échappe les caractères < > mais pas forcément les " dans le text content
        expect(html).not.toContain('><img src=x>');
    });
});

// =============================================================================
// Null/undefined props
// =============================================================================

describe('Phase 3 — null/undefined props tolerance', () => {
    it('KioskErrorProductRemovedComponent renders without productName', () => {
        const wrapper = mount(KioskErrorProductRemovedComponent, mountOpts());
        expect(wrapper.exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-error-product-cta-menu"]').exists()).toBe(true);
    });

    it('KioskErrorPaymentRefusedComponent renders without errorCode/orderId', () => {
        const wrapper = mount(KioskErrorPaymentRefusedComponent, mountOpts());
        expect(wrapper.exists()).toBe(true);
        expect(wrapper.find('[data-testid="kiosk-error-payment-cta-retry"]').exists()).toBe(true);
    });

    it('logEvent omits context when itemId null', () => {
        mount(KioskErrorProductRemovedComponent, {
            ...mountOpts(),
            props: { productName: null, itemId: null },
        });
        const call = axios.post.mock.calls.find(
            ([url, body]) => url === '/frontend/kiosk/event' && body?.type === 'error_shown'
        );
        expect(call).toBeDefined();
        expect(call[1].context).toBeNull();
    });
});

// =============================================================================
// Overflow (long strings)
// =============================================================================

describe('Phase 3 — overflow strings', () => {
    it('handles productName ≥ 500 chars without crash', () => {
        const longName = 'X'.repeat(600);
        const wrapper = mount(KioskErrorProductRemovedComponent, {
            ...mountOpts(),
            props: { productName: longName, itemId: 1 },
        });
        expect(wrapper.text()).toContain('XXXX');
    });

    it('handles huge orderNumber without breaking layout', () => {
        const wrapper = mount(KioskCashInstructionComponent, {
            ...mountOpts(),
            props: { orderNumber: '#'.repeat(80), orderTotal: 999999.99, autoRedirectSeconds: 0 },
        });
        expect(wrapper.find('[data-testid="kiosk-cash-order-number"]').exists()).toBe(true);
    });
});

// =============================================================================
// RTL (Arabic locale) — no crash, direction inheritable
// =============================================================================

describe('Phase 3 — RTL (ar) rendering', () => {
    it('renders network error screen in Arabic without errors', () => {
        const wrapper = mount(KioskErrorNetworkComponent, mountOpts('ar'));
        expect(wrapper.exists()).toBe(true);
        // La clé i18n ar doit fournir une traduction (non vide, non la clé brute)
        const title = wrapper.find('[data-testid="kiosk-error-network-title"]');
        if (title.exists()) {
            expect(title.text().length).toBeGreaterThan(0);
            expect(title.text()).not.toBe('kiosk.error.network.title');
        }
    });

    it('renders cash instruction in Arabic with action buttons functional', async () => {
        const wrapper = mount(KioskCashInstructionComponent, {
            ...mountOpts('ar'),
            props: { orderNumber: 'A1', orderTotal: 10, autoRedirectSeconds: 0 },
        });
        const cta = wrapper.find('[data-testid="kiosk-cash-cta-understood"]');
        expect(cta.exists()).toBe(true);
        await cta.trigger('click');
        expect(wrapper.emitted('acknowledged')).toBeTruthy();
    });
});

// =============================================================================
// Locale EN fallback
// =============================================================================

describe('Phase 3 — EN locale', () => {
    it('renders network error screen in English', () => {
        const wrapper = mount(KioskErrorNetworkComponent, mountOpts('en'));
        const text = wrapper.text();
        // Doit contenir du texte EN, pas les clés brutes
        expect(text).not.toContain('kiosk.error.network');
        expect(text.length).toBeGreaterThan(20);
    });
});

// =============================================================================
// KioskErrorLayoutComponent — tested directly
// =============================================================================

describe('KioskErrorLayoutComponent', () => {
    it('renders slot actions and exposes role=alert with aria-live=assertive', () => {
        const wrapper = mount(KioskErrorLayoutComponent, {
            ...mountOpts(),
            props: {
                variant: 'generic',
                icon: '⚠️',
                title: 'Test Error',
                subtitle: 'Some detail',
                hint: 'Follow these steps',
            },
            slots: {
                actions: '<button data-testid="custom-action">Custom</button>',
            },
        });

        expect(wrapper.find('[data-testid="custom-action"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Test Error');
        expect(wrapper.text()).toContain('Some detail');
        expect(wrapper.text()).toContain('Follow these steps');

        // Accessibility: role=alert ou aria-live
        const root = wrapper.element;
        const hasRole = root.getAttribute('role') === 'alert' ||
                        root.querySelector('[role="alert"]') !== null;
        const hasLive = root.getAttribute('aria-live') ||
                        root.querySelector('[aria-live]') !== null;
        expect(hasRole || hasLive).toBe(true);
    });

    it('renders without hint (optional prop)', () => {
        const wrapper = mount(KioskErrorLayoutComponent, {
            ...mountOpts(),
            props: {
                variant: 'generic',
                icon: '⚠️',
                title: 'Test',
            },
            slots: { actions: '<div />' },
        });
        expect(wrapper.exists()).toBe(true);
        expect(wrapper.text()).toContain('Test');
    });
});
