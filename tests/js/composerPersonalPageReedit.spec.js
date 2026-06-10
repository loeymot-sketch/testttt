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

const item = { id: 7, name: 'Tacos XL', category_name: 'Tacos' };

// An item profile that already owns an extra_group page → the re-edit affordance must render.
const groupStep = {
    id: 901,
    profile_id: 55,
    step_key: 'sauces_maison',
    label: 'Sauces Maison',
    source_type: 'extra_group',
    source_ref: 'Sauces Maison',
    min_select: 0,
    max_select: 2,
    visible_on: ['pos', 'kiosk'],
    position: 1,
    is_active: true,
};

const itemProfile = {
    id: 55,
    item_id: 7,
    template: 'custom',
    is_published: false,
    branch_id_scope: null,
    version: 3,
    steps: [groupStep],
};

const sources = {
    item_attribute: [],
    extra_group: [{ id: 'Sauces Maison', name: 'Sauces Maison', source_type: 'extra_group', count: 2 }],
    addon: [],
};

// What the pre-fill GET returns for step 901.
const prefill = {
    step_id: 901,
    label: 'Sauces Maison',
    group_label: 'Sauces Maison',
    min_select: 0,
    max_select: 2,
    visible_on: ['pos', 'kiosk'],
    options: [
        { name: 'Algérienne', price: 0.5, description: 'Maison', image_path: null },
        { name: 'Blanche', price: 0, description: '', image_path: null },
    ],
};

function primeAxios() {
    axios.get.mockImplementation((url) => {
        if (url === 'admin/item/show/7') return Promise.resolve({ data: { data: item } });
        if (url === 'admin/composer/items/7/profile') return Promise.resolve({ data: { data: itemProfile } });
        if (url === 'admin/composer/items/7/available-sources') return Promise.resolve({ data: { data: sources } });
        if (url === 'admin/composer/profiles/55/personal-page/901') return Promise.resolve({ data: { data: prefill } });
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    axios.post.mockResolvedValue({ data: { data: itemProfile } });
    axios.put.mockResolvedValue({ data: { data: itemProfile } });
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
                    getters: { 'backendGlobalState/branches': [{ id: 1, name: 'Paris Centre' }] },
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

describe('ProductComposerEditorComponent personal-page re-edit (W1)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('exposes "Modifier les options" only for an existing extra_group step', async () => {
        const wrapper = await mountEditor({ itemId: 7 });
        expect(wrapper.vm.selectedStepIsEditableGroup).toBe(true);
        expect(wrapper.find('[data-testid="composer-edit-personal-page"]').exists()).toBe(true);
    });

    it('pre-fills the modal from the server when editing', async () => {
        const wrapper = await mountEditor({ itemId: 7 });

        await wrapper.find('[data-testid="composer-edit-personal-page"]').trigger('click');
        await flushPromises();

        // Pre-fill GET hit the step's own endpoint.
        expect(axios.get).toHaveBeenCalledWith('admin/composer/profiles/55/personal-page/901');
        expect(wrapper.vm.personalPageOpen).toBe(true);
        expect(wrapper.vm.personalPageIsEdit).toBe(true);
        expect(wrapper.vm.personalPage.label).toBe('Sauces Maison');
        expect(wrapper.vm.personalPage.options.map((o) => o.name)).toEqual(['Algérienne', 'Blanche']);
        expect(wrapper.vm.personalPage.options[0].price).toBe(0.5);
        // Edit title shown, not create title (t() falls back to FR copy when $t echoes the key).
        expect(wrapper.find('[data-testid="composer-personal-page-title"]').text())
            .toContain('Modifier la page');
    });

    it('PUTs to the step endpoint when submitting in edit mode (never POST)', async () => {
        const wrapper = await mountEditor({ itemId: 7 });
        await wrapper.find('[data-testid="composer-edit-personal-page"]').trigger('click');
        await flushPromises();

        await wrapper.vm.submitPersonalPage();
        await flushPromises();

        expect(axios.put).toHaveBeenCalledWith(
            'admin/composer/profiles/55/personal-page/901',
            expect.objectContaining({ label: 'Sauces Maison' }),
        );
        expect(axios.post).not.toHaveBeenCalledWith(
            'admin/composer/profiles/55/personal-page',
            expect.anything(),
        );
        // Edit mode cleared after a successful PUT.
        expect(wrapper.vm.personalPageEditStepId).toBeNull();
    });

    it('still POSTs a brand-new page in create mode', async () => {
        const wrapper = await mountEditor({ itemId: 7 });

        wrapper.vm.openPersonalPage();
        wrapper.vm.personalPage.label = 'Nouvelle';
        wrapper.vm.personalPage.options = [{ name: 'Opt', price: 1, description: '' }];
        await wrapper.vm.submitPersonalPage();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            'admin/composer/profiles/55/personal-page',
            expect.objectContaining({ label: 'Nouvelle' }),
        );
        expect(axios.put).not.toHaveBeenCalled();
    });

    // [GOAL_WIZARD_E2E_PARITY W6 heal] An UNBOUND extra_group step (empty source_ref — e.g. a
    // provisioned catch-all "garnitures"/"suppléments" page that matched no real category group)
    // is NOT editable: the backend showPersonalPage/updatePersonalPage both abort 422 when
    // source_ref is empty. The edit affordance must therefore be HIDDEN on it, so the gérant never
    // hits a 422 error banner from a button that promised an edit.
    it('hides "Modifier les options" on an UNBOUND extra_group step (empty source_ref)', async () => {
        const wrapper = await mountEditor({ itemId: 7 });
        // Bound step → button shows (sanity).
        expect(wrapper.vm.selectedStepIsEditableGroup).toBe(true);
        expect(wrapper.find('[data-testid="composer-edit-personal-page"]').exists()).toBe(true);

        // Unbind the selected step (mirror a provisioned unbound catch-all page).
        wrapper.vm.selectedStep.source_ref = '';
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.selectedStepIsEditableGroup).toBe(false);
        expect(wrapper.find('[data-testid="composer-edit-personal-page"]').exists()).toBe(false);
    });
});
