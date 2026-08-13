import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MenuComponent from '../../resources/js/components/admin/settings/MenuComponent.vue';
import BackendMenuComponent from '../../resources/js/components/layouts/backend/BackendMenuComponent.vue';

const RouterLink = {
    props: ['to'],
    template: '<a><slot /></a>',
};

describe('V1 admin sidebar cleanup', () => {
    it('does not render legacy settings entries in the settings menu', () => {
        const wrapper = mount(MenuComponent, {
            global: {
                stubs: { RouterLink },
                mocks: { $t: (key) => key },
            },
        });

        const text = wrapper.text();
        expect(text).not.toContain('menu.role_permissions');
        expect(text).not.toContain('menu.taxes');
        expect(text).not.toContain('menu.languages');
        expect(text).not.toContain('menu.theme');
        expect(text).not.toContain('menu.otp');
        expect(text).not.toContain('menu.notification_alert');
        expect(text).not.toContain('menu.social_media');
        expect(text).not.toContain('menu.cookies');
        expect(text).not.toContain('menu.analytics');
        expect(text).not.toContain('menu.time_slots');
        expect(text).not.toContain('menu.sliders');
        expect(text).not.toContain('menu.pages');
        expect(text).not.toContain('menu.sms_gateway');
        expect(text).not.toContain('menu.payment_gateway');
        expect(text).not.toContain('menu.license');
    });

    it('renders dashboard + POS + V1 primary band (virtual catalogue children under items)', () => {
        const wrapper = mount(BackendMenuComponent, {
            global: {
                stubs: { RouterLink },
                mocks: {
                    $route: { path: '/admin/items/studio' },
                    $t: (key) => key,
                    $store: {
                        getters: {
                            'frontendSetting/lists': { theme_logo: '/logo.png' },
                            authMenu: [],
                            'globalState/lists': { topSidebar: false },
                        },
                        dispatch: () => Promise.resolve(),
                    },
                },
            },
        });

        const text = wrapper.text();
        expect(text).toContain('menu.stock_rupture');
        expect(text).toContain('menu.catalog');
        expect(text).toContain('menu.ingredients');
        expect(text).toContain('menu.pos_orders');
        // buildMergedSidebarMenus: dashboard, pos, rupture, items→studio+attrs,
        // ingredients, pos-orders, cash-overview, delivery-cash-sessions = 9 rows
        // [GOAL-2026-05-29] +2 since this was written: cash-overview + delivery-cash-sessions.
        // [GOAL-CAISSE-UNIFIED 2026-05-30] +2: historique + encaissement (unified
        // history + collection surfaces) → 11 rows. Both gated on pos-orders perm.
        // [P3c 2026-07-24] +1 undocumented drift: purchasing/scan (Scan Facture) was
        // added to V1_PRIMARY_SIDEBAR_MENUS without bumping this count → 12 rows.
        // [PHASE 3d-UI 2026-07-24] +1: stock/unified (Conso & Stock, gated on the
        // items permission-url via the stock/ prefix) → 13 rows.
        // [TICKET PROMO 2026-08-07] +2 : promo-flyer (la file d'impression réclamée par
        // la caisse) et promo-flyer/settings → 15 rows. Ajoutées par `9b2f3a0a3` SANS
        // remonter ce compteur : la sentinelle a fait exactement son travail en rougissant
        // (détectée par la vérification globale du 07/08, pas par la session qui a livré).
        // Les deux entrées sont légitimes — la fonctionnalité est en production et un
        // ticket y a déjà été imprimé — donc on verrouille le nouveau plancher au lieu
        // de retirer les entrées.
        // [UBER-PHOTO 2026-08-10] +1 : « Commande Uber (Photo) » — l'écran tablette qui
        // photographie un ticket Uber et l'envoie en cuisine. Même porte que la caisse
        // (`pos-orders`), donc visible pour le même personnel → 16 rows. Le compteur est
        // remonté DANS la session qui livre l'entrée, pas découvert deux semaines plus tard.
        expect(text).toContain('menu.historique');
        expect(text).toContain('menu.encaissement');
        expect(text).toContain('menu.stock_unified');
        expect(text).toContain('menu.promo_flyer');
        // [ROUE 2026-08-13] +1 : « La roue ». Les cinq écrans du jeu existaient et
        // fonctionnaient, mais aucun lien n'y menait depuis le back-office — il fallait
        // taper les URL de mémoire. Même porte que la caisse (`pos-orders`), donc le
        // même personnel, → 17 rows.
        //
        // Cette entrée est la SEULE de la barre à porter `external: true` : elle mène à
        // une page Blade autonome, hors du routeur Vue. Le test ci-dessous le vérifie —
        // sans ce drapeau, l'entrée rendrait un `router-link` vers une route inexistante,
        // c'est-à-dire un lien mort dans le menu.
        //
        // Compteur remonté DANS la session qui livre l'entrée, pas découvert plus tard.
        expect(text).toContain('menu.uber_photo');
        expect(text).toContain('menu.roue');
        expect(wrapper.findAll('.db-sidebar-nav-menu')).toHaveLength(17);

        // La roue doit être une vraie ANCRE, ouverte dans un nouvel onglet — pas un
        // router-link. C'est ce qui distingue un lien qui marche d'un lien mort.
        const roue = wrapper.findAll('a.db-sidebar-nav-menu')
            .find((a) => a.attributes('href') === '/admin/roue');
        expect(roue, "l'entrée « La roue » n'est pas rendue comme une ancre : "
            + 'un router-link vers /admin/roue serait un lien mort').toBeTruthy();
        expect(roue.attributes('target')).toBe('_blank');
        expect(roue.attributes('rel')).toBe('noopener');
    });
});
