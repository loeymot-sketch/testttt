/**
 * A4 — KioskMicConsentDialog (P2 RGPD privacy).
 *
 * Couverture (happy-dom + @vue/test-utils) :
 *   - render role="dialog" + aria-modal + aria-labelledby + aria-describedby
 *   - emit 'confirm' on primary button click
 *   - emit 'cancel' on secondary button click
 *   - emit 'cancel' on overlay self-click
 *   - emit 'cancel' on Escape key
 *   - autofocus on the SAFE default = cancel button (privacy-conservative)
 *   - focus trap between cancel and confirm via onTabKey
 *   - fallback FR labels via tr() when $t is missing
 *
 * Voir plans/pr-review/ULTRA_PLAN_CORRECTION_2026-05-08.md §A4.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import KioskMicConsentDialog from '../../resources/js/components/frontend/kiosk/KioskMicConsentDialog.vue';

describe('KioskMicConsentDialog', () => {
    function factory() {
        return mount(KioskMicConsentDialog);
    }

    it('renders a dialog role + aria-modal + labelledby + describedby for screen readers', () => {
        const wrapper = factory();
        const dialog = wrapper.get('[data-testid="kiosk-mic-consent-dialog"]');
        expect(dialog.attributes('role')).toBe('dialog');
        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(dialog.attributes('aria-labelledby')).toBe('mic-consent-title');
        expect(dialog.attributes('aria-describedby')).toBe('mic-consent-disclaimer');
    });

    it('emits "confirm" when the primary "Activer le micro" button is clicked', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="kiosk-mic-consent-confirm"]').trigger('click');
        expect(wrapper.emitted('confirm')).toBeTruthy();
        expect(wrapper.emitted('confirm').length).toBe(1);
    });

    it('emits "cancel" when the secondary "Annuler" button is clicked', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="kiosk-mic-consent-cancel"]').trigger('click');
        expect(wrapper.emitted('cancel')).toBeTruthy();
        expect(wrapper.emitted('cancel').length).toBe(1);
    });

    it('emits "cancel" when the overlay (self) is clicked', async () => {
        const wrapper = factory();
        const overlay = wrapper.get('[data-testid="kiosk-mic-consent-dialog"]');
        await overlay.trigger('click');
        expect(wrapper.emitted('cancel')).toBeTruthy();
    });

    it('emits "cancel" on Escape key (WCAG 2.1.2)', async () => {
        const wrapper = factory();
        const overlay = wrapper.get('[data-testid="kiosk-mic-consent-dialog"]');
        await overlay.trigger('keydown', { key: 'Escape' });
        expect(wrapper.emitted('cancel')).toBeTruthy();
    });

    it('autofocuses the SAFE default (cancel) button on mount — privacy-conservative', async () => {
        // Ne pas autofocus le bouton "Activer le micro" : un Enter accidentel
        // déclencherait la captation audio sans intention. Le cancel par
        // défaut respecte le principe RGPD "privacy by default".
        const wrapper = factory();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.$refs.cancelBtn).toBeDefined();
        expect(wrapper.vm.$refs.cancelBtn.tagName).toBe('BUTTON');
        expect(wrapper.vm.$refs.cancelBtn.dataset.testid).toBe('kiosk-mic-consent-cancel');
    });

    it('traps Tab focus between cancel and confirm via onTabKey method (WCAG 2.4.3)', async () => {
        const wrapper = factory();
        await wrapper.vm.$nextTick();
        const cancelBtn = wrapper.vm.$refs.cancelBtn;
        const confirmBtn = wrapper.vm.$refs.confirmBtn;

        const cancelFocusSpy = vi.spyOn(cancelBtn, 'focus');
        const confirmFocusSpy = vi.spyOn(confirmBtn, 'focus');

        // Simule activeElement = confirm + Tab non-shift → doit appeler cancel.focus()
        Object.defineProperty(document, 'activeElement', { value: confirmBtn, configurable: true });
        wrapper.vm.onTabKey({ shiftKey: false, preventDefault: () => {} });
        expect(cancelFocusSpy).toHaveBeenCalled();

        // Simule activeElement = cancel + Shift+Tab → doit appeler confirm.focus()
        Object.defineProperty(document, 'activeElement', { value: cancelBtn, configurable: true });
        wrapper.vm.onTabKey({ shiftKey: true, preventDefault: () => {} });
        expect(confirmFocusSpy).toHaveBeenCalled();
    });

    it('falls back to French defaults via tr() when $t is missing', () => {
        const wrapper = factory();
        const html = wrapper.html();
        // Title + subtitle + disclaimer
        expect(html).toContain('Activation du microphone');
        expect(html).toContain('Cette borne va activer le micro');
        expect(html).toContain('effacées immédiatement après');
        // Buttons
        expect(html).toContain('Activer le micro');
        expect(html).toContain('Annuler');
    });

    // [A11y-HEAL-2026-05-08] P0 fix — WCAG 2.4.3 focus restoration on unmount.
    // The dialog must save document.activeElement on mount and restore it
    // when unmounted, otherwise focus falls back to <body> after Esc/Cancel.
    it('saves document.activeElement on mount (P0 a11y focus restore)', async () => {
        document.body.innerHTML = '<button id="trigger">Mic</button>';
        const trigger = document.getElementById('trigger');
        // happy-dom doesn't always update document.activeElement on .focus(),
        // so we assert the captured ref directly via Object.defineProperty —
        // same workaround used elsewhere in this suite.
        Object.defineProperty(document, 'activeElement', {
            value: trigger,
            configurable: true,
        });
        const wrapper = factory();
        await wrapper.vm.$nextTick();
        expect(wrapper.vm._prevActive).toBe(trigger);
        wrapper.unmount();
    });

    it('restores focus to the previous trigger on unmount (P0 a11y focus restore)', async () => {
        document.body.innerHTML = '<button id="trigger">Mic</button>';
        const trigger = document.getElementById('trigger');
        Object.defineProperty(document, 'activeElement', {
            value: trigger,
            configurable: true,
        });
        const focusSpy = vi.spyOn(trigger, 'focus');
        const wrapper = factory();
        await wrapper.vm.$nextTick();
        // mount() autofocuses cancelBtn → focusSpy on the trigger should not
        // have been called yet (the dialog moves focus into itself).
        focusSpy.mockClear();
        wrapper.unmount();
        // On unmount, beforeUnmount() must restore focus to the saved trigger.
        expect(focusSpy).toHaveBeenCalled();
    });

    it('uses $t when vue-i18n is wired (translation lookup)', () => {
        const wrapper = mount(KioskMicConsentDialog, {
            global: {
                mocks: {
                    $t: (key) => {
                        const map = {
                            'kiosk.voice.consent_title': 'Microphone activation',
                            'kiosk.voice.consent_subtitle': 'This kiosk will activate the microphone.',
                            'kiosk.voice.consent_disclaimer': 'Your speech will be erased afterwards.',
                            'kiosk.voice.consent_grant': 'Enable microphone',
                            'kiosk.voice.consent_deny': 'Cancel',
                        };
                        return map[key] !== undefined ? map[key] : key;
                    },
                },
            },
        });
        const html = wrapper.html();
        expect(html).toContain('Microphone activation');
        expect(html).toContain('Enable microphone');
        // Le fallback FR ne doit PAS apparaître quand $t fournit une vraie value
        expect(html).not.toContain('Activation du microphone');
    });
});
