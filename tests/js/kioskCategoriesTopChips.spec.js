/**
 * Kiosk Phase 9.1.13 — UI morte retirée / wirée.
 *
 * Finding P0 : Les chips "Mon compte" et "Allergènes" dans
 *   `KioskCategoriesComponent.vue` étaient rendus en `disabled` permanent,
 *   sans aucune action attachée — UX placebo qui trompe le client en lui
 *   laissant croire qu'un parcours loyalty / allergènes existe.
 *
 * Fix attendu (vérifié par ce spec) :
 *   1. Le chip "Mon compte" existe, n'est PAS disabled, et un clic déclenche
 *      `$router.push({ name: 'kiosk.loyalty' })`.
 *   2. Le chip "Allergènes" est retiré du DOM (il sera ré-introduit en
 *      Phase 9.5 quand la surface préférences alimentaires existera).
 *   3. Aucune régression sur `kiosk-categories-root` / autres testids.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskCategoriesComponent from '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

function makeStore() {
    return createStore({
        modules: {
            kioskMenu: {
                namespaced: true,
                state: () => ({ kioskSandwichSubcolumn: null }),
                getters: {
                    categories: () => [{ id: 1, name: 'Tacos', kioskRowKey: 't' }],
                    allItems: () => [],
                    selectedCategoryId: () => null,
                    loading: () => false,
                    isStale: () => false,
                    fromCache: () => false,
                    sidebarCategories: () => [{ id: 1, name: 'Tacos', kioskRowKey: 't' }],
                    kioskCatalogItems: () => [],
                },
                actions: {
                    fetchMenu: vi.fn().mockResolvedValue(),
                    selectKioskCategory: vi.fn(),
                },
            },
            kioskCart: {
                namespaced: true,
                state: () => ({ branchId: 1, kioskToken: 'test-token' }),
                getters: { count: () => 0, total: () => 0, isEmpty: () => true },
                actions: { addItem: vi.fn(), reset: vi.fn() },
            },
            frontendItem: {
                namespaced: true,
                actions: { details: vi.fn().mockResolvedValue({ data: { data: {} } }) },
            },
        },
    });
}

function mkI18n() {
    return createI18n({
        legacy: false,
        locale: 'fr',
        fallbackLocale: 'fr',
        messages: { fr: frMessages },
    });
}

function mountWith($router) {
    return mount(KioskCategoriesComponent, {
        global: {
            plugins: [makeStore(), mkI18n()],
            mocks: {
                $router,
                $route: { query: {}, params: {} },
            },
            stubs: { KioskWizardComponent: true, transition: false },
        },
    });
}

beforeEach(() => {
    global.window = global.window || {};
});

describe('Kiosk Phase 9.1.13 — chips header catalogue', () => {
    it('le chip "Mon compte" est interactif (non disabled)', () => {
        const wrapper = mountWith({ push: vi.fn(), replace: vi.fn() });
        const account = wrapper.find('[data-testid="kiosk-categories-top-account"]');
        expect(account.exists()).toBe(true);
        expect(account.attributes('disabled')).toBeFalsy();
    });

    it('un clic sur "Mon compte" route vers kiosk.loyalty', async () => {
        const push = vi.fn();
        const wrapper = mountWith({ push, replace: vi.fn() });
        await wrapper.find('[data-testid="kiosk-categories-top-account"]').trigger('click');
        expect(push).toHaveBeenCalledTimes(1);
        expect(push).toHaveBeenCalledWith({ name: 'kiosk.loyalty' });
    });

    it('le chip "Allergènes" a été retiré (pas de destination valide en P9.1)', () => {
        const wrapper = mountWith({ push: vi.fn(), replace: vi.fn() });
        expect(wrapper.find('[data-testid="kiosk-categories-top-allergens"]').exists()).toBe(false);
    });

    it('ne plante pas si $router.push jette (environnement tests sans guard)', async () => {
        const push = vi.fn(() => { throw new Error('no guard'); });
        const wrapper = mountWith({ push, replace: vi.fn() });
        await expect(
            wrapper.find('[data-testid="kiosk-categories-top-account"]').trigger('click')
        ).resolves.not.toThrow();
    });
});
