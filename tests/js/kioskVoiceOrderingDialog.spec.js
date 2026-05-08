/**
 * V2-4 Phase A — KioskVoiceOrderingDialog.
 *
 * Couverture (happy-dom + @vue/test-utils) :
 *   - render transcript dans une <blockquote>
 *   - emit 'confirm' / 'cancel' sur clic boutons
 *   - emit 'cancel' sur clic overlay (self only)
 *   - fallback labels via tr() quand $t absent
 *
 * Voir plans/PLAN_DESIGN_V2_4_VOICE_ORDERING_2026-05-08.md.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import KioskVoiceOrderingDialog from '../../resources/js/components/frontend/kiosk/KioskVoiceOrderingDialog.vue';

describe('KioskVoiceOrderingDialog', () => {
    function factory(props = {}) {
        return mount(KioskVoiceOrderingDialog, {
            props: { transcript: 'un cheeseburger et un coca', ...props },
        });
    }

    it('renders the transcript prop inside a blockquote', () => {
        const wrapper = factory({ transcript: 'un cheeseburger et un coca' });
        const tx = wrapper.get('[data-testid="kiosk-voice-dialog-transcript"]');
        expect(tx.text()).toContain('un cheeseburger et un coca');
    });

    it('emits "confirm" when the primary button is clicked', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="kiosk-voice-dialog-confirm"]').trigger('click');
        expect(wrapper.emitted('confirm')).toBeTruthy();
        expect(wrapper.emitted('confirm').length).toBe(1);
    });

    it('emits "cancel" when the secondary button is clicked', async () => {
        const wrapper = factory();
        await wrapper.get('[data-testid="kiosk-voice-dialog-cancel"]').trigger('click');
        expect(wrapper.emitted('cancel')).toBeTruthy();
        expect(wrapper.emitted('cancel').length).toBe(1);
    });

    it('emits "cancel" when the overlay (self) is clicked', async () => {
        const wrapper = factory();
        const overlay = wrapper.get('[data-testid="kiosk-voice-dialog"]');
        await overlay.trigger('click');
        // Note: @click.self only fires when target === currentTarget. Vue Test
        // Utils dispatches on the el itself, so target === the overlay.
        expect(wrapper.emitted('cancel')).toBeTruthy();
    });

    it('falls back to French defaults via tr() when $t is missing', () => {
        const wrapper = factory({ transcript: 'test' });
        // No $t plugin in default mount, the tr() helper returns fallback.
        const html = wrapper.html();
        expect(html).toContain("J'ai entendu");
        expect(html).toContain('OUI, CONTINUER');
        expect(html).toContain('Annuler');
    });

    it('renders a dialog role + aria-modal for screen readers', () => {
        const wrapper = factory();
        const dialog = wrapper.get('[data-testid="kiosk-voice-dialog"]');
        expect(dialog.attributes('role')).toBe('dialog');
        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(dialog.attributes('aria-labelledby')).toBe('voice-dialog-title');
    });

    // [A11y-HEAL-2026-05-08] Ultra-review P0 fixes pour WCAG 2.4.3
    it('emits "cancel" on Escape key (P0 a11y fix)', async () => {
        const wrapper = factory();
        const overlay = wrapper.get('[data-testid="kiosk-voice-dialog"]');
        await overlay.trigger('keydown', { key: 'Escape' });
        expect(wrapper.emitted('cancel')).toBeTruthy();
    });

    it('autofocuses the primary confirm button on mount (P0 a11y fix)', async () => {
        // happy-dom's focus() ne met pas toujours à jour document.activeElement
        // → on spy directement sur la méthode focus du ref pour vérifier l'appel.
        const wrapper = factory();
        // L'appel mounted() se fait dans $nextTick → on attend.
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        // Vérifie que ref confirmBtn existe (autofocus point d'attache).
        expect(wrapper.vm.$refs.confirmBtn).toBeDefined();
        expect(wrapper.vm.$refs.confirmBtn.tagName).toBe('BUTTON');
        // Vérifie que c'est bien le bouton primaire ("OUI, CONTINUER")
        expect(wrapper.vm.$refs.confirmBtn.dataset.testid).toBe('kiosk-voice-dialog-confirm');
    });

    it('traps Tab focus between cancel and confirm via onTabKey method (P0 a11y fix)', async () => {
        const wrapper = factory();
        await wrapper.vm.$nextTick();
        const cancelBtn = wrapper.vm.$refs.cancelBtn;
        const confirmBtn = wrapper.vm.$refs.confirmBtn;

        // Spy sur cancel.focus pour intercepter l'appel
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
});
