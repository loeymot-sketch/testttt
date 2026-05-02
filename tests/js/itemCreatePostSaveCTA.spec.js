/**
 * [CV1-WIZARD-COMPOSABLE-001 / T-WC-AFTER-CREATE-01]
 *
 * Sentinel — Post-save CTA modal in ItemCreateComponent.vue.
 *
 * After a successful CREATE (tempId === null), the admin should see a CTA modal
 * proposing 3 next-step actions: configure wizard / view product / continue.
 * On UPDATE (tempId !== null), no CTA must appear.
 *
 * Closes audit gap A.3 #8 (post-create UX guidance).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';

const alertServiceMock = vi.hoisted(() => ({
    error: vi.fn(),
    success: vi.fn(),
    successFlip: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
}));

const appServiceMock = vi.hoisted(() => ({
    sideDrawerShow: vi.fn(),
    sideDrawerHide: vi.fn(),
    modalShow: vi.fn(),
    modalHide: vi.fn(),
}));

vi.mock('../../resources/js/services/alertService', () => ({
    default: alertServiceMock,
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: appServiceMock,
}));
vi.mock(
    '../../resources/js/components/admin/components/buttons/SmSidebarModalCreateComponent',
    () => ({
        default: { name: 'SmSidebarModalCreateComponent', template: '<div />' },
    }),
);
vi.mock('../../resources/js/components/admin/components/LoadingComponent', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));

import ItemCreateComponent from '../../resources/js/components/admin/items/ItemCreateComponent.vue';

const buildPropsForm = () => ({
    form: {
        name: 'Pizza',
        price: '12.00',
        description: '',
        caution: '',
        is_featured: 1,
        is_upsell: 0,
        item_type: 1,
        item_category_id: 1,
        tax_id: null,
        status: 1,
    },
    search: { keyword: '' },
});

const buildStoreMock = ({
    saveResponse = { data: { data: { id: 42 } } },
    tempId = null,
} = {}) => ({
    getters: new Proxy(
        {
            'item/temp': { temp_id: tempId },
            'itemCategory/lists': [],
            'tax/lists': [],
        },
        {
            get(target, property) {
                return property in target ? target[property] : [];
            },
        },
    ),
    dispatch: vi.fn((action) => {
        if (action === 'item/save') {
            return Promise.resolve(saveResponse);
        }
        return Promise.resolve();
    }),
    commit: vi.fn(),
});

const mountComponent = ({
    saveResponse = { data: { data: { id: 42 } } },
    tempId = null,
    routerPush = vi.fn(() => Promise.resolve()),
} = {}) => {
    const propsObj = buildPropsForm();
    const storeMock = buildStoreMock({ saveResponse, tempId });

    // Override mounted() so the test does not dispatch unrelated store actions.
    const TestComponent = {
        ...ItemCreateComponent,
        mounted() {},
    };

    const wrapper = shallowMount(TestComponent, {
        props: { props: propsObj },
        global: {
            stubs: {
                SmSidebarModalCreateComponent: true,
                LoadingComponent: true,
                'vue-select': true,
            },
            mocks: {
                $store: storeMock,
                $t: (key) => key,
                $route: { params: {}, query: {}, name: '' },
                $router: { push: routerPush, replace: vi.fn() },
            },
        },
    });

    return { wrapper, storeMock, routerPush, propsObj };
};

describe('ItemCreateComponent — post-save CTA (T-WC-AFTER-CREATE-01)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('save() opens the CTA modal and captures item id on CREATE success', async () => {
        const { wrapper, storeMock } = mountComponent({
            saveResponse: { data: { data: { id: 42 } } },
            tempId: null,
        });

        wrapper.vm.save();
        await flushPromises();

        expect(storeMock.dispatch).toHaveBeenCalledWith(
            'item/save',
            expect.objectContaining({ form: expect.any(FormData) }),
        );
        expect(wrapper.vm.showPostSaveCta).toBe(true);
        expect(wrapper.vm.savedItemId).toBe(42);
    });

    it('renders the CTA modal with the three guided action buttons when active', async () => {
        const { wrapper } = mountComponent();
        await wrapper.setData({ showPostSaveCta: true, savedItemId: 7 });

        expect(wrapper.find('[data-testid="item-create-post-save-cta"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="cta-configure-wizard"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="cta-view-product"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="cta-continue"]').exists()).toBe(true);
    });

    it('clicking cta-configure-wizard navigates to admin.items.composer and dismisses the modal', async () => {
        const routerPush = vi.fn(() => Promise.resolve());
        const { wrapper } = mountComponent({ routerPush });
        await wrapper.setData({ showPostSaveCta: true, savedItemId: 7 });

        await wrapper.find('[data-testid="cta-configure-wizard"]').trigger('click');

        expect(routerPush).toHaveBeenCalledTimes(1);
        expect(routerPush).toHaveBeenCalledWith({
            name: 'admin.items.composer',
            params: { id: 7 },
        });
        expect(wrapper.vm.showPostSaveCta).toBe(false);
        expect(wrapper.vm.savedItemId).toBeNull();
    });

    it('clicking cta-continue dismisses the modal without any router navigation', async () => {
        const routerPush = vi.fn(() => Promise.resolve());
        const { wrapper } = mountComponent({ routerPush });
        await wrapper.setData({ showPostSaveCta: true, savedItemId: 9 });

        await wrapper.find('[data-testid="cta-continue"]').trigger('click');

        expect(routerPush).not.toHaveBeenCalled();
        expect(wrapper.vm.showPostSaveCta).toBe(false);
        expect(wrapper.vm.savedItemId).toBeNull();
    });

    it('save() does not surface the CTA when in EDIT mode (tempId !== null)', async () => {
        const { wrapper } = mountComponent({
            saveResponse: { data: { data: { id: 99 } } },
            tempId: 99,
        });

        wrapper.vm.save();
        await flushPromises();

        expect(wrapper.vm.showPostSaveCta).toBe(false);
        expect(wrapper.vm.savedItemId).toBeNull();
    });
});
