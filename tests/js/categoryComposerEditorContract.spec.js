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

const category = {
    id: 42,
    name: 'Tacos',
    product_count: 3,
};

const item = {
    id: 7,
    name: 'Tacos XL',
    category_name: 'Tacos',
};

const categoryProfile = {
    id: 77,
    item_id: null,
    item_category_id: 42,
    template: 'custom',
    is_published: false,
    branch_id_scope: null,
    version: 1,
    steps: [],
};

const itemProfile = {
    id: 55,
    item_id: 7,
    template: 'custom',
    is_published: false,
    branch_id_scope: null,
    version: 3,
    steps: [],
};

const sources = {
    item_attribute: [{ id: 5, name: 'Viande', source_type: 'item_attribute' }],
    extra_group: [],
    addon: [],
};

function primeAxios() {
    axios.get.mockImplementation((url) => {
        if (url === 'admin/setting/item-category/show/42') {
            return Promise.resolve({ data: { data: category } });
        }
        if (url === 'admin/composer/categories/42/profile') {
            return Promise.resolve({ data: { data: categoryProfile } });
        }
        if (url === 'admin/item/show/7') {
            return Promise.resolve({ data: { data: item } });
        }
        if (url === 'admin/composer/items/7/profile') {
            return Promise.resolve({ data: { data: itemProfile } });
        }
        if (url === 'admin/composer/items/7/available-sources') {
            return Promise.resolve({ data: { data: sources } });
        }
        if (url === 'admin/composer/categories/42/available-sources') {
            return Promise.resolve({ data: { data: sources } });
        }
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    axios.post.mockResolvedValue({ data: { data: categoryProfile } });
    axios.put.mockResolvedValue({ data: { data: categoryProfile } });
    axios.patch.mockResolvedValue({ data: { data: {} } });
    axios.delete.mockResolvedValue({ data: { status: true } });
}

async function mountEditor(props) {
    primeAxios();
    const wrapper = mount(ProductComposerEditorComponent, {
        props,
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
                $t: (key) => key,
                $store: {
                    dispatch: vi.fn(() => Promise.resolve()),
                    getters: {
                        'backendGlobalState/branches': [{ id: 1, name: 'Paris Centre' }],
                    },
                },
                $router: { push: vi.fn() },
                $route: { params: {}, query: {}, meta: {}, path: '' },
            },
        },
    });
    await flushPromises();
    await flushPromises();
    return wrapper;
}

describe('ProductComposerEditorComponent category contract', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('loads the category composer profile endpoint', async () => {
        await mountEditor({ entityType: 'category', entityId: 42 });

        expect(axios.get).toHaveBeenCalledWith('admin/composer/categories/42/profile', undefined);
    });

    it('renders the category wizard header', async () => {
        const wrapper = await mountEditor({ entityType: 'category', entityId: 42 });

        expect(wrapper.find('[data-testid="admin-composer-product-name"]').text()).toContain('Wizard de la catégorie');
        expect(wrapper.find('[data-testid="admin-composer-product-name"]').text()).toContain('Tacos');
    });

    it('applies a template through the category endpoint', async () => {
        const wrapper = await mountEditor({ entityType: 'category', entityId: 42 });

        await wrapper.vm.applyTemplate('tacos');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('admin/composer/categories/42/apply-template', { template: 'tacos' });
    });

    it('keeps item composer endpoints unchanged', async () => {
        await mountEditor({ itemId: 7 });

        expect(axios.get).toHaveBeenCalledWith('admin/composer/items/7/profile', undefined);
        expect(axios.get).toHaveBeenCalledWith('admin/composer/items/7/available-sources');
    });

    it('loads category available sources instead of an empty picker', async () => {
        await mountEditor({ entityType: 'category', entityId: 42 });

        expect(axios.get).toHaveBeenCalledWith('admin/composer/categories/42/available-sources');
    });
});
