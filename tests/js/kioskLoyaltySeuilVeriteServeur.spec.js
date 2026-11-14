/**
 * [VG-2026-10-21 M5-101] Écran fidélité de la BORNE : à 925 points, sous le seuil serveur de 1000,
 * il affichait en vert « = 7,40 € de réduction sur cette commande » (= le panier entier) puis
 * « il en faut au moins 1000 ». Aucune remise n'était appliquée : promesse non tenue.
 *
 * Contrat : l'écran dit la vérité du SERVEUR — seuil (`/loyalty/config` → min_redeem_points),
 * solde et valeur (`/loyalty/check`). Pas d'équivalence en euros sous le seuil ; et si le seuil
 * n'a pas pu être lu, la borne n'en invente pas un (l'ancien repli « 100 » proposait un rachat
 * que le serveur refusait).
 */
import { describe, it, expect, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import axios from 'axios';

import KioskLoyaltyComponent from '../../resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages } });

function store() {
    return createStore({
        modules: {
            kioskCart: {
                namespaced: true,
                getters: { total: () => 7.4, upsellShown: () => false, items: () => [] },
                actions: { setLoyalty: vi.fn(), markUpsellShown: vi.fn() },
            },
            kioskMenu: { namespaced: true, getters: { categories: () => [] } },
            kioskSettings: { namespaced: true, state: () => ({ locale: 'fr' }) },
        },
    });
}

async function ecranSolde({ config, points }) {
    global.window.foodkingConfig = { discountsEnabled: true, kioskLoyaltyRedeemEnabled: true };
    if (config instanceof Error) {
        vi.spyOn(axios, 'get').mockRejectedValue(config);
    } else {
        vi.spyOn(axios, 'get').mockResolvedValue({ data: { data: config } });
    }
    // Réponse RÉELLE du serveur (LoyaltyController::check) : discount_value = points / taux,
    // même sous le seuil.
    vi.spyOn(axios, 'post').mockResolvedValue({ data: { data: {
        name: 'Jean Test', points, discount_value: points / 100, loyalty_code: 'ABC123',
    } } });
    const w = mount(KioskLoyaltyComponent, {
        global: {
            plugins: [store(), i18n],
            mocks: { $router: { push: vi.fn(), replace: vi.fn() }, $route: { query: {}, params: {} } },
            stubs: { KsConsentModal: true, KsVirtualKeyboard: true },
        },
    });
    await flushPromises();
    await w.setData({ code: 'ABC123' });
    await w.vm.checkLoyalty();
    await flushPromises();
    return w;
}

describe('borne — fidélité : le seuil et la valeur viennent du serveur (M5-101)', () => {
    afterEach(() => { vi.restoreAllMocks(); });

    it('925 points sous un seuil de 1000 : aucune « réduction » annoncée, le seuil serveur est dit', async () => {
        const w = await ecranSolde({ config: { min_redeem_points: 1000, points_for_1_euro_discount: 100 }, points: 925 });
        expect(w.find('.kiosk-loyalty-points-value').text()).toContain('925');
        expect(w.find('[data-testid="kiosk-loyalty-points-equiv"]').exists()).toBe(false);
        expect(w.find('[data-testid="kiosk-loyalty-redeem-options"]').exists()).toBe(false);
        expect(w.text()).toContain('1000');
    });

    it('au-dessus du seuil serveur : l’équivalence et le choix « utiliser » apparaissent', async () => {
        const w = await ecranSolde({ config: { min_redeem_points: 1000, points_for_1_euro_discount: 100 }, points: 1200 });
        expect(w.find('[data-testid="kiosk-loyalty-points-equiv"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-loyalty-redeem-options"]').exists()).toBe(true);
    });

    it('seuil illisible (réseau) : la borne n’invente pas « 100 » et ne promet rien', async () => {
        const w = await ecranSolde({ config: new Error('réseau'), points: 500 });
        expect(w.find('[data-testid="kiosk-loyalty-points-equiv"]').exists()).toBe(false);
        expect(w.find('[data-testid="kiosk-loyalty-redeem-options"]').exists()).toBe(false);
        expect(w.text()).not.toMatch(/au moins 100\b/);
        expect(w.find('.kiosk-loyalty-points-value').text()).toContain('500');
    });

    it('réponse de config sans seuil : pas de repli inventé non plus', async () => {
        const w = await ecranSolde({ config: { points_for_1_euro_discount: 100 }, points: 500 });
        expect(w.find('[data-testid="kiosk-loyalty-points-equiv"]').exists()).toBe(false);
        expect(w.find('[data-testid="kiosk-loyalty-redeem-options"]').exists()).toBe(false);
    });
});
