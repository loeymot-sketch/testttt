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

vi.mock('vue-draggable-next', () => ({
    VueDraggableNext: {
        name: 'draggable',
        props: ['modelValue'],
        emits: ['update:modelValue', 'end'],
        template: `
            <div data-testid="draggable">
                <slot
                    v-for="(element, index) in modelValue"
                    name="item"
                    :element="element"
                    :index="index"
                />
            </div>
        `,
    },
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
        ],
        ...overrides,
    };
}

function primeAxios({ profilePayload = profile() } = {}) {
    axios.get.mockImplementation((url) => {
        if (url === '/admin/item/show/7') {
            return Promise.resolve({ data: { data: item } });
        }
        if (url === '/admin/composer/items/7/profile') {
            return Promise.resolve({ data: { data: profilePayload } });
        }
        if (url === '/admin/composer/items/7/available-sources') {
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
    const wrapper = mount(ProductComposerEditorComponent, {
        props: { itemId: 7 },
        global: {
            stubs: {
                ItemPreviewComponent: {
                    name: 'ItemPreviewComponent',
                    template: '<section data-testid="stub-live-preview"></section>',
                    methods: { refreshAll: vi.fn() },
                },
                ComposerPublishDiffModal: true,
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
            },
        },
    });
    await flushPromises();
    await flushPromises();
    return wrapper;
}

describe('ProductComposerEditorComponent version conflict', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('test_save_draft_sends_version_field_in_payload', async () => {
        const wrapper = await mountEditor({ profilePayload: profile({ version: 2 }) });

        await wrapper.vm.saveDraft();
        await flushPromises();

        expect(axios.put).toHaveBeenCalledWith(
            '/admin/composer/profiles/55',
            expect.objectContaining({ version: 2 })
        );
    });

    it('test_409_response_sets_conflict_detected_true_and_disables_publish', async () => {
        const wrapper = await mountEditor({ profilePayload: profile({ version: 3 }) });
        axios.put.mockRejectedValueOnce({
            response: {
                status: 409,
                data: { expected: 5, got: 3 },
            },
        });

        await wrapper.vm.saveDraft();
        await flushPromises();

        expect(wrapper.vm.conflictDetected).toBe(true);
        expect(wrapper.vm.expectedVersion).toBe(5);
        expect(wrapper.find('[data-testid="composer-version-conflict-banner"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="admin-composer-publish"]').element.disabled).toBe(true);
    });

    it('test_reload_clears_conflict_state_and_refetches_profile', async () => {
        const wrapper = await mountEditor({ profilePayload: profile({ version: 3 }) });
        await wrapper.setData({
            conflictDetected: true,
            expectedVersion: 5,
        });
        vi.clearAllMocks();

        await wrapper.find('[data-testid="composer-version-conflict-banner-reload"]').trigger('click');
        await flushPromises();

        expect(wrapper.vm.conflictDetected).toBe(false);
        expect(wrapper.vm.expectedVersion).toBeNull();
        expect(axios.get).toHaveBeenCalledWith('/admin/composer/items/7/profile', undefined);
    });
});
