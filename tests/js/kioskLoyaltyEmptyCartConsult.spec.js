/**
 * [W-REM T-R3.1b F-BV-04 2026-06-12] « Mon compte » panier vide → PANIER VIDE
 * sans explication.
 * -----------------------------------------------------------------------------
 * Constat audit borne : le chip « Mon compte » (KioskCategoriesComponent)
 * route vers kiosk.loyalty ; le guard requireCart redirigeait vers
 * kiosk.cart dès que le panier était vide → le client atterrissait sur
 * l'écran « PANIER VIDE » sans AUCUN rapport avec son intention (consulter
 * son compte fidélité), sans message.
 *
 * Invariants verrouillés :
 *  1. kiosk.loyalty est accessible panier vide (mode consultation compte).
 *  2. En mode consultation (panier vide), les sorties de l'écran fidélité
 *     ramènent au catalogue (kiosk.categories) — JAMAIS au panier vide :
 *     goBack() et proceedToPayment() sont contextuels.
 *  3. Les options de rachat « -X € sur cette commande » sont masquées panier
 *     vide (pas de commande → pas de rachat), le bouton Confirmer n'est pas
 *     gelé par l'absence de redeemChoice.
 *  4. CTA final contextuel : clé i18n continue_menu présente en FR.
 */
import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import KioskLoyaltyComponent from '../../resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue';

vi.mock('../../resources/js/store/index.js', () => ({
    default: {
        state: { kioskCart: { kioskToken: 'token', orderRef: null } },
        getters: {
            // Panier VIDE — le cas F-BV-04.
            'kioskCart/isEmpty': true,
            'kioskFilter/hydrated': true,
        },
        dispatch: vi.fn().mockResolvedValue(),
    },
}));

import kioskRoutes from '../../resources/js/router/modules/kioskRoutes.js';

const componentSrc = readFileSync(
    resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue'),
    'utf-8',
);

function findRouteByName(routes, name) {
    for (const route of routes) {
        if (route.name === name) return route;
        if (Array.isArray(route.children)) {
            const child = findRouteByName(route.children, name);
            if (child) return child;
        }
    }
    return null;
}

describe('[F-BV-04] kiosk.loyalty accessible panier vide (consultation compte)', () => {
    it('le guard de kiosk.loyalty ne redirige PLUS vers kiosk.cart quand le panier est vide', () => {
        const route = findRouteByName(kioskRoutes, 'kiosk.loyalty');
        expect(route).toBeTruthy();
        if (!route.beforeEnter) {
            // Pas de guard du tout = accès libre, invariant satisfait.
            expect(route.beforeEnter).toBeUndefined();
            return;
        }
        const next = vi.fn();
        route.beforeEnter({ name: 'kiosk.loyalty' }, { name: 'kiosk.categories' }, next);
        expect(next).toHaveBeenCalledTimes(1);
        // next() sans argument = navigation autorisée ; next({name:'kiosk.cart'}) = bug F-BV-04.
        expect(next.mock.calls[0][0]).toBeUndefined();
    });

    it('upsell et payment restent protégés par requireCart (redirection panier)', () => {
        for (const name of ['kiosk.upsell', 'kiosk.payment']) {
            const route = findRouteByName(kioskRoutes, name);
            expect(route).toBeTruthy();
            const next = vi.fn();
            route.beforeEnter({ name }, { name: 'kiosk.cart' }, next);
            expect(next).toHaveBeenCalledWith({ name: 'kiosk.cart' });
        }
    });
});

describe('[F-BV-04] sorties contextuelles de KioskLoyaltyComponent panier vide', () => {
    function makeVm({ cartIsEmpty }) {
        const pushed = [];
        const vm = {
            cartIsEmpty,
            upsellShown: false,
            shouldSkipKioskUpsell: false,
            markUpsellShown: vi.fn(),
            $router: { push: (arg) => pushed.push(arg) },
        };
        vm.goBack = KioskLoyaltyComponent.methods.goBack.bind(vm);
        vm.proceedToPayment = KioskLoyaltyComponent.methods.proceedToPayment.bind(vm);
        return { vm, pushed };
    }

    it('goBack panier vide → catalogue (kiosk.categories), jamais le panier vide', () => {
        const { vm, pushed } = makeVm({ cartIsEmpty: true });
        vm.goBack();
        expect(pushed).toEqual([{ name: 'kiosk.categories' }]);
    });

    it('goBack panier rempli → panier (comportement checkout inchangé)', () => {
        const { vm, pushed } = makeVm({ cartIsEmpty: false });
        vm.goBack();
        expect(pushed).toEqual([{ name: 'kiosk.cart' }]);
    });

    it('proceedToPayment panier vide → catalogue (choisir ses articles), pas payment', () => {
        const { vm, pushed } = makeVm({ cartIsEmpty: true });
        vm.proceedToPayment();
        expect(pushed).toEqual([{ name: 'kiosk.categories' }]);
    });

    it('proceedToPayment panier rempli → upsell (flux checkout inchangé)', () => {
        const { vm, pushed } = makeVm({ cartIsEmpty: false });
        vm.proceedToPayment();
        expect(pushed).toEqual([{ name: 'kiosk.upsell' }]);
    });

    it('computed cartIsEmpty existe (items du store)', () => {
        expect(typeof KioskLoyaltyComponent.computed?.cartIsEmpty).toBe('function');
        const empty = KioskLoyaltyComponent.computed.cartIsEmpty.call({ items: [] });
        const filled = KioskLoyaltyComponent.computed.cartIsEmpty.call({ items: [{ id: 1 }] });
        expect(empty).toBe(true);
        expect(filled).toBe(false);
    });
});

describe('[F-BV-04] template — rachat masqué + CTA contextuel panier vide', () => {
    it('les options de rachat sont conditionnées à un panier non vide', () => {
        expect(componentSrc).toMatch(/v-if="canRedeem && !cartIsEmpty"/);
    });

    it('le bouton Confirmer n\'est pas gelé panier vide (disabled tient compte de cartIsEmpty)', () => {
        expect(componentSrc).toMatch(/:disabled="canRedeem && !cartIsEmpty && !redeemChoice"/);
    });

    it('CTA final contextuel : continue_menu rendu quand cartIsEmpty', () => {
        expect(componentSrc).toMatch(/cartIsEmpty[\s\S]{0,120}continue_menu|continue_menu[\s\S]{0,120}cartIsEmpty/);
    });

    it('clé FR kiosk.loyalty_screen.continue_menu présente', () => {
        const fr = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf-8'));
        expect(typeof fr.kiosk.loyalty_screen.continue_menu).toBe('string');
        expect(fr.kiosk.loyalty_screen.continue_menu.length).toBeGreaterThan(3);
    });
});
