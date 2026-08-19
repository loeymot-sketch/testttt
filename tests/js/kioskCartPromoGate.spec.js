/**
 * [W2 audit heal 2026-06-26] Kiosk promo/loyalty UI gate.
 *
 * BUG (W2 audit): the borne cart showed a promo-code + loyalty-redeem block
 * promising a "-X €" the backend never applies — the kiosk only sends
 * `kiosk_promo_code` metadata, never a `coupon_id`, so the order is created at
 * full price while the cart UI lies. The shared `discountsEnabled`
 * (pos.manual_discount_enabled) flag MUST stay on (it drives the legitimate POS
 * manual discount + the web checkout), so a DEDICATED kiosk flag
 * (window.foodkingConfig.kioskPromoEnabled, default FALSE) now gates the kiosk
 * promo + loyalty entries via `v-if="discountsEnabled && kioskPromoEnabled"`.
 *
 * Ce spec prouve la LOGIQUE du gate :
 *  - le computed `kioskPromoEnabled` isolé (lit window.foodkingConfig, défaut FALSE) ;
 *  - le `v-if` combiné réel, monté : caché sauf si LES DEUX flags sont true.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskCartComponent from '../../resources/js/components/frontend/kiosk/KioskCartComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const ORDER_TYPE_KIOSK = 25;

// ---------------------------------------------------------------------------
// 1) Computed isolé — la logique booléenne du gate dédié borne.
// ---------------------------------------------------------------------------
describe('KioskCartComponent.kioskPromoEnabled — computed isolé (gate dédié)', () => {
    let saved;
    beforeEach(() => { saved = global.window.foodkingConfig; });
    afterEach(() => { global.window.foodkingConfig = saved; });

    const kioskPromoEnabled = () => KioskCartComponent.computed.kioskPromoEnabled.call(null);
    const discountsEnabled = () => KioskCartComponent.computed.discountsEnabled.call(null);

    it('FALSE quand window.foodkingConfig est absent (défaut fail-safe = caché)', () => {
        global.window.foodkingConfig = undefined;
        expect(kioskPromoEnabled()).toBe(false);
    });

    it('FALSE quand la clé kioskPromoEnabled est absente de la config', () => {
        global.window.foodkingConfig = { discountsEnabled: true };
        expect(kioskPromoEnabled()).toBe(false);
    });

    it('FALSE quand kioskPromoEnabled vaut explicitement false', () => {
        global.window.foodkingConfig = { discountsEnabled: true, kioskPromoEnabled: false };
        expect(kioskPromoEnabled()).toBe(false);
    });

    it('FALSE sur les valeurs truthy non-strictes (1, "true") — strict === true requis', () => {
        global.window.foodkingConfig = { kioskPromoEnabled: 1 };
        expect(kioskPromoEnabled()).toBe(false);
        global.window.foodkingConfig = { kioskPromoEnabled: 'true' };
        expect(kioskPromoEnabled()).toBe(false);
    });

    it('TRUE uniquement quand kioskPromoEnabled === true', () => {
        global.window.foodkingConfig = { kioskPromoEnabled: true };
        expect(kioskPromoEnabled()).toBe(true);
    });

    it('condition combinée du v-if : (discountsEnabled && kioskPromoEnabled)', () => {
        // (a) flag partagé ON mais gate borne OFF → bloc CACHÉ (le bug W2 corrigé)
        global.window.foodkingConfig = { discountsEnabled: true, kioskPromoEnabled: false };
        expect(discountsEnabled() && kioskPromoEnabled()).toBe(false);

        // gate borne ON mais flag partagé OFF → toujours CACHÉ (ni l'un ni l'autre seul ne ré-expose)
        global.window.foodkingConfig = { discountsEnabled: false, kioskPromoEnabled: true };
        expect(discountsEnabled() && kioskPromoEnabled()).toBe(false);

        // (b) LES DEUX true → VISIBLE
        global.window.foodkingConfig = { discountsEnabled: true, kioskPromoEnabled: true };
        expect(discountsEnabled() && kioskPromoEnabled()).toBe(true);
    });
});

// ---------------------------------------------------------------------------
// 2) Composant monté — le v-if réel cache/affiche le bloc promo + bouton fidélité.
// ---------------------------------------------------------------------------
function makeStore() {
    const items = [{
        item_id: 42, name: 'Tacos XL', quantity: 1, convert_price: 8, total: 8,
        image: null, item_variations: [], item_extras: [],
        item_variation_total: 0, item_extra_total: 0,
    }];
    return createStore({
        modules: {
            kioskCart: {
                namespaced: true,
                getters: {
                    items: () => items,
                    count: () => items.length,
                    subtotal: () => 8,
                    total: () => 8,
                    loyaltyDiscount: () => 0,
                    upsellShown: () => false,
                    orderType: () => ORDER_TYPE_KIOSK,
                    isEmpty: () => false,
                    promoCode: () => null,
                    promoDiscount: () => 0,
                    promoError: () => null,
                    promoLoading: () => false,
                },
                actions: {
                    updateQuantity: vi.fn(), removeItem: vi.fn(), reset: vi.fn(),
                    markUpsellShown: vi.fn(), popItem: vi.fn(), setOrderType: vi.fn(),
                    quoteOrder: vi.fn(), validatePromo: vi.fn(), clearPromo: vi.fn(),
                },
            },
            kioskMenu: {
                namespaced: true,
                getters: { categories: () => [], selectedCategoryId: () => null, allItems: () => [] },
            },
            frontendSetting: {
                namespaced: true,
                getters: { lists: () => ({}) },
                actions: { lists: vi.fn() },
            },
        },
    });
}

const i18n = createI18n({
    legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages },
});

function mountCart() {
    return mount(KioskCartComponent, {
        global: {
            plugins: [makeStore(), i18n],
            mocks: { $router: { push: vi.fn(), replace: vi.fn() }, $route: { query: {}, params: {} } },
        },
    });
}

describe('KioskCartComponent — v-if réel du gate promo/fidélité', () => {
    let saved;
    beforeEach(() => { saved = global.window.foodkingConfig; });
    afterEach(() => { global.window.foodkingConfig = saved; });

    /*
     * [FIDÉLITÉ BORNE 2026-08-19] LES DEUX BLOCS ONT ÉTÉ SÉPARÉS.
     *
     * Ils partageaient `kioskPromoEnabled` depuis le W2 (2026-06-26) parce qu'ils promettaient
     * alors tous les deux une remise que le serveur n'appliquait pas. Ce n'est plus vrai que du
     * PROMO : le rachat de points est câblé (`loyalty_redeem_points` dans le payload) et prouvé
     * bout-en-bout, sceau du devis compris (`KioskRedeemThroughSealedQuoteTest`). Le garder
     * derrière le drapeau du promo, c'était punir la fidélité pour un défaut qui n'est pas le
     * sien. Ce qui est vérifié ici reste le même invariant : ce qu'on AFFICHE est ce qu'on
     * FACTURE — chaque bloc suit désormais l'interrupteur de sa propre plomberie.
     */
    it('gate promo OFF → promo CACHÉ, fidélité VISIBLE (elle a son propre interrupteur)', () => {
        global.window.foodkingConfig = { discountsEnabled: true, kioskPromoEnabled: false };
        const w = mountCart();
        expect(w.find('[data-testid="kiosk-cart-promo"]').exists()).toBe(false);
        expect(w.find('[data-testid="kiosk-cart-loyalty-btn"]').exists()).toBe(true);
    });

    it('gate fidélité OFF → fidélité CACHÉE (le kill-switch doit vraiment couper)', () => {
        global.window.foodkingConfig = {
            discountsEnabled: true,
            kioskPromoEnabled: true,
            kioskLoyaltyRedeemEnabled: false,
        };
        const w = mountCart();
        expect(w.find('[data-testid="kiosk-cart-loyalty-btn"]').exists()).toBe(false);
        // …sans emporter le promo avec elle : c'est tout l'intérêt d'avoir séparé les deux.
        expect(w.find('[data-testid="kiosk-cart-promo"]').exists()).toBe(true);
    });

    it('clé kioskPromoEnabled absente → promo CACHÉ (défaut fail-safe inchangé)', () => {
        global.window.foodkingConfig = { discountsEnabled: true };
        const w = mountCart();
        expect(w.find('[data-testid="kiosk-cart-promo"]').exists()).toBe(false);
    });

    it('LES DEUX flags ON → promo + fidélité VISIBLES', () => {
        global.window.foodkingConfig = { discountsEnabled: true, kioskPromoEnabled: true };
        const w = mountCart();
        expect(w.find('[data-testid="kiosk-cart-promo"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-cart-loyalty-btn"]').exists()).toBe(true);
    });
});
