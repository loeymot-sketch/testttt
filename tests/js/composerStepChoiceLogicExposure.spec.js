/**
 * [GOAL_WIZARD_DYNAMIC W1 / GAP-A] ComposerStepFormPanel must EXPOSE the choice-logic
 * fields the owner asked for and the backend already supports end-to-end
 * (ComposerStepRequest.php:25/31 + ComposerStepService::normalize:51/56):
 *   - allow_repeat  → "quantité" : the same choice can be taken several times
 *     (kiosk already renders a per-choice quantity stepper from it)
 *   - addon_role    → categorises an addon page (boisson/accompagnement/…),
 *     and must appear ONLY when source_type === 'addon'
 * Before this wave the polished owner-facing panel exposed only min/max sliders,
 * so these were invisible & uneditable (the orphan StepEditorComponent showed
 * addon_role unconditionally — a dangling control on attribute/extra steps).
 */
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import ComposerStepFormPanel from '../../resources/js/components/admin/items/composer/ComposerStepFormPanel.vue';
import fr from '../../resources/js/languages/fr.json';

const localeFr = fr?.default ?? fr;

function mountPanel(overrides = {}) {
    const i18n = createI18n({
        legacy: true,
        locale: 'fr',
        messages: { fr: localeFr },
        silentFallbackWarn: true,
    });

    return mount(ComposerStepFormPanel, {
        props: {
            modelValue: {
                label: 'Sauce',
                source_type: 'item_attribute',
                min_select: 0,
                max_select: 3,
                allow_repeat: false,
                visible_on: ['pos', 'kiosk'],
                is_active: true,
                addon_role: null,
                ...overrides,
            },
            availableSources: {
                item_attribute: [{ id: 1, name: 'Sauce' }],
                extra_group: [],
                addon: [{ id: 9, name: 'Coca' }],
            },
            sourceTypeLabels: {
                item_attribute: 'Attribut produit',
                extra_group: 'Groupe extras',
                addon: 'Addon catalogue',
            },
        },
        global: { plugins: [i18n] },
    });
}

const addonStep = {
    label: 'Boisson',
    source_type: 'addon',
    min_select: 1,
    max_select: 1,
    allow_repeat: false,
    visible_on: ['pos', 'kiosk'],
    is_active: true,
    addon_role: null,
};

describe('ComposerStepFormPanel — choice-logic exposure (GAP-A)', () => {
    it('exposes the quantité (allow_repeat) toggle and commits it', async () => {
        const w = mountPanel();
        const toggle = w.find('[data-testid="composer-step-allow-repeat"]');
        expect(toggle.exists()).toBe(true);

        await toggle.setValue(true);
        const emitted = w.emitted('update:modelValue');
        expect(emitted).toBeTruthy();
        expect(emitted.at(-1)[0].allow_repeat).toBe(true);
    });

    it('preserves an existing allow_repeat=true step on mount (no live-data loss)', () => {
        const w = mountPanel({ allow_repeat: true });
        const toggle = w.find('[data-testid="composer-step-allow-repeat"]');
        expect(toggle.element.checked).toBe(true);
    });

    it('shows addon_role ONLY when source_type=addon and commits the selection', async () => {
        const w = mountPanel({ source_type: 'item_attribute' });
        expect(w.find('[data-testid="composer-step-addon-role"]').exists()).toBe(false);

        await w.setProps({ modelValue: { ...addonStep } });
        const select = w.find('[data-testid="composer-step-addon-role"]');
        expect(select.exists()).toBe(true);

        await select.setValue('drink');
        const emitted = w.emitted('update:modelValue');
        expect(emitted.at(-1)[0].addon_role).toBe('drink');
    });

    it('extends the summary to mention quantity when allow_repeat is on', () => {
        const w = mountPanel({ allow_repeat: true, min_select: 0, max_select: 3 });
        expect(w.text()).toContain('plusieurs exemplaires');
    });

    it('renders FR labels for the new controls (no raw i18n keys)', () => {
        const w = mountPanel({ source_type: 'addon' });
        const text = w.text();
        expect(text).toContain('Quantité');
        expect(text).toContain("Rôle de l'add-on");
        expect(text).not.toContain('label.composer.allow_repeat');
        expect(text).not.toContain('label.composer.addon_role');
    });
});
