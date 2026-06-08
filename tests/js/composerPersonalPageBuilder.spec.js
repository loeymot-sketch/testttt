import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import fr from '../../resources/js/languages/fr.json';

const localeFr = fr?.default ?? fr;

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

// Profile already exists (has an id) so the saveDraft-first guard does NOT fire and
// the POST goes straight to .../profiles/77/personal-page.
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
        if (url === 'admin/composer/categories/42/available-sources') {
            return Promise.resolve({ data: { data: { category_id: 42, ...sources } } });
        }
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    axios.post.mockResolvedValue({
        data: {
            success: true,
            data: { step_id: 9, step_key: 'sauces_maison', group_label: 'Sauces maison', options_created: 2, items_touched: 3 },
        },
    });
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

describe('composer personal-page builder', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('exposes the "page personnalisée" builder action in the category context', async () => {
        const wrapper = await mountEditor({ entityType: 'category', entityId: 42 });

        expect(wrapper.find('[data-testid="admin-composer-add-personal-page"]').exists()).toBe(true);
    });

    it('opens the modal with one editable option row carrying its own price', async () => {
        const wrapper = await mountEditor({ entityType: 'category', entityId: 42 });

        await wrapper.find('[data-testid="admin-composer-add-personal-page"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="composer-personal-page-modal"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="composer-personal-page-option-0-price"]').exists()).toBe(true);
    });

    it('POSTs to the personal-page endpoint with a per-option price payload and no top-level page price', async () => {
        const wrapper = await mountEditor({ entityType: 'category', entityId: 42 });

        wrapper.vm.personalPage = {
            label: 'Sauces maison',
            options: [
                { name: 'Algérienne', price: 0, description: 'maison' },
                { name: 'Samouraï', price: 1.5, description: '' },
            ],
            min_select: 0,
            max_select: null,
            visible_on: ['pos', 'kiosk'],
        };

        await wrapper.vm.submitPersonalPage();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, payload] = axios.post.mock.calls[0];

        // Correct route, model-bound to the existing profile id.
        expect(url).toBe('admin/composer/profiles/77/personal-page');

        // Payload shape: label + repeatable options + select bounds + visible_on.
        expect(payload.label).toBe('Sauces maison');
        expect(Array.isArray(payload.options)).toBe(true);
        expect(payload.options).toHaveLength(2);
        expect(payload.min_select).toBe(0);
        // max_select defaults to the option count when left blank.
        expect(payload.max_select).toBe(2);
        expect(payload.visible_on).toEqual(['pos', 'kiosk']);

        // NF525: each option carries its OWN price on the construct.
        expect(payload.options[0]).toMatchObject({ name: 'Algérienne', price: 0 });
        expect(payload.options[1]).toMatchObject({ name: 'Samouraï', price: 1.5 });
        payload.options.forEach((option) => {
            expect(typeof option.price).toBe('number');
        });

        // NF525 hard rule: there is NO single price on the page/step itself.
        expect(payload).not.toHaveProperty('price');
        expect(payload).not.toHaveProperty('total');
    });

    it('reloads the profile after a successful create (server-created step is not local-only)', async () => {
        const wrapper = await mountEditor({ entityType: 'category', entityId: 42 });
        axios.get.mockClear();

        wrapper.vm.personalPage = {
            label: 'Sauces maison',
            options: [{ name: 'Algérienne', price: 0, description: '' }],
            min_select: 0,
            max_select: null,
            visible_on: ['pos', 'kiosk'],
        };

        await wrapper.vm.submitPersonalPage();
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith('admin/composer/categories/42/profile', undefined);
        expect(alertServiceMock.success).toHaveBeenCalled();
    });

    it('renders the modal with resolved French i18n (no raw label leaks)', async () => {
        // Visual-mandate proxy: mount with the REAL fr.json so the new keys must resolve.
        // A raw label leak (label.composer.* / message.composer.*) would surface here.
        primeAxios();
        const i18n = createI18n({ legacy: true, locale: 'fr', messages: { fr: localeFr }, silentFallbackWarn: true });
        const wrapper = mount(ProductComposerEditorComponent, {
            props: { entityType: 'category', entityId: 42 },
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
                        getters: { 'backendGlobalState/branches': [{ id: 1, name: 'Paris Centre' }] },
                    },
                    $router: { push: vi.fn() },
                    $route: { params: {}, query: {}, meta: {}, path: '' },
                },
            },
        });
        await flushPromises();
        await flushPromises();

        // Sidebar action label resolves.
        expect(wrapper.find('[data-testid="admin-composer-add-personal-page"]').text()).toContain('Créer une page personnalisée');

        await wrapper.find('[data-testid="admin-composer-add-personal-page"]').trigger('click');
        await flushPromises();

        const modal = wrapper.find('[data-testid="composer-personal-page-modal"]');
        expect(modal.exists()).toBe(true);
        const modalText = modal.text();
        // Resolved FR copy is present.
        expect(modalText).toContain('Options de la page');
        expect(modalText).toContain("Nom de l'option");
        // No raw i18n keys leaked into the rendered DOM.
        expect(modalText).not.toMatch(/label\.composer\./);
        expect(modalText).not.toMatch(/message\.composer\./);
    });

    it('surfaces a 422 validation message without closing the modal', async () => {
        const wrapper = await mountEditor({ entityType: 'category', entityId: 42 });
        wrapper.vm.openPersonalPage();
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { message: 'Le titre est requis.' } } });

        wrapper.vm.personalPage = {
            label: 'Sauces maison',
            options: [{ name: 'Algérienne', price: 0, description: '' }],
            min_select: 0,
            max_select: null,
            visible_on: ['pos', 'kiosk'],
        };

        await wrapper.vm.submitPersonalPage();
        await flushPromises();

        expect(wrapper.vm.personalPageOpen).toBe(true);
        expect(wrapper.vm.personalPageError).toBe('Le titre est requis.');
    });
});
