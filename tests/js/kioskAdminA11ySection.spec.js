/**
 * Kiosk Design V1 — Phase V1x-5 : Admin a11y staff section
 *
 * Vérifie :
 *  - Les 3 toggles a11y (AAA / PMR / reducedMotion) sont rendus dans le panel
 *    une fois le PIN validé.
 *  - Toggle AAA dispatche `kioskSettings/setContrast` ('aa' ↔ 'aaa')
 *  - Toggle PMR dispatche `kioskSettings/setPmr`
 *  - Toggle reducedMotion dispatche `kioskSettings/setReducedMotion`
 *  - Le bouton reset remet les 3 prefs à leurs valeurs par défaut
 *  - SSOT partagée avec KsA11ySettings drawer (mêmes mutations Vuex)
 *  - data-testid stables pour E2E
 *
 * NB : ne teste PAS la propagation aux attributs <html> — c'est le rôle de
 * useKioskA11y composable, déjà couvert par tests/js/kioskA11yComposable.spec.js.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskAdminComponent from '../../resources/js/components/frontend/kiosk/KioskAdminComponent.vue';
import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import frMessages from '../../resources/js/languages/fr.json';

function makeStore() {
    return createStore({
        modules: {
            kioskSettings,
            // Stubs pour les autres modules dont KioskAdminComponent dépend
            // (kioskCart.reset, kioskMenu.fetchMenu, globalState.lists).
            kioskCart: {
                namespaced: true,
                state: () => ({ branchId: 1 }),
                actions: { reset() { /* noop */ } },
            },
            kioskMenu: {
                namespaced: true,
                state: () => ({ lastFetchedAt: null }),
                getters: { allItems: () => [] },
                actions: { fetchMenu() { /* noop */ } },
            },
            globalState: {
                state: () => ({ lists: { kiosk_admin_pin: '1234' } }),
            },
        },
    });
}

function makeI18n() {
    return createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } });
}

function mountAdmin() {
    const store = makeStore();
    const router = { push: () => Promise.resolve() };
    const w = mount(KioskAdminComponent, {
        global: {
            plugins: [store, makeI18n()],
            mocks: { $router: router, window: { axios: null } },
            stubs: {
                // KsButton est globalement enregistré en prod (app.use(KioskDesignSystem)).
                // Stub minimal ici suffit — on ne teste pas le rendu visuel.
            },
        },
    });
    return { w, store };
}

async function unlockPin(w) {
    // PIN 1234 — clic sur 4 boutons puis le check est automatique
    const numpad = w.findAll('.kiosk-pin-key');
    // Le numpad rend [1,2,3,4,5,6,7,8,9,'',0,'⌫'] — index 0..3 = 1,2,3,4
    await numpad[0].trigger('click'); // 1
    await numpad[1].trigger('click'); // 2
    await numpad[2].trigger('click'); // 3
    await numpad[3].trigger('click'); // 4
    await flushPromises();
}

describe('KioskAdminComponent — a11y staff section [V1x-5]', () => {
    let ctx;

    beforeEach(async () => {
        ctx = mountAdmin();
        await unlockPin(ctx.w);
    });

    it('rend la section a11y avec les 3 toggles + bouton reset', () => {
        const section = ctx.w.find('[data-testid="kiosk-admin-a11y-section"]');
        expect(section.exists()).toBe(true);
        expect(ctx.w.find('[data-testid="kiosk-admin-a11y-aaa"]').exists()).toBe(true);
        expect(ctx.w.find('[data-testid="kiosk-admin-a11y-pmr"]').exists()).toBe(true);
        expect(ctx.w.find('[data-testid="kiosk-admin-a11y-reduced-motion"]').exists()).toBe(true);
        expect(ctx.w.find('[data-testid="kiosk-admin-a11y-reset"]').exists()).toBe(true);
    });

    it('toggle AAA dispatche kioskSettings/setContrast=aaa', async () => {
        const checkbox = ctx.w.find('[data-testid="kiosk-admin-a11y-aaa"]');
        expect(ctx.store.state.kioskSettings.contrast).toBe('aa');

        // Simulate user check
        await checkbox.setValue(true);
        await flushPromises();

        expect(ctx.store.state.kioskSettings.contrast).toBe('aaa');
    });

    it('toggle AAA off → revient à aa', async () => {
        await ctx.store.dispatch('kioskSettings/setContrast', 'aaa');
        ctx.w.vm.a11yDraft.contrast = 'aaa';
        await ctx.w.vm.$nextTick();

        const checkbox = ctx.w.find('[data-testid="kiosk-admin-a11y-aaa"]');
        await checkbox.setValue(false);
        await flushPromises();

        expect(ctx.store.state.kioskSettings.contrast).toBe('aa');
    });

    it('toggle PMR dispatche kioskSettings/setPmr=true', async () => {
        const checkbox = ctx.w.find('[data-testid="kiosk-admin-a11y-pmr"]');
        expect(ctx.store.state.kioskSettings.pmr).toBe(false);

        await checkbox.setValue(true);
        await flushPromises();

        expect(ctx.store.state.kioskSettings.pmr).toBe(true);
    });

    it('toggle reducedMotion dispatche kioskSettings/setReducedMotion=true', async () => {
        const checkbox = ctx.w.find('[data-testid="kiosk-admin-a11y-reduced-motion"]');
        expect(ctx.store.state.kioskSettings.reducedMotion).toBe(false);

        await checkbox.setValue(true);
        await flushPromises();

        expect(ctx.store.state.kioskSettings.reducedMotion).toBe(true);
    });

    it('reset remet les 3 prefs aux valeurs par défaut (aa / false / false)', async () => {
        await ctx.store.dispatch('kioskSettings/setContrast', 'aaa');
        await ctx.store.dispatch('kioskSettings/setPmr', true);
        await ctx.store.dispatch('kioskSettings/setReducedMotion', true);
        await flushPromises();

        const resetBtn = ctx.w.find('[data-testid="kiosk-admin-a11y-reset"]');
        await resetBtn.trigger('click');
        await flushPromises();

        expect(ctx.store.state.kioskSettings.contrast).toBe('aa');
        expect(ctx.store.state.kioskSettings.pmr).toBe(false);
        expect(ctx.store.state.kioskSettings.reducedMotion).toBe(false);
    });

    it('reset NE touche PAS au consent ni à la locale (SSOT respectée)', async () => {
        await ctx.store.dispatch('kioskSettings/setLocale', 'en');
        await ctx.store.dispatch('kioskSettings/setConsentLoyalty', true);
        await ctx.store.dispatch('kioskSettings/setContrast', 'aaa');
        await flushPromises();

        const resetBtn = ctx.w.find('[data-testid="kiosk-admin-a11y-reset"]');
        await resetBtn.trigger('click');
        await flushPromises();

        // a11y reset
        expect(ctx.store.state.kioskSettings.contrast).toBe('aa');
        // intacts
        expect(ctx.store.state.kioskSettings.locale).toBe('en');
        expect(ctx.store.state.kioskSettings.consentLoyalty).toBe(true);
    });

    it('initialise a11yDraft depuis le store à l\'unlock du PIN', () => {
        // À l'unlock, _initEditableState a copié les prefs du store
        expect(ctx.w.vm.a11yDraft).toEqual({
            contrast: 'aa',
            pmr: false,
            reducedMotion: false,
        });
    });
});
