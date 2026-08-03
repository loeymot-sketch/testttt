import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import ComposerStepFormPanel from '../../resources/js/components/admin/items/composer/ComposerStepFormPanel.vue';
import fr from '../../resources/js/languages/fr.json';

const localeFr = fr?.default ?? fr;

function mountPanel() {
    const i18n = createI18n({
        legacy: true,
        locale: 'fr',
        messages: { fr: localeFr },
        silentFallbackWarn: true,
    });

    return mount(ComposerStepFormPanel, {
        props: {
            modelValue: {
                label: 'Viande',
                source_type: 'item_attribute',
                min: 0,
                max: 1,
                visible_on: ['pos'],
                is_active: true,
            },
            availableSources: {
                item_attribute: [{ id: 1, name: 'Viande' }],
                extra_group: [],
                addon: [],
            },
            sourceTypeLabels: {
                item_attribute: 'Attribut produit',
                extra_group: 'Groupe extras',
                addon: 'Addon catalogue',
            },
        },
        global: {
            plugins: [i18n],
        },
    });
}

describe('ComposerStepFormPanel help copy', () => {
    it('renders human labels, tooltip aria labels, and min/max summary', () => {
        const wrapper = mountPanel();
        const text = wrapper.text();

        expect(text).toContain("D'où viennent les choix ?");
        expect(text).toContain('Limiter à un groupe précis');
        expect(text).toContain('Optionnel, le client peut choisir 1 article maximum.');

        const ariaLabels = wrapper
            .findAll('button[aria-label]')
            .map((button) => button.attributes('aria-label'));

        expect(ariaLabels).toContain(localeFr.label.composer.source_type_help);
        expect(ariaLabels).toContain(localeFr.label.composer.source_ref_help);
        expect(ariaLabels).toContain(localeFr.label.composer.min_select_help);
        expect(ariaLabels).toContain(localeFr.label.composer.max_select_help);
        expect(ariaLabels).toContain(localeFr.label.composer.visible_on_help);
        expect(ariaLabels).toContain(localeFr.label.composer.is_active_help);
    });
});
