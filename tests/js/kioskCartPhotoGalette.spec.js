/**
 * [PAIN-PHOTO 2026-10-29 · test-e2e ADV-03/04] Borne — panier : une ligne « Galette » montre la photo de la GALETTE.
 * Avant : « Pain : Galette » s'affichait sous la photo d'un sandwich. Le catalogue range ses variations par attribut,
 * en liste PLATE (forme réelle de la borne), TABLEAU ou OBJET indexé par id selon la source : les trois formes sont couvertes.
 * [Le banc de la première version n'en testait que deux et passait au vert alors que la borne servait la forme plate : photo inchangée.]
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import KioskCartComponent from '../../resources/js/components/frontend/kiosk/KioskCartComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const SANDWICH = '/images/menu/thumbs/sandwich-cayenne.webp';
const GALETTE_PREVIEW = '/storage/69/conversions/g-cayenne-preview.png';

const catalogue = (shape) => {
    const vars = [{ id: 450, name: 'Pain' }, { id: 451, name: 'Galette' }];
    return [
        { id: 22, name: 'Cayenne', thumb: SANDWICH, variations: shape === 'plat' ? vars : { 6: shape === 'array' ? vars : { 450: vars[0], 451: vars[1] } } },
        { id: 24, name: 'Galette Cayenne', thumb: '/images/menu/thumbs/galette-cayenne.webp', preview: GALETTE_PREVIEW },
    ];
};

const store = (items, all) => createStore({
    modules: {
        kioskCart: {
            namespaced: true,
            getters: { items: () => items, count: () => items.length, subtotal: () => 7.4, total: () => 7.4, loyaltyDiscount: () => 0, upsellShown: () => false, orderType: () => 10, isEmpty: () => false },
            actions: { updateQuantity: vi.fn(), removeItem: vi.fn(), reset: vi.fn(), markUpsellShown: vi.fn(), popItem: vi.fn(), setOrderType: vi.fn() },
        },
        kioskMenu: { namespaced: true, getters: { categories: () => [], selectedCategoryId: () => null, allItems: () => all } },
    },
});

const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages } });
const line = (variationId) => ({ item_id: 22, name: 'Cayenne', quantity: 1, convert_price: 7.4, total: 7.4, image: SANDWICH, item_variations: [{ id: variationId, variation_name: 'Type de Pain', name: '' }], item_extras: [] });
const imgSrc = (items, all) => mount(KioskCartComponent, { global: { plugins: [store(items, all), i18n], mocks: { $router: { push: vi.fn() }, $route: { query: {}, params: {} } } } })
    .find('.kiosk-cart-item-img img').attributes('src');

describe('Borne — photo de la ligne de panier selon le pain', () => {
    beforeEach(() => { global.window.foodkingConfig = { discountsEnabled: true, kioskPromoEnabled: true }; });

    for (const shape of ['plat', 'array', 'objet']) {
        it(`galette (variations en ${shape}) → photo de la galette`, () => {
            expect(imgSrc([line(451)], catalogue(shape))).toBe(GALETTE_PREVIEW);
        });
        it(`pain (variations en ${shape}) → photo du sandwich inchangée`, () => {
            expect(imgSrc([line(450)], catalogue(shape))).toBe(SANDWICH);
        });
    }

    it('catalogue sans galette jumelle → repli sur la photo de la ligne (jamais d\'image cassée)', () => {
        expect(imgSrc([line(451)], catalogue('array').slice(0, 1))).toBe(SANDWICH);
    });

    it('le résumé de la ligne dit « Galette », pas le nom de l\'attribut « Type de Pain »', () => {
        const w = mount(KioskCartComponent, { global: { plugins: [store([line(451)], catalogue('plat')), i18n], mocks: { $router: { push: vi.fn() }, $route: { query: {}, params: {} } } } });
        const txt = w.find('[data-testid="kiosk-cart-item-options-0"]').text();
        expect(txt).toContain('Galette');
        expect(txt).not.toContain('Type de Pain');
    });
});

// ── Les autres écrans de la borne qui montrent la ligne / le récap (constats R8-01 / R8-02 du superviseur adversaire) ──
import KsCartBottomSheet from '../../resources/js/components/frontend/kiosk/ds/KsCartBottomSheet.vue';
import KioskOrderSummaryComponent from '../../resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue';
import { kioskLinePhoto } from '../../resources/js/helpers/kioskMedia';

describe('Borne — bandeau panier (KsCartBottomSheet)', () => {
    const mountStrip = (variationId, cat) => mount(KsCartBottomSheet, { props: { items: [{ ...line(variationId), total: 7.4 }], formatPrice: (n) => String(n), catalog: cat }, global: { plugins: [i18n] } });
    it('ligne galette → photo de la galette ; ligne pain → photo du sandwich', () => {
        expect(mountStrip(451, catalogue('plat')).find('img.kiosk-cart-strip-img').attributes('src')).toBe(GALETTE_PREVIEW);
        expect(mountStrip(450, catalogue('plat')).find('img.kiosk-cart-strip-img').attributes('src')).toBe(SANDWICH);
    });
    it('sans catalogue → photo d\'origine', () => {
        expect(mountStrip(451, []).find('img.kiosk-cart-strip-img').attributes('src')).toBe(SANDWICH);
    });
});

describe('Borne — récap de l\'assistant (KioskOrderSummaryComponent)', () => {
    const mountRecap = (painId) => {
        const all = catalogue('plat');
        const item = { ...all[0], id: 22, convert_price: 7.4, itemAttributes: [{ id: 6, name: 'Type de Pain' }], extras: [] };
        const st = createStore({ modules: { kioskMenu: { namespaced: true, getters: { allItems: () => all } } } });
        return mount(KioskOrderSummaryComponent, { props: { step: {}, item, selections: { pain: painId, garnitures: {}, sauces: {}, totalViandes: 0 } }, global: { plugins: [st, i18n] } });
    };
    it('Galette choisie → l\'en-tête « Cayenne » montre la galette', () => {
        expect(mountRecap(451).find('[data-testid="kiosk-order-summary-main-item"] img').attributes('src')).toBe(GALETTE_PREVIEW);
    });
    it('Pain choisi → l\'en-tête garde le sandwich', () => {
        expect(mountRecap(450).find('[data-testid="kiosk-order-summary-main-item"] img').attributes('src')).toBe(SANDWICH);
    });
});

describe('kioskLinePhoto', () => {
    it('ne lève jamais : entrées vides → repli', () => {
        expect(kioskLinePhoto(null, null, null, 'x')).toBe('x');
        expect(kioskLinePhoto({ name: 'Cayenne' }, [451], undefined, 'x')).toBe('x');
    });
});
