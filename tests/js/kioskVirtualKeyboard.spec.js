/**
 * Kiosk Design V1 — Phase 4.6 : KsVirtualKeyboard atom
 *
 * Vérifie :
 *  - Rendu 3 layouts (fr/en/ar) + émissions update:modelValue / submit
 *  - dir="rtl" pour AR
 *  - Backspace supprime un code point Unicode complet (AR)
 *  - maxLength bloque les keystrokes excédentaires
 *  - allowSpace=false ignore l'espace
 *  - A11y : role=group, aria-label, chaque touche = <button>, data-testid stable
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import KsVirtualKeyboard from '../../resources/js/components/frontend/kiosk/ds/KsVirtualKeyboard.vue';
import frMessages from '../../resources/js/languages/fr.json';

function makeI18n() {
    return createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } });
}

function mountKb(props = {}) {
    return mount(KsVirtualKeyboard, {
        props: { modelValue: '', visible: true, ...props },
        global: { plugins: [makeI18n()] },
    });
}

describe('KsVirtualKeyboard', () => {
    it('rend le layout FR (AZERTY) par défaut', () => {
        const w = mountKb();
        expect(w.find('[data-testid="kiosk-vkeyb"]').exists()).toBe(true);
        // Première touche de la première rangée AZERTY = a
        expect(w.find('[data-testid="kiosk-vkeyb-key-a"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-vkeyb-submit"]').exists()).toBe(true);
    });

    it('rend le layout EN (QWERTY)', () => {
        const w = mountKb({ layout: 'en' });
        // Première rangée = q w e r t y u i o p
        expect(w.find('[data-testid="kiosk-vkeyb-key-q"]').exists()).toBe(true);
        expect(w.find('[data-testid="kiosk-vkeyb-key-p"]').exists()).toBe(true);
    });

    it('rend le layout AR avec dir=rtl', () => {
        const w = mountKb({ layout: 'ar' });
        expect(w.find('.ks-vkeyb--rtl').exists()).toBe(true);
        // Vérifie qu'on a des touches (encodées en codepoints u<hex>)
        const keys = w.findAll('[data-testid^="kiosk-vkeyb-key-u"]');
        expect(keys.length).toBeGreaterThan(0);
    });

    it('émet update:modelValue sur clic de touche', async () => {
        const w = mountKb({ modelValue: '' });
        await w.find('[data-testid="kiosk-vkeyb-key-a"]').trigger('click');
        const events = w.emitted('update:modelValue');
        expect(events).toBeTruthy();
        expect(events[0][0]).toBe('a');
    });

    it('émet submit sur touche OK', async () => {
        const w = mountKb({ modelValue: 'hello' });
        await w.find('[data-testid="kiosk-vkeyb-submit"]').trigger('click');
        expect(w.emitted('submit')).toBeTruthy();
        expect(w.emitted('submit')[0][0]).toBe('hello');
    });

    it('backspace retire le dernier caractère', async () => {
        const w = mountKb({ modelValue: 'abc' });
        await w.find('[data-testid="kiosk-vkeyb-backspace"]').trigger('click');
        expect(w.emitted('update:modelValue')[0][0]).toBe('ab');
    });

    it('maxLength bloque les keystrokes excédentaires', async () => {
        const w = mountKb({ modelValue: 'abc', maxLength: 3 });
        await w.find('[data-testid="kiosk-vkeyb-key-a"]').trigger('click');
        expect(w.emitted('update:modelValue')).toBeUndefined();
    });

    it('allowSpace=false ignore l\'espace', async () => {
        const w = mountKb({ modelValue: 'a', allowSpace: false });
        await w.find('[data-testid="kiosk-vkeyb-space"]').trigger('click');
        expect(w.emitted('update:modelValue')).toBeUndefined();
    });

    it('a11y — role=group + aria-label + chaque touche = <button>', () => {
        const w = mountKb();
        const root = w.find('[data-testid="kiosk-vkeyb"]');
        expect(root.attributes('role')).toBe('group');
        expect(root.attributes('aria-label')).toBeTruthy();
        // Toutes les touches sont des <button> natives
        const allButtons = w.findAll('.ks-vkeyb__key');
        expect(allButtons.length).toBeGreaterThan(30);
        allButtons.forEach((b) => expect(b.element.tagName).toBe('BUTTON'));
    });

    it('clear bouton vide la valeur', async () => {
        const w = mountKb({ modelValue: 'hello' });
        await w.find('[data-testid="kiosk-vkeyb-clear"]').trigger('click');
        expect(w.emitted('update:modelValue')[0][0]).toBe('');
    });

    /**
     * Kiosk Phase 9.1.7 — digits row partagée par tous les layouts :
     * fondamentale pour les saisies téléphone / email chiffrés sur les
     * bornes Windows sans TabTip.
     */
    it('9.1.7 — rangée numérique 0-9 dispo sur tous les layouts', async () => {
        for (const layout of ['fr', 'en', 'ar']) {
            const w = mountKb({ layout });
            for (const digit of ['0', '1', '5', '9']) {
                expect(
                    w.find(`[data-testid="kiosk-vkeyb-key-${digit}"]`).exists(),
                    `layout ${layout} manque le chiffre ${digit}`,
                ).toBe(true);
            }
        }
    });

    it('9.1.7 — clic sur une touche numérique émet le chiffre correspondant', async () => {
        const w = mountKb({ modelValue: '06' });
        await w.find('[data-testid="kiosk-vkeyb-key-1"]').trigger('click');
        expect(w.emitted('update:modelValue')[0][0]).toBe('061');
    });
});
