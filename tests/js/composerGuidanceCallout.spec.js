import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import fr from '../../resources/js/languages/fr.json';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
}));

vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

import axios from 'axios';
import ProductComposerEditorComponent from '../../resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue';

const localeFr = fr?.default ?? fr;

const item = {
    id: 7,
    name: 'Tacos XL',
    category_name: 'Tacos',
};

const emptyProfile = {
    id: 55,
    item_id: 7,
    template: 'custom',
    is_published: false,
    branch_id_scope: null,
    version: 3,
    steps: [],
};

function primeAxios() {
    axios.get.mockImplementation((url) => {
        if (url === '/admin/item/show/7') {
            return Promise.resolve({ data: { data: item } });
        }
        if (url === '/admin/composer/items/7/profile') {
            return Promise.resolve({ data: { data: emptyProfile } });
        }
        if (url === '/admin/composer/items/7/available-sources') {
            return Promise.resolve({
                data: {
                    data: {
                        item_attribute: [],
                        extra_group: [],
                        addon: [],
                    },
                },
            });
        }
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
}

async function mountEditor() {
    primeAxios();

    const i18n = createI18n({
        legacy: true,
        locale: 'fr',
        messages: { fr: localeFr },
        silentFallbackWarn: true,
    });

    const wrapper = mount(ProductComposerEditorComponent, {
        props: { itemId: 7 },
        global: {
            plugins: [i18n],
            stubs: {
                ComposerPublishDiffModal: true,
                ComposerStepFormPanel: true,
                ComposerStepListSidebar: true,
                ComposerTemplatePickerModal: true,
                ComposerVersionConflictBanner: true,
                ItemPreviewComponent: {
                    name: 'ItemPreviewComponent',
                    template: '<section data-testid="stub-live-preview"></section>',
                    methods: { refreshAll: vi.fn() },
                },
            },
            mocks: {
                $store: {
                    dispatch: vi.fn(() => Promise.resolve()),
                    getters: {
                        'backendGlobalState/branches': [{ id: 1, name: 'Paris Centre' }],
                    },
                },
                $router: { push: vi.fn() },
            },
        },
    });

    await flushPromises();
    await flushPromises();
    return wrapper;
}

describe('ProductComposerEditorComponent zero-step guidance callout', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the educational callout and its two actions when no steps exist', async () => {
        const wrapper = await mountEditor();
        const text = wrapper.find('[data-testid="admin-composer-empty-state"]').text();

        expect(text).toContain('Comment fonctionne le wizard ?');
        expect(text).toContain('Choisir un template');
        expect(text).toContain('Ajouter une page manuellement');
    });
});
