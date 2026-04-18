/**
 * Kiosk Design V1 — Phase 7.3 : Audit a11y structurel des atoms DS.
 *
 * Pourquoi pas axe-core ?
 *   axe-core aurait nécessité d'ajouter un package de plusieurs Mo juste
 *   pour du test. Le master prompt interdit les libs UI lourdes (y compris
 *   indirectement via les outils test). On fait donc un audit ciblé sur
 *   les invariants WCAG 2.2 AA qu'on peut valider par inspection DOM :
 *
 *   - `role` et `aria-*` sur les éléments interactifs custom (KsChip, KsModal).
 *   - `tabindex` cohérent (pas de trap clavier).
 *   - Labels accessibles (aria-label / aria-labelledby / <label>).
 *   - Focus management sur les modals (autofocus + aria-modal).
 *   - Contrastes : pas vérifiable via DOM, couvert par les tokens CSS
 *     (tokens-aaa.css) et par le test visuel manuel documenté §2.
 *
 * Couverture : 7 atoms DS + KsConsentModal (RGPD).
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KsButton from '../../resources/js/components/frontend/kiosk/ds/KsButton.vue';
import KsCard from '../../resources/js/components/frontend/kiosk/ds/KsCard.vue';
import KsChip from '../../resources/js/components/frontend/kiosk/ds/KsChip.vue';
import KsBadge from '../../resources/js/components/frontend/kiosk/ds/KsBadge.vue';
import KsModal from '../../resources/js/components/frontend/kiosk/ds/KsModal.vue';
import KsStepper from '../../resources/js/components/frontend/kiosk/ds/KsStepper.vue';
import KsConsentModal from '../../resources/js/components/frontend/kiosk/ds/KsConsentModal.vue';
import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import frMessages from '../../resources/js/languages/fr.json';

function makeI18n() { return createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } }); }
function makeStore() { return createStore({ modules: { kioskSettings } }); }

describe('A11y — KsButton', () => {
    it('native <button> avec type par défaut non-submit (évite submit accidentel)', () => {
        const w = mount(KsButton, { slots: { default: 'Click' } });
        const btn = w.find('button');
        expect(btn.exists()).toBe(true);
        expect(btn.attributes('type')).not.toBe('submit');
    });

    it('disabled state propage aria-disabled ou disabled native', () => {
        const w = mount(KsButton, { props: { disabled: true }, slots: { default: 'X' } });
        const btn = w.find('button');
        const isDisabled = btn.attributes('disabled') !== undefined || btn.attributes('aria-disabled') === 'true';
        expect(isDisabled).toBe(true);
    });
});

describe('A11y — KsChip (div[role=button] + tabindex + aria-pressed)', () => {
    it('pattern WCAG div[role=button] — évite <button> imbriqué pour supporter removable', () => {
        const w = mount(KsChip, { slots: { default: 'Tag' } });
        const root = w.element;
        expect(root.getAttribute('role')).toBe('button');
        expect(root.getAttribute('tabindex')).toBe('0');
        expect(root.getAttribute('aria-pressed')).toBe('false');
    });

    it('disabled → tabindex=-1 + aria-disabled=true', () => {
        const w = mount(KsChip, { props: { disabled: true }, slots: { default: 'Tag' } });
        const root = w.element;
        expect(root.getAttribute('tabindex')).toBe('-1');
        expect(root.getAttribute('aria-disabled')).toBe('true');
    });

    it('support keyboard activation (Enter / Space)', async () => {
        const w = mount(KsChip, { slots: { default: 'Tag' } });
        await w.trigger('keydown.enter');
        await w.trigger('keydown.space');
        // Le template émet `click` sur enter/space (voir KsChip.onClick).
        expect(w.emitted().click).toBeTruthy();
        expect(w.emitted().click.length).toBeGreaterThanOrEqual(1);
    });

    it('selected passe aria-pressed=true', () => {
        const w = mount(KsChip, { props: { selected: true }, slots: { default: 'Tag' } });
        expect(w.element.getAttribute('aria-pressed')).toBe('true');
    });
});

describe('A11y — KsBadge', () => {
    it('rend du texte visible (pas purement visuel)', () => {
        const w = mount(KsBadge, { slots: { default: 'Nouveau' } });
        expect(w.text()).toContain('Nouveau');
    });

    it('iconOnly expose aria-label quand fourni', () => {
        const w = mount(KsBadge, {
            props: { iconOnly: true, ariaLabel: 'Halal' },
            slots: { icon: '✓' },
        });
        expect(w.element.getAttribute('role')).toBe('img');
        expect(w.element.getAttribute('aria-label')).toBe('Halal');
    });
});

describe('A11y — KsCard', () => {
    it('n\'ajoute pas de tabindex ni role par défaut (container neutre)', () => {
        const w = mount(KsCard, { slots: { default: '<p>Contenu</p>' } });
        const root = w.element;
        expect(root.getAttribute('tabindex')).toBeNull();
        expect(root.getAttribute('role')).toBeNull();
    });

    it('devient focusable avec role=button quand interactive=true', () => {
        const w = mount(KsCard, { props: { interactive: true }, slots: { default: '<p>X</p>' } });
        const root = w.element;
        expect(root.getAttribute('role')).toBe('button');
        expect(root.getAttribute('tabindex')).toBe('0');
    });

    it('disabled → aria-disabled + pas de tabindex (pas focusable)', () => {
        const w = mount(KsCard, {
            props: { interactive: true, disabled: true },
            slots: { default: '<p>X</p>' },
        });
        const root = w.element;
        expect(root.getAttribute('aria-disabled')).toBe('true');
        expect(root.getAttribute('tabindex')).toBeNull();
    });
});

describe('A11y — KsModal', () => {
    it('role=dialog + aria-modal=true quand ouverte', () => {
        const w = mount(KsModal, {
            props: { modelValue: true, title: 'Titre' },
            slots: { default: '<p>Body</p>' },
            attachTo: document.body,
        });
        const dialog = w.find('[role="dialog"]');
        expect(dialog.exists()).toBe(true);
        expect(dialog.attributes('aria-modal')).toBe('true');
        w.unmount();
    });

    it('aria-labelledby réfère au title rendu', () => {
        const w = mount(KsModal, {
            props: { modelValue: true, title: 'Mon titre' },
            slots: { default: '<p>Body</p>' },
            attachTo: document.body,
        });
        const dialog = w.find('[role="dialog"]');
        const labelId = dialog.attributes('aria-labelledby');
        if (labelId) {
            const titleEl = document.getElementById(labelId);
            expect(titleEl).toBeTruthy();
            expect(titleEl.textContent).toContain('Mon titre');
        } else {
            expect(dialog.attributes('aria-label')).toBeDefined();
        }
        w.unmount();
    });
});

describe('A11y — KsStepper (role=progressbar)', () => {
    const STEPS = [
        { id: 'size', label: 'Taille', icon: '📏' },
        { id: 'pain', label: 'Pain', icon: '🍞' },
        { id: 'meat', label: 'Viande', icon: '🥩' },
    ];

    it('role=progressbar avec aria-valuemin/max/now dérivé des steps', () => {
        const w = mount(KsStepper, {
            props: { steps: STEPS, current: 1, ariaLabel: 'Progression' },
        });
        const root = w.find('[role="progressbar"]');
        expect(root.exists()).toBe(true);
        expect(root.attributes('aria-valuemin')).toBe('1');
        expect(root.attributes('aria-valuemax')).toBe('3');
        // current=1 (0-indexed) → aria-valuenow = 2 (humain-lisible, 1-indexed)
        expect(root.attributes('aria-valuenow')).toBe('2');
        expect(root.attributes('aria-label')).toBe('Progression');
    });

    it('step actif porte aria-current=step (l\'étape en cours)', () => {
        const w = mount(KsStepper, {
            props: { steps: STEPS, current: 2 },
        });
        const current = w.find('[aria-current="step"]');
        expect(current.exists()).toBe(true);
        expect(current.text()).toContain('Viande');
    });
});

describe('A11y — KsConsentModal (RGPD)', () => {
    it('role=dialog + aria-modal + aria-labelledby', () => {
        const w = mount(KsConsentModal, {
            props: { modelValue: true },
            global: { plugins: [makeStore(), makeI18n()] },
            attachTo: document.body,
        });
        const dialog = w.find('[data-testid="kiosk-consent-modal"]');
        expect(dialog.exists()).toBe(true);
        expect(dialog.attributes('role')).toBe('dialog');
        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(dialog.attributes('aria-labelledby')).toBeDefined();
        w.unmount();
    });

    it('checkboxes non pré-cochées ET ont un label associé', () => {
        const w = mount(KsConsentModal, {
            props: { modelValue: true },
            global: { plugins: [makeStore(), makeI18n()] },
            attachTo: document.body,
        });
        const checkboxes = w.findAll('input[type="checkbox"]');
        expect(checkboxes.length).toBeGreaterThanOrEqual(2);
        checkboxes.forEach((cb) => {
            // RGPD : pas de case pré-cochée.
            expect(cb.element.checked).toBe(false);
            // Label associé (id+for OR label parent wrap OR aria-label/labelledby).
            const id = cb.attributes('id');
            const ariaLabel = cb.attributes('aria-label');
            const ariaLabelledby = cb.attributes('aria-labelledby');
            let hasLabel = !!ariaLabel || !!ariaLabelledby;
            if (!hasLabel && id) {
                hasLabel = !!document.querySelector(`label[for="${id}"]`);
            }
            if (!hasLabel) hasLabel = !!cb.element.closest('label');
            expect(hasLabel).toBe(true);
        });
        w.unmount();
    });
});
