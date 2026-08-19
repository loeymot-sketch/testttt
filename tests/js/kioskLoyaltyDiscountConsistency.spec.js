/**
 * [C39 heal 2026-07-06] Cohérence remise fidélité borne : « affiché == facturé ».
 *
 * BUG (C39, P2 — confirmé par triage adversaire) : sur la borne, un client
 * pouvait atteindre « Mon compte » (KioskCategoriesComponent → kiosk.loyalty),
 * saisir son loyalty_code, choisir « utiliser mes points » → le panier affichait
 * une ligne « -X € » (KioskCartComponent) et un total réduit, MAIS le payload
 * borne (buildKioskQuotePayload / buildKioskOrderPayload) n'envoie JAMAIS de
 * champ discount (loyalty_code seul → le serveur `withKioskLoyaltyDiscount`
 * early-return, discount=0) → le client voyait « -X » mais était débité PLEIN
 * TARIF au TPE et ses points n'étaient pas décrémentés.
 *
 * Le heal W2 (2026-06-26) avait gaté le bloc PROMO + le bouton fidélité DU
 * PANIER derrière `discountsEnabled && kioskPromoEnabled`, mais PAS l'écran de
 * redeem fidélité (atteignable via « Mon compte »). C39 aligne donc le redeem
 * fidélité + l'affichage de la remise au panier sur le MÊME couple de flags.
 *
 * FIX D'ORIGINE (option a, 2026-07-06) : masquer le redeem derrière `kioskPromoEnabled`
 * (défaut FALSE) — « caché = aucune promesse non tenue ». Le câblage restait cassé, assumé.
 *
 * [FIDÉLITÉ BORNE 2026-08-19] ON EST PASSÉ À L'OPTION (b) : ON A CÂBLÉ.
 * Le payload transmet désormais la demande (`loyalty_redeem_points`), le serveur l'applique, et
 * le débit a lieu APRÈS le sceau du devis — sans quoi le sceau, qui recalcule le rachat sur le
 * solde vivant, refusait la commande (« Order quote intent mismatch », prouvé par
 * `tests/Feature/Loyalty/KioskRedeemThroughSealedQuoteTest.php`). Le rachat a donc son propre
 * interrupteur, `kioskLoyaltyRedeemEnabled` : les CODES PROMO, eux, restent cassés et gardent
 * le leur.
 *
 * L'INVARIANT PROTÉGÉ N'A PAS CHANGÉ — il est seulement satisfait autrement : ce qui est
 * AFFICHÉ est ce qui sera FACTURÉ. Hier en cachant, aujourd'hui en tenant la promesse.
 *
 * Ce spec prouve :
 *  (a) interrupteur fidélité OFF → aucune ligne remise, total affiché == sous-total ;
 *  (b) interrupteur ON → remise affichée ET réellement transmise au serveur ;
 *  (c) le payload ne porte AUCUN champ monétaire : la demande voyage en POINTS.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskCartComponent from '../../resources/js/components/frontend/kiosk/KioskCartComponent.vue';
import KioskLoyaltyComponent from '../../resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue';
import { buildKioskQuotePayload } from '../../resources/js/store/modules/kioskCart';
import frMessages from '../../resources/js/languages/fr.json';

const ORDER_TYPE_KIOSK = 25;

const i18n = createI18n({
    legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages },
});

// ---------------------------------------------------------------------------
// Panier : store configurable (subtotal + loyaltyDiscount).
// ---------------------------------------------------------------------------
function makeCartStore({ subtotal = 20, loyaltyDiscount = 0 } = {}) {
    const items = [{
        item_id: 42, name: 'Tacos XL', quantity: 1, convert_price: subtotal, total: subtotal,
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
                    subtotal: () => subtotal,
                    // Le getter store `total` soustrait la remise (comme en prod). Le FIX
                    // du composant DOIT neutraliser cet affichage quand le flag est OFF.
                    total: () => Math.max(0, subtotal - loyaltyDiscount),
                    loyaltyDiscount: () => loyaltyDiscount,
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
                    startEditingCartItem: vi.fn(), pruneUnavailableLines: vi.fn(),
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

function mountCart(store) {
    return mount(KioskCartComponent, {
        global: {
            plugins: [store, i18n],
            mocks: { $router: { push: vi.fn(), replace: vi.fn() }, $route: { query: {}, params: {} } },
        },
    });
}

// ---------------------------------------------------------------------------
// (a) + (c) Panier : flag OFF → pas de remise affichée, total == sous-total.
// ---------------------------------------------------------------------------
describe('KioskCartComponent — remise fidélité affichée == facturée (C39)', () => {
    let saved;
    beforeEach(() => { saved = global.window.foodkingConfig; });
    afterEach(() => { global.window.foodkingConfig = saved; });

    it('(a) interrupteur fidélité OFF + loyaltyDiscount persisté → AUCUNE ligne remise, total == sous-total', () => {
        // Le kill-switch fidélité doit vraiment couper : un discount resté en mémoire (session
        // antérieure au flip du flag) ne doit jamais s'afficher, sinon on annonce une remise
        // que le payload n'enverra pas.
        global.window.foodkingConfig = { discountsEnabled: true, kioskLoyaltyRedeemEnabled: false };
        const store = makeCartStore({ subtotal: 20, loyaltyDiscount: 5 });
        const w = mountCart(store);

        // Pas de ligne « -5 € » : le payload ne l'enverra jamais.
        expect(w.find('[data-testid="kiosk-cart-loyalty-discount"]').exists()).toBe(false);

        // Total affiché == sous-total (plein tarif = ce qui sera facturé).
        const totalTxt = w.find('[data-testid="kiosk-cart-total"]').text();
        const subtotalTxt = w.find('[data-testid="kiosk-cart-subtotal"]').text();
        expect(totalTxt).toBe(subtotalTxt);
        expect(totalTxt).not.toContain('-');
    });

    it('(a-bis) interrupteur fidélité OFF explicite → même comportement, quel que soit le promo', () => {
        global.window.foodkingConfig = {
            discountsEnabled: true,
            kioskPromoEnabled: true,
            kioskLoyaltyRedeemEnabled: false,
        };
        const store = makeCartStore({ subtotal: 20, loyaltyDiscount: 5 });
        const w = mountCart(store);
        expect(w.find('[data-testid="kiosk-cart-loyalty-discount"]').exists()).toBe(false);
        expect(w.find('[data-testid="kiosk-cart-total"]').text())
            .toBe(w.find('[data-testid="kiosk-cart-subtotal"]').text());
    });

    it('(c) interrupteur fidélité ON → remise affichée ET total réduit (parité avec le getter store)', () => {
        global.window.foodkingConfig = { discountsEnabled: true, kioskLoyaltyRedeemEnabled: true };
        const store = makeCartStore({ subtotal: 20, loyaltyDiscount: 5 });
        const w = mountCart(store);

        // Quand le flag est ON, la remise est légitimement affichée…
        expect(w.find('[data-testid="kiosk-cart-loyalty-discount"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-cart-loyalty-discount"]').text()).toContain('-');

        // …et le total affiché < sous-total (remise appliquée à l'affichage).
        const totalTxt = w.find('[data-testid="kiosk-cart-total"]').text();
        const subtotalTxt = w.find('[data-testid="kiosk-cart-subtotal"]').text();
        expect(totalTxt).not.toBe(subtotalTxt);
    });
});

// ---------------------------------------------------------------------------
// (b) Contrat source : le redeem fidélité est gaté par kioskPromoEnabled,
//     EXACTEMENT comme la promo du panier.
// ---------------------------------------------------------------------------
describe('KioskLoyaltyComponent.kioskPromoEnabled — computed isolé (gate redeem)', () => {
    let saved;
    beforeEach(() => { saved = global.window.foodkingConfig; });
    afterEach(() => { global.window.foodkingConfig = saved; });

    const kioskPromoEnabled = () => KioskLoyaltyComponent.computed.kioskPromoEnabled.call(null);

    it('FALSE quand window.foodkingConfig est absent (défaut fail-safe)', () => {
        global.window.foodkingConfig = undefined;
        expect(kioskPromoEnabled()).toBe(false);
    });

    it('FALSE quand la clé est absente (discountsEnabled seul ne ré-expose pas le redeem)', () => {
        global.window.foodkingConfig = { discountsEnabled: true };
        expect(kioskPromoEnabled()).toBe(false);
    });

    it('FALSE sur valeurs truthy non-strictes (1, "true") — strict === true requis', () => {
        global.window.foodkingConfig = { kioskPromoEnabled: 1 };
        expect(kioskPromoEnabled()).toBe(false);
        global.window.foodkingConfig = { kioskPromoEnabled: 'true' };
        expect(kioskPromoEnabled()).toBe(false);
    });

    it('TRUE uniquement quand kioskPromoEnabled === true', () => {
        global.window.foodkingConfig = { kioskPromoEnabled: true };
        expect(kioskPromoEnabled()).toBe(true);
    });
});

// ---------------------------------------------------------------------------
// (b) Monté : l'écran balance masque les options de redeem quand le flag OFF,
//     même avec assez de points (canRedeem true) et discountsEnabled ON.
// ---------------------------------------------------------------------------
function makeLoyaltyStore() {
    return createStore({
        modules: {
            kioskCart: {
                namespaced: true,
                getters: { total: () => 20, upsellShown: () => false, items: () => [] },
                actions: { setLoyalty: vi.fn(), markUpsellShown: vi.fn() },
            },
            kioskMenu: { namespaced: true, getters: { categories: () => [] } },
            kioskSettings: { namespaced: true, state: () => ({ locale: 'fr' }) },
        },
    });
}

async function mountLoyaltyOnBalance(config) {
    global.window.foodkingConfig = config;
    // mounted() → loadConfig() fait un axios.get ; on le neutralise pour éviter le réseau.
    const axios = (await import('axios')).default;
    const getSpy = vi.spyOn(axios, 'get').mockResolvedValue({ data: { data: {} } });

    const w = mount(KioskLoyaltyComponent, {
        global: {
            plugins: [makeLoyaltyStore(), i18n],
            mocks: { $router: { push: vi.fn(), replace: vi.fn() }, $route: { query: {}, params: {} } },
            stubs: { KsConsentModal: true, KsVirtualKeyboard: true },
        },
    });
    // Passe à l'étape « balance » avec un client qui a assez de points (canRedeem=true).
    await w.setData({
        step: 'balance',
        customer: { name: 'Jean Test', loyalty_point: 500, loyalty_code: 'ABC123' },
        discountValue: 5,
        minRedeemPoints: 100,
    });
    return { w, getSpy };
}

describe('KioskLoyaltyComponent — redeem gaté au montage (parité promo)', () => {
    let saved;
    beforeEach(() => { saved = global.window.foodkingConfig; });
    afterEach(() => { global.window.foodkingConfig = saved; vi.restoreAllMocks(); });

    it('interrupteur fidélité OFF → options « utiliser mes points » CACHÉES', async () => {
        const { w } = await mountLoyaltyOnBalance({ discountsEnabled: true, kioskLoyaltyRedeemEnabled: false });
        expect(w.find('[data-testid="kiosk-loyalty-redeem-options"]').exists()).toBe(false);
    });

    it('interrupteur fidélité OFF → PAS d\'équivalence « = X € de réduction » (promesse non tenue)', async () => {
        const { w } = await mountLoyaltyOnBalance({ discountsEnabled: true, kioskLoyaltyRedeemEnabled: false });
        // Le solde brut reste (consultation), mais l'équivalence-réduction est masquée.
        expect(w.find('[data-testid="kiosk-loyalty-points-equiv"]').exists()).toBe(false);
        expect(w.find('.kiosk-loyalty-points-value').text()).toContain('500');
    });

    it('interrupteur fidélité ON → équivalence-réduction VISIBLE', async () => {
        const { w } = await mountLoyaltyOnBalance({ discountsEnabled: true, kioskLoyaltyRedeemEnabled: true });
        expect(w.find('[data-testid="kiosk-loyalty-points-equiv"]').exists()).toBe(true);
    });

    it('le rachat ne dépend PLUS du drapeau des codes promo (défaut cassé qui n\'est pas le sien)', async () => {
        const { w } = await mountLoyaltyOnBalance({ discountsEnabled: true, kioskPromoEnabled: false });
        expect(w.find('[data-testid="kiosk-loyalty-redeem-options"]').exists()).toBe(true);
    });

    it('interrupteur fidélité ON → options de redeem VISIBLES', async () => {
        const { w } = await mountLoyaltyOnBalance({ discountsEnabled: true, kioskLoyaltyRedeemEnabled: true });
        expect(w.find('[data-testid="kiosk-loyalty-redeem-options"]').exists()).toBe(true);
    });

    it('applyLoyalty : interrupteur fidélité OFF ne pose JAMAIS de discount, même si redeemChoice=yes', async () => {
        const store = makeLoyaltyStore();
        const setLoyaltySpy = vi.spyOn(store._actions['kioskCart/setLoyalty'], '0');
        global.window.foodkingConfig = { discountsEnabled: true, kioskLoyaltyRedeemEnabled: false };
        const axios = (await import('axios')).default;
        vi.spyOn(axios, 'get').mockResolvedValue({ data: { data: {} } });

        const w = mount(KioskLoyaltyComponent, {
            global: {
                plugins: [store, i18n],
                mocks: { $router: { push: vi.fn(), replace: vi.fn() }, $route: { query: {}, params: {} } },
                stubs: { KsConsentModal: true, KsVirtualKeyboard: true },
            },
        });
        await w.setData({
            step: 'balance',
            customer: { name: 'Jean Test', loyalty_point: 500, loyalty_code: 'ABC123' },
            discountValue: 5,
            minRedeemPoints: 100,
            redeemChoice: 'yes', // même si un état résiduel force 'yes'…
        });
        await w.vm.applyLoyalty();
        // …le discount posé DOIT être 0 (aucune remise non transmise au payload).
        expect(setLoyaltySpy).toHaveBeenCalledWith(expect.objectContaining({ discount: 0 }));
    });
});

// ---------------------------------------------------------------------------
// (c) Contrat payload : la demande de rachat EST transmise — en POINTS, jamais en argent.
//     C'est ce qui rend « affiché == facturé » vrai par application, et non par masquage.
// ---------------------------------------------------------------------------
describe('buildKioskQuotePayload — la demande de rachat voyage en POINTS (contrat)', () => {
    it('transmet loyalty_redeem_points, et AUCUN champ monétaire', () => {
        const state = {
            orderType: ORDER_TYPE_KIOSK,
            loyaltyCustomer: { loyalty_code: 'ABC123' },
            loyaltyDiscount: 5,        // valeur d'AFFICHAGE (euros)
            loyaltyRedeemPoints: 500,  // ce qu'on TRANSMET (quantité)
            promoCode: null,
            items: [{ item_id: 42, quantity: 1, item_variations: [], item_extras: [] }],
        };
        const payload = buildKioskQuotePayload(state, { orderType: ORDER_TYPE_KIOSK });

        expect(payload.loyalty_code).toBe('ABC123');
        // Le maillon qui manquait : sans lui, le serveur sortait immédiatement et facturait
        // plein tarif pendant que le panier affichait « -5 € ».
        expect(payload.loyalty_redeem_points).toBe(500);

        // L'invariant SSOT/NF525 tient toujours : le prix appartient au serveur, le client
        // n'envoie aucun montant. Des points ne sont pas de l'argent.
        expect(payload).not.toHaveProperty('discount');
        expect(payload).not.toHaveProperty('loyalty_discount');
        expect(payload).not.toHaveProperty('loyaltyDiscount');
        expect(payload).not.toHaveProperty('subtotal');
        expect(payload).not.toHaveProperty('total');
    });

    it('sans rachat demandé → 0 point, et toujours aucun champ monétaire', () => {
        const state = {
            orderType: ORDER_TYPE_KIOSK,
            loyaltyCustomer: { loyalty_code: 'ABC123' },
            loyaltyDiscount: 0,
            loyaltyRedeemPoints: 0,
            promoCode: null,
            items: [{ item_id: 42, quantity: 1, item_variations: [], item_extras: [] }],
        };
        const payload = buildKioskQuotePayload(state, { orderType: ORDER_TYPE_KIOSK });
        // La clé est TOUJOURS présente : une clé conditionnelle ferait diverger le devis de la
        // commande, et le sceau refuserait la vente.
        expect(payload.loyalty_redeem_points).toBe(0);
        expect(payload).not.toHaveProperty('discount');
    });
});
