/**
 * [UIUX-W4 K3 2026-06-11] — Boutons overlay inactivité VIDES.
 *
 * KioskInactivityOverlayComponent passait `:label="…"` à KsButton, mais
 * KsButton n'a PAS de prop `label` — il rend uniquement son slot par
 * défaut (`<span class="ks-btn__label"><slot /></span>`, ds/KsButton.vue).
 * Résultat : les deux CTA « Je suis là » / « Abandonner » s'affichaient
 * comme des pilules vides sur la borne.
 *
 * Fix : texte passé en slot. Ce spec monte réellement le composant et
 * exige un libellé non vide dans chaque bouton (RED avant fix).
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import KioskInactivityOverlayComponent
    from '../../resources/js/components/frontend/kiosk/KioskInactivityOverlayComponent.vue';

const T = {
    'kiosk.inactivity.stay': 'Je suis là',
    'kiosk.inactivity.leave': 'Abandonner',
    'kiosk.inactivity.title': 'Toujours là ?',
    'kiosk.inactivity.desc': 'Votre commande sera effacée dans',
    'kiosk.inactivity.seconds': 'secondes',
    'kiosk.inactivity.second': 'seconde',
};

function mountOverlay() {
    return mount(KioskInactivityOverlayComponent, {
        props: { visible: true },
        global: {
            mocks: { $t: (key) => T[key] ?? key },
        },
    });
}

describe('KioskInactivityOverlay CTA labels (K3)', () => {
    it('renders a non-empty FR label inside the "stay" button', () => {
        const wrapper = mountOverlay();
        const stay = wrapper.find('[data-testid="kiosk-inactivity-stay"]');
        expect(stay.exists()).toBe(true);
        expect(stay.text().trim()).toBe('Je suis là');
        wrapper.unmount();
    });

    it('renders a non-empty FR label inside the "leave" button', () => {
        const wrapper = mountOverlay();
        const leave = wrapper.find('[data-testid="kiosk-inactivity-leave"]');
        expect(leave.exists()).toBe(true);
        expect(leave.text().trim()).toBe('Abandonner');
        wrapper.unmount();
    });

    it('emits stay/leave when the buttons are clicked (behaviour preserved)', async () => {
        const wrapper = mountOverlay();
        await wrapper.find('[data-testid="kiosk-inactivity-stay"]').trigger('click');
        await wrapper.find('[data-testid="kiosk-inactivity-leave"]').trigger('click');
        expect(wrapper.emitted('stay')).toBeTruthy();
        expect(wrapper.emitted('leave')).toBeTruthy();
        wrapper.unmount();
    });
});
