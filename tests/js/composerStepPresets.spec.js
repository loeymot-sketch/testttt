/**
 * [GOAL CMS heal P1-3 2026-06-10] Presets « Choix unique / Choix multiples »
 * dans ComposerStepFormPanel : pilotent min_select/max_select/allow_repeat
 * sans raisonner en chiffres (demande owner « logique des choix »).
 */
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ComposerStepFormPanel from '../../resources/js/components/admin/items/composer/ComposerStepFormPanel.vue';

function mountPanel(step = {}) {
    return mount(ComposerStepFormPanel, {
        global: {
            // Pas de $t mocké : le helper interne t(key, fallback) du panneau
            // retombe alors sur le fallback FR (comportement runtime réel
            // quand vue-i18n manque).
            mocks: { $t: undefined },
        },
        props: {
            modelValue: {
                label: 'Sauces',
                source_type: 'extra_group',
                source_ref: 'sauce',
                min_select: 0,
                max_select: 3,
                allow_repeat: false,
                addon_role: null,
                visible_on: ['pos', 'kiosk'],
                ...step,
            },
            availableSources: { extra_group: [], item_attribute: [], addon: [] },
        },
    });
}

describe('ComposerStepFormPanel — presets logique des choix', () => {
    it('preset single sets min=1 max=1 no repeat', async () => {
        const wrapper = mountPanel();
        await wrapper.find('[data-testid="composer-step-preset-single"]').trigger('change');

        const emitted = wrapper.emitted('change');
        const last = emitted[emitted.length - 1][0];
        expect(last.min_select).toBe(1);
        expect(last.max_select).toBe(1);
        expect(last.allow_repeat).toBe(false);
    });

    it('preset multiple sets min=0 max=10 with repeat', async () => {
        const wrapper = mountPanel();
        await wrapper.find('[data-testid="composer-step-preset-multiple"]').trigger('change');

        const emitted = wrapper.emitted('change');
        const last = emitted[emitted.length - 1][0];
        expect(last.min_select).toBe(0);
        expect(last.max_select).toBe(10);
        expect(last.allow_repeat).toBe(true);
    });

    it('shows read-only choice prices of the selected source (heal P1-4)', () => {
        const wrapper = mountPanel(
            { source_type: 'extra_group', source_ref: 'sauce' },
        );
        wrapper.setProps({
            availableSources: {
                extra_group: [
                    {
                        id: 'sauce',
                        name: 'Sauces',
                        source_type: 'extra_group',
                        choices: [
                            { id: 1, name: 'Blanche', price: 0 },
                            { id: 2, name: 'Algérienne', price: 0.5 },
                        ],
                    },
                ],
                item_attribute: [],
                addon: [],
            },
        });
        return wrapper.vm.$nextTick().then(() => {
            const panel = wrapper.find('[data-testid="composer-step-choice-prices"]');
            expect(panel.exists()).toBe(true);
            expect(panel.text()).toContain('Blanche');
            expect(panel.text()).toContain('Algérienne');
            expect(panel.text()).toContain('0,50');
            expect(panel.text()).toContain('Inclus');
        });
    });

    it('reflects current settings as the matching preset', () => {
        const single = mountPanel({ min_select: 1, max_select: 1, allow_repeat: false });
        expect(single.find('[data-testid="composer-step-preset-single"]').element.checked).toBe(true);

        const custom = mountPanel({ min_select: 2, max_select: 5, allow_repeat: false });
        expect(custom.find('[data-testid="composer-step-preset-custom"]').element.checked).toBe(true);
    });
});
