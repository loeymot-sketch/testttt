import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import ItemPreviewComponent from '../../resources/js/components/admin/items/ItemPreviewComponent.vue';
import fr from '../../resources/js/languages/fr.json';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

vi.mock('../../resources/js/helpers/a11y/announcer.js', () => ({
    announcer: {
        polite: vi.fn(),
        assertive: vi.fn(),
        catalogChanged: vi.fn(),
        orderRejected: vi.fn(),
    },
}));

const localeFr = fr?.default ?? fr;

function mountPreview(props = {}) {
    const i18n = createI18n({
        legacy: true,
        locale: 'fr',
        messages: { fr: localeFr },
        silentFallbackWarn: true,
    });

    return mount(ItemPreviewComponent, {
        props: {
            item: { id: 5, name: 'Tacos XXL' },
            branches: [],
            steps: [],
            ...props,
        },
        global: {
            plugins: [i18n],
        },
    });
}

describe('ItemPreviewComponent wizard steps', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders configured wizard steps in the live preview', () => {
        const wrapper = mountPreview({
            steps: [
                {
                    id: 10,
                    label: 'Viande',
                    min: 1,
                    max: 1,
                    source_type: 'item_attribute',
                    visible_on: ['pos', 'kiosk'],
                },
            ],
        });

        expect(wrapper.text()).toContain('Viande');
        expect(wrapper.text()).toContain('Obligatoire — 1 choix');
    });
});
