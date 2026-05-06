import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const alertServiceMock = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
}));

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
    default: alertServiceMock,
}));

import axios from 'axios';
import ProductComposerEditorComponent from '../../resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue';

const item = {
    id: 7,
    name: 'Tacos XL',
    category_name: 'Tacos',
};

const sources = {
    item_id: 7,
    item_attribute: [{ id: 5, name: 'Viande', source_type: 'item_attribute' }],
    extra_group: [],
    addon: [],
};

const profile = {
    id: 55,
    item_id: 7,
    template: 'custom',
    is_published: false,
    branch_id_scope: null,
    version: 3,
    steps: [
        {
            id: 101,
            profile_id: 55,
            step_key: 'viande',
            label: 'Viande',
            source_type: 'item_attribute',
            source_ref: '5',
            min_select: 1,
            max_select: 1,
            visible_on: ['pos', 'kiosk'],
            position: 0,
            is_active: true,
        },
    ],
};

const translations = {
    'label.dismiss': 'Dismiss',
    'message.composer.apply_template_validation_error': 'Invalid data was sent to the server.',
    'message.composer.apply_template_server_error': 'Server error. Try again in a moment.',
    'message.composer.apply_template_unknown_error': 'Unexpected failure while applying the template.',
};

function primeAxios() {
    axios.get.mockImplementation((url) => {
        if (url === 'admin/item/show/7') {
            return Promise.resolve({ data: { data: item } });
        }
        if (url === 'admin/composer/items/7/profile') {
            return Promise.resolve({ data: { data: profile } });
        }
        if (url === 'admin/composer/items/7/available-sources') {
            return Promise.resolve({ data: { data: sources } });
        }
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    axios.put.mockResolvedValue({ data: { data: profile } });
    axios.patch.mockResolvedValue({ data: { data: {} } });
    axios.delete.mockResolvedValue({ data: { status: true } });
}

async function mountEditor() {
    primeAxios();
    const wrapper = mount(ProductComposerEditorComponent, {
        props: { itemId: 7 },
        global: {
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
                $t: (key) => translations[key] || key,
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

describe('ProductComposerEditorComponent applyTemplate errors', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('surfaces validation errors in an alert banner', async () => {
        const wrapper = await mountEditor();
        axios.post.mockRejectedValueOnce({
            response: {
                status: 422,
                data: { message: 'Template invalide.' },
            },
        });

        await wrapper.vm.applyTemplate('tacos');
        await flushPromises();

        expect(wrapper.vm.applyTemplateError).toBe('Template invalide.');
        expect(wrapper.find('[role="alert"]').text()).toContain('Template invalide.');
    });

    it('surfaces server errors with the translated fallback', async () => {
        const wrapper = await mountEditor();
        axios.post.mockRejectedValueOnce({
            response: {
                status: 500,
                data: {},
            },
        });

        await wrapper.vm.applyTemplate('tacos');
        await flushPromises();

        expect(wrapper.vm.applyTemplateError).toBe('Server error. Try again in a moment.');
        expect(wrapper.find('[role="alert"]').text()).toContain('Server error. Try again in a moment.');
    });
});
