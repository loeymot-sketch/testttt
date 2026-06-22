import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import fs from 'fs';
import path from 'path';
import KioskStepGenericChoicesComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue';

const root = process.cwd();

function read(file) {
    return fs.readFileSync(path.join(root, file), 'utf8');
}

const step = {
    type: 'generic_choices',
    label: 'Accompagnement',
    composer_step: {
        id: 77,
        step_key: 'accompagnement',
        label: 'Accompagnement',
        source_type: 'item_attribute',
        min_select: 1,
        max_select: 1,
        allow_repeat: false,
        choices: [
            { id: 11, name: 'Riz', source_type: 'variation', item_attribute_id: 4, status: 5 },
            { id: 12, name: 'Frites', source_type: 'variation', item_attribute_id: 4, status: 5 },
        ],
    },
};

describe('generic composer wizard step', () => {
    it('emits branch-safe composerChoices metadata for single-select generic steps', async () => {
        const wrapper = mount(KioskStepGenericChoicesComponent, {
            props: { step, selections: { composerChoices: {} } },
            global: { mocks: { $t: (key) => key } },
        });

        await wrapper.findAll('button')[0].trigger('click');

        const event = wrapper.emitted('update')?.[0];
        expect(event?.[0]).toBe('composerChoices');
        expect(event?.[1]['77'].choices['variation:11']).toMatchObject({
            id: 11,
            name: 'Riz',
            source_type: 'variation',
            item_attribute_id: 4,
            count: 1,
        });
    });

    it('supports repeatable generic extra choices without exceeding max_select', async () => {
        const repeatStep = {
            ...step,
            composer_step: {
                ...step.composer_step,
                id: 88,
                source_type: 'extra_group',
                min_select: 0,
                max_select: 2,
                allow_repeat: true,
                choices: [{ id: 21, name: 'Cheddar', source_type: 'extra', status: 5 }],
            },
        };

        let selections = { composerChoices: {} };
        const wrapper = mount(KioskStepGenericChoicesComponent, {
            props: { step: repeatStep, selections },
            global: { mocks: { $t: (key) => key } },
        });

        await wrapper.find('button').trigger('click');
        selections = { composerChoices: wrapper.emitted('update')[0][1] };
        await wrapper.setProps({ selections });
        await wrapper.find('button').trigger('click');
        selections = { composerChoices: wrapper.emitted('update')[1][1] };
        await wrapper.setProps({ selections });
        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('update')[1][1]['88'].choices['extra:21'].count).toBe(2);
        expect(wrapper.emitted('update')[2][1]['88'].choices['extra:21']).toBeUndefined();
    });

    it('emits addon composer choices with addon role metadata', async () => {
        const addonStep = {
            ...step,
            composer_step: {
                ...step.composer_step,
                id: 99,
                source_type: 'addon',
                addon_role: 'drink',
                choices: [{ id: 31, name: 'Coca', source_type: 'addon', addon_item_id: 444, role: 'drink' }],
            },
        };

        const wrapper = mount(KioskStepGenericChoicesComponent, {
            props: { step: addonStep, selections: { composerChoices: {} } },
            global: { mocks: { $t: (key) => key } },
        });

        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('update')?.[0]?.[1]['99'].choices['addon:31']).toMatchObject({
            id: 31,
            source_type: 'addon',
            addon_item_id: 444,
            role: 'drink',
            count: 1,
        });
    });

    it('disables a generic choice when backend marks it unavailable', async () => {
        const unavailableStep = {
            ...step,
            composer_step: {
                ...step.composer_step,
                choices: [
                    { id: 11, name: 'Riz', source_type: 'variation', item_attribute_id: 4, status: 5, is_available: false, unavailable_reason: 'stock_rupture' },
                ],
            },
        };

        const wrapper = mount(KioskStepGenericChoicesComponent, {
            props: { step: unavailableStep, selections: { composerChoices: {} } },
            global: { mocks: { $t: (key) => key } },
        });

        const button = wrapper.find('button');
        await button.trigger('click');

        expect(button.attributes('disabled')).toBeDefined();
        expect(wrapper.emitted('update')).toBeUndefined();
    });

    it('wires generic composer steps into kiosk runtime without frontend pricing authority', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskWizardComponent.vue');

        expect(source).toContain('generic_choices');
        expect(source).toContain('KioskStepGenericChoices');
        expect(source).toContain('composerChoiceEntries()');
        expect(source).toContain('sanitizeComposerChoicesForCurrentProfile');
        expect(source).toContain('currentComposerAllowedChoiceKeys');
        expect(source).toContain('item_addons');
        expect(source).toContain('composerVariationTotal');
        expect(source).toContain('composerExtraTotal');
        expect(source).not.toContain('composer_final_price');
    });
});
