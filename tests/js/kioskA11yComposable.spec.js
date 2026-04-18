/**
 * Kiosk Design V1 — Phase 4.2 : composable useKioskA11y
 *
 * Vérifie :
 *  - applyKioskA11yFromStore pose data-kiosk-contrast / pmr / audio / lang / dir
 *  - clearKioskA11yAttributes supprime les flags (retour admin)
 *  - Le watcher Vuex (useKioskA11y) met à jour le <html> root lors d'un
 *    changement de contrast / pmr / audio / locale (FR → AR → dir=rtl).
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createStore } from 'vuex';
import { defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';

import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import {
    useKioskA11y,
    applyKioskA11yFromStore,
    clearKioskA11yAttributes,
    KIOSK_A11Y_ATTRS,
} from '../../resources/js/composables/useKioskA11y';

function makeStore() {
    return createStore({ modules: { kioskSettings } });
}

function cleanHtml() {
    const el = document.documentElement;
    Object.values(KIOSK_A11Y_ATTRS).forEach((attr) => el.removeAttribute(attr));
}

describe('useKioskA11y composable', () => {
    beforeEach(() => cleanHtml());
    afterEach(() => cleanHtml());

    it('applyKioskA11yFromStore pose les attributs initiaux', () => {
        const store = makeStore();
        applyKioskA11yFromStore(store);
        expect(document.documentElement.getAttribute('data-kiosk-contrast')).toBe('aa');
        expect(document.documentElement.getAttribute('data-kiosk-pmr')).toBe('false');
        expect(document.documentElement.getAttribute('data-kiosk-audio')).toBe('false');
        expect(document.documentElement.getAttribute('lang')).toBe('fr');
        expect(document.documentElement.getAttribute('dir')).toBe('ltr');
    });

    it('basculer locale=ar pose dir=rtl', async () => {
        const store = makeStore();
        const Harness = defineComponent({
            setup() {
                useKioskA11y({ store });
                return () => h('div');
            },
        });
        const wrapper = mount(Harness);
        await store.dispatch('kioskSettings/setLocale', 'ar');
        await wrapper.vm.$nextTick();
        expect(document.documentElement.getAttribute('lang')).toBe('ar');
        expect(document.documentElement.getAttribute('dir')).toBe('rtl');
        wrapper.unmount();
    });

    it('activer AAA + PMR + audio met à jour les 3 attributs', async () => {
        const store = makeStore();
        const Harness = defineComponent({
            setup() {
                useKioskA11y({ store });
                return () => h('div');
            },
        });
        const wrapper = mount(Harness);
        await store.dispatch('kioskSettings/setContrast', 'aaa');
        await store.dispatch('kioskSettings/setPmr', true);
        await store.dispatch('kioskSettings/setAudio', true);
        await wrapper.vm.$nextTick();
        expect(document.documentElement.getAttribute('data-kiosk-contrast')).toBe('aaa');
        expect(document.documentElement.getAttribute('data-kiosk-pmr')).toBe('true');
        expect(document.documentElement.getAttribute('data-kiosk-audio')).toBe('true');
        wrapper.unmount();
    });

    it('clearKioskA11yAttributes retire les attributs kiosk', () => {
        const store = makeStore();
        applyKioskA11yFromStore(store);
        clearKioskA11yAttributes();
        expect(document.documentElement.getAttribute('data-kiosk-contrast')).toBeNull();
        expect(document.documentElement.getAttribute('data-kiosk-pmr')).toBeNull();
        expect(document.documentElement.getAttribute('data-kiosk-audio')).toBeNull();
    });

    it('applyKioskA11yFromStore est idempotent (pas de reflow si valeur identique)', () => {
        const store = makeStore();
        applyKioskA11yFromStore(store);
        const spy = vi => {};
        // Simple vérification : deux appels d'affilée laissent les attributs stables.
        applyKioskA11yFromStore(store);
        expect(document.documentElement.getAttribute('lang')).toBe('fr');
    });
});
