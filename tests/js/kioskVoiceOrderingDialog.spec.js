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
import { describe, it, expect } from 'vitest';
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
});
