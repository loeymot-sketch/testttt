import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

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

vi.mock('vue-draggable-next', () => ({
    VueDraggableNext: {
        name: 'draggable',
        props: ['modelValue'],
        emits: ['update:modelValue', 'end'],
        // Real component uses default slot + v-for; pass through for tests.
        template: '<div data-testid="draggable"><slot /></div>',
    },
}));

import axios from 'axios';
import ProductComposerEditorComponent from '../../resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue';

const item = {
    id: 7,
    name: 'Tacos XL',
    category_name: 'Tacos',
    preview: '/uploads/tacos.jpg',
};

const sources = {
    item_id: 7,
    item_attribute: [{ id: 5, name: 'Viande', source_type: 'item_attribute' }],
    extra_group: [{ id: 'sauces', name: 'Sauces extras', source_type: 'extra_group' }],
    addon: [{ id: 88, name: 'Boisson', source_type: 'addon' }],
};

function profile(overrides = {}) {
    return {
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
            {
                id: 102,
                profile_id: 55,
                step_key: 'boisson',
                label: 'Boisson',
                source_type: 'addon',
                source_ref: '88',
                min_select: 0,
                max_select: 1,
                visible_on: ['kiosk'],
                position: 1,
                is_active: true,
            },
        ],
        ...overrides,
    };
}

const t = (key) => ({
    'label.composer.new_page': 'Nouvelle page',
    'label.composer.source_item_attribute': 'Attribut produit',
    'label.composer.source_extra_group': 'Groupe extras',
    'label.composer.source_addon': 'Addon catalogue',
    'label.composer.all_source_options': 'Aucune source — page vide en caisse',
    'message.composer.no_steps': 'Ajoutez une page pour commencer.',
}[key] || key);

function primeAxios({ profilePayload = profile() } = {}) {
    axios.get.mockImplementation((url) => {
        if (url === 'admin/item/show/7') {
            return Promise.resolve({ data: { data: item } });
        }
        if (url === 'admin/composer/items/7/profile') {
            return Promise.resolve({ data: { data: profilePayload } });
        }
        if (url === 'admin/composer/items/7/available-sources') {
            return Promise.resolve({ data: { data: sources } });
        }
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    axios.post.mockResolvedValue({ data: { data: profilePayload } });
    axios.put.mockResolvedValue({ data: { data: profilePayload } });
    axios.patch.mockResolvedValue({ data: { data: {} } });
    axios.delete.mockResolvedValue({ data: { status: true } });
}

async function mountEditor(options = {}) {
    primeAxios(options);
    const store = {
        dispatch: vi.fn(() => Promise.resolve()),
        getters: {
            'backendGlobalState/branches': [
                { id: 1, name: 'Paris Centre' },
                { id: 2, name: 'Lyon' },
            ],
        },
    };
    const routerPush = vi.fn();
    const wrapper = mount(ProductComposerEditorComponent, {
        props: { itemId: 7 },
        global: {
            stubs: {
                ItemPreviewComponent: {
                    name: 'ItemPreviewComponent',
                    template: '<section data-testid="stub-live-preview"></section>',
                    methods: { refreshAll: vi.fn() },
                },
            },
            mocks: {
                $t: t,
                $store: store,
                $router: { push: routerPush },
            },
        },
    });
    await flushPromises();
    await flushPromises();
    return { wrapper, store, routerPush };
}

describe('ProductComposerEditorComponent V2', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('rend le header avec le nom produit', async () => {
        const { wrapper } = await mountEditor();

        expect(wrapper.find('[data-testid="admin-composer-product-name"]').text()).toContain('Tacos XL');
        expect(wrapper.find('[data-testid="admin-composer-product-category"]').text()).toContain('Tacos');
        expect(wrapper.find('[data-testid="admin-composer-product-photo"]').exists()).toBe(true);
    });

    it('affiche la liste steps depuis API GET profile', async () => {
        const { wrapper } = await mountEditor();

        expect(axios.get).toHaveBeenCalledWith('admin/composer/items/7/profile', undefined);
        expect(wrapper.find('[data-testid="composer-step-row-101"]').text()).toContain('Viande');
        expect(wrapper.find('[data-testid="composer-step-row-102"]').text()).toContain('Boisson');
    });

    it('drag and drop reordonne les steps et appelle PATCH update', async () => {
        const { wrapper } = await mountEditor();
        const sidebar = wrapper.findComponent({ name: 'ComposerStepListSidebar' });
        const reordered = [...wrapper.vm.steps].reverse().map((step, index) => ({ ...step, position: index }));

        sidebar.vm.$emit('reorder', reordered);
        await flushPromises();

        expect(axios.patch).toHaveBeenCalledWith('admin/composer/steps/102', expect.objectContaining({ position: 0 }));
        expect(axios.patch).toHaveBeenCalledWith('admin/composer/steps/101', expect.objectContaining({ position: 1 }));
    });

    it('bouton Ajouter page ouvre le formulaire nouveau step', async () => {
        const { wrapper } = await mountEditor();

        await wrapper.find('[data-testid="admin-composer-add-step"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="composer-step-form-panel"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="composer-step-label-input"]').element.value).toBe('Nouvelle page');
        expect(wrapper.vm.selectedStep.id).toBeNull();
    });

    it('bouton Supprimer page demande confirmation puis DELETE step endpoint', async () => {
        const { wrapper } = await mountEditor();

        await wrapper.find('[data-testid="composer-step-remove-0"]').trigger('click');
        expect(wrapper.find('[data-testid="composer-delete-confirm-modal"]').exists()).toBe(true);

        await wrapper.find('[data-testid="composer-delete-confirm"]').trigger('click');
        await flushPromises();

        expect(axios.delete).toHaveBeenCalledWith('admin/composer/steps/101');
        expect(wrapper.find('[data-testid="composer-step-row-101"]').exists()).toBe(false);
    });

    it('picker template ouvre modal puis applique via POST apply-template', async () => {
        const { wrapper } = await mountEditor({
            profilePayload: profile({ template: 'tacos' }),
        });

        await wrapper.find('[data-testid="admin-composer-template"]').trigger('click');
        expect(wrapper.find('[data-testid="composer-template-picker-modal"]').exists()).toBe(true);

        await wrapper.find('[data-testid="composer-template-tacos"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('admin/composer/items/7/apply-template', { template: 'tacos' });
    });

    it('picker source_ref affiche les options disponibles depuis available-sources', async () => {
        const { wrapper } = await mountEditor();

        expect(wrapper.find('[data-testid="composer-step-source-ref"]').text()).toContain('Viande');
        expect(wrapper.find('[data-testid="composer-step-source-ref"]').text()).toContain('Aucune source — page vide en caisse');
    });

    it('bouton Publier demande confirmation puis appelle POST publish', async () => {
        const { wrapper } = await mountEditor({
            profilePayload: profile({ is_published: false }),
        });

        await wrapper.find('[data-testid="admin-composer-publish"]').trigger('click');
        expect(wrapper.find('[data-testid="composer-publish-confirm-modal"]').exists()).toBe(true);

        axios.post.mockResolvedValueOnce({ data: { data: profile({ is_published: true }) } });
        await wrapper.find('[data-testid="composer-publish-confirm"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('admin/composer/profiles/55/publish');
    });
});
