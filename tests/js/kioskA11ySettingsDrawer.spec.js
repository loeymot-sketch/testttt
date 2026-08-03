/**
 * Kiosk Design V1 — Phase 4.3 : KsA11ySettings drawer
 *
 * Vérifie :
 *  - Rendu conditionnel (v-model)
 *  - A11y : role=dialog, aria-modal, aria-labelledby, radiogroups, switches
 *  - [ADR-007 / Sprint 3D 2026-05-16] AUCUN sélecteur de langue rendu :
 *      le drawer n'expose plus FR/EN/AR — kiosk runtime FR-immutable.
 *  - Sélection contraste AA/AAA dispatche kioskSettings/setContrast
 *  - Toggle PMR/Audio dispatche + met à jour aria-checked du switch
 *  - Bouton "done" émet update:modelValue=false
 *  - Bouton "reset" dispatche kioskSettings/reset (la locale du store reste 'fr'
 *    sans passer par un dispatch UI setLocale)
 *  - data-testid stables pour E2E
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KsA11ySettings from '../../resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue';
import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import frMessages from '../../resources/js/languages/fr.json';

function makeI18n() {
    return createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } });
}

function makeStore() {
    return createStore({ modules: { kioskSettings } });
}

function mountDrawer(props = { modelValue: true }) {
    const store = makeStore();
    const w = mount(KsA11ySettings, {
        props,
        global: { plugins: [store, makeI18n()], mocks: { window: { axios: null } } },
    });
    return { w, store };
}

describe('KsA11ySettings drawer', () => {
    it('ne rend rien si modelValue=false', () => {
        const { w } = mountDrawer({ modelValue: false });
        expect(w.find('[data-testid="kiosk-a11y-drawer"]').exists()).toBe(false);
    });

    it('rend le drawer + role=dialog + aria-modal', () => {
        const { w } = mountDrawer();
        const drawer = w.find('[data-testid="kiosk-a11y-drawer"]');
        expect(drawer.exists()).toBe(true);
        expect(drawer.attributes('role')).toBe('dialog');
        expect(drawer.attributes('aria-modal')).toBe('true');
        expect(drawer.attributes('aria-labelledby')).toBeTruthy();
    });

    // [ADR-007 / Sprint 3D 2026-05-16] FR-lock restore — le drawer ne propose
    // plus de sélecteur FR/EN/AR. Aucun radiogroup `kiosk-a11y-lang-*` ne doit
    // être rendu, et aucun bouton de switch locale ne doit exister.
    it('[ADR-007] AUCUN radiogroup de langue rendu (FR-lock kiosk immutable)', () => {
        const { w } = mountDrawer();
        // Le wrapper "lang-group" ne doit plus exister
        expect(w.find('[data-testid="kiosk-a11y-lang-group"]').exists()).toBe(false);
        // Aucun bouton lang-fr / lang-en / lang-ar
        ['fr', 'en', 'ar'].forEach((code) => {
            expect(w.find(`[data-testid="kiosk-a11y-lang-${code}"]`).exists()).toBe(false);
        });
        // Heuristique défensive : aucun élément data-testid commençant par
        // "kiosk-a11y-lang-" (au cas où un futur dev réintroduirait un id).
        const langCandidates = w.findAll('[data-testid^="kiosk-a11y-lang-"]');
        expect(langCandidates.length).toBe(0);
    });

    it('[ADR-007] aucun click ne dispatche setLocale depuis le drawer', async () => {
        const { w, store } = mountDrawer();
        // Cliquer sur tous les boutons exposés du drawer ne doit jamais muter
        // `kioskSettings.locale` (defense-in-depth : si un futur radio fr/en/ar
        // était rendu par erreur, ce test l'attraperait dès le 1er click).
        const buttons = w.findAll('button');
        for (const btn of buttons) {
            await btn.trigger('click');
        }
        expect(store.state.kioskSettings.locale).toBe('fr');
    });

    it('changer le contraste dispatche setContrast', async () => {
        const { w, store } = mountDrawer();
        await w.find('[data-testid="kiosk-a11y-contrast-aaa"]').trigger('click');
        expect(store.state.kioskSettings.contrast).toBe('aaa');
        expect(w.find('[data-testid="kiosk-a11y-contrast-aaa"]').attributes('aria-checked')).toBe('true');
    });

    it('toggle PMR inverse la valeur + aria-checked switch', async () => {
        const { w, store } = mountDrawer();
        const sw = w.find('[data-testid="kiosk-a11y-pmr-toggle"]');
        expect(sw.attributes('role')).toBe('switch');
        expect(sw.attributes('aria-checked')).toBe('false');
        await sw.trigger('click');
        expect(store.state.kioskSettings.pmr).toBe(true);
        expect(w.find('[data-testid="kiosk-a11y-pmr-toggle"]').attributes('aria-checked')).toBe('true');
    });

    it('toggle Audio inverse la valeur', async () => {
        const { w, store } = mountDrawer();
        await w.find('[data-testid="kiosk-a11y-audio-toggle"]').trigger('click');
        expect(store.state.kioskSettings.audio).toBe(true);
    });

    it('bouton Done émet update:modelValue=false', async () => {
        const { w } = mountDrawer();
        await w.find('[data-testid="kiosk-a11y-done"]').trigger('click');
        expect(w.emitted('update:modelValue')).toBeTruthy();
        expect(w.emitted('update:modelValue')[0][0]).toBe(false);
    });

    it('bouton Reset dispatche reset (restaure les defaults)', async () => {
        const { w, store } = mountDrawer();
        // [ADR-007] Pas de dispatch UI `setLocale` ici — on mute directement
        // via setContrast/setPmr (chemins UI restants), et reset doit ramener
        // la locale à 'fr' (déjà la valeur par défaut, vérifié par invariant).
        await store.dispatch('kioskSettings/setContrast', 'aaa');
        await store.dispatch('kioskSettings/setPmr', true);
        await w.find('[data-testid="kiosk-a11y-reset"]').trigger('click');
        expect(store.state.kioskSettings.locale).toBe('fr');
        expect(store.state.kioskSettings.contrast).toBe('aa');
        expect(store.state.kioskSettings.pmr).toBe(false);
    });
});
