// [WIZARD-STUDIO W1 2026-06-14] Contract test for the live preview wiring:
// the Studio fetches the DRAFT preview-projection and feeds it to the (frozen)
// KioskWizardComponent READ-ONLY (onAddToCart no-op). The frozen wizard is stubbed
// here — the no-cart-write guarantee is structural (we assert a function onAddToCart
// is wired); a deeper "no kioskCart/* dispatch" assertion belongs to a kiosk-wizard spec.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import axios from 'axios';
import WizardStudioComponent from '../../resources/js/components/admin/items/composer/WizardStudioComponent.vue';

const ITEM = { id: 41, name: 'Bowl Frites Poulet mariné' };
const PROFILE = { id: 8, item_id: 41, template: 'custom', is_published: false, version: 2, steps: [{ id: 1, label: 'Sauce', position: 0, min_select: 1, max_select: 2 }] };

const draftProjection = (steps) => ({
    data: { data: { item: { id: 41, name: ITEM.name, price: 8.9, composer_profile: { id: 8, item_id: 41, is_published: true, version: 2, steps } } } },
});

function wireAxios({ steps }) {
    axios.get.mockImplementation((url) => {
        if (url.includes('item/show')) return Promise.resolve({ data: ITEM });
        if (url.includes('preview-projection')) return Promise.resolve(draftProjection(steps));
        if (url.includes('/profile')) return Promise.resolve({ data: PROFILE });
        return Promise.reject(new Error(`unexpected url ${url}`));
    });
}

// Stub the frozen kiosk wizard by its registered name (no real lazy import, no template
// compilation, no module-mock proxy) — we assert on the Studio's own vm/DOM, not the wizard.
const STUBS = { KioskWizardComponent: true };

const mountStudio = () => mount(WizardStudioComponent, {
    props: { entityType: 'item', entityId: 41 },
    global: {
        stubs: STUBS,
        mocks: { $router: { push: vi.fn(), back: vi.fn() } },
    },
});

afterEach(() => vi.clearAllMocks());

describe('WizardStudio live preview (W1)', () => {
    it('fetches the draft preview-projection for the loaded profile', async () => {
        wireAxios({ steps: [{ id: 1, step_key: 'sauce', label: 'Quelle sauce ?', choices: [{ id: 1, name: 'Spicy' }] }] });
        mountStudio();
        await flushPromises();
        const calls = axios.get.mock.calls.map((c) => c[0]);
        expect(calls.some((u) => u === 'admin/composer/profiles/8/preview-projection')).toBe(true);
    });

    it('mounts the frozen kiosk wizard fed the draft, read-only (onAddToCart is a no-op function)', async () => {
        wireAxios({ steps: [{ id: 1, step_key: 'sauce', label: 'Quelle sauce ?', choices: [{ id: 1, name: 'Spicy' }] }] });
        const wrapper = mountStudio();
        await flushPromises();
        // The draft is fetched and shaped for the live wizard (server forces is_published true).
        expect(wrapper.vm.draftItem.composer_profile.is_published).toBe(true);
        expect(wrapper.vm.draftItem.composer_profile.steps.length).toBe(1);
        // The wizard branch is active (neither loading nor empty placeholder is shown) → the
        // template mounts <KioskWizardComponent :item="draftItem" :on-add-to-cart="noop" ...>.
        expect(wrapper.find('[data-testid="wizard-studio-preview-loading"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="wizard-studio-preview-empty"]').exists()).toBe(false);
        // Read-only guard: onAddToCart is wired to a no-op → frozen wizard short-circuits cart writes.
        expect(typeof wrapper.vm.noop).toBe('function');
        expect(wrapper.vm.noop()).toBeUndefined();
    });

    it('warns when a step resolves to zero options + labels category inheritance', async () => {
        // draft with one normal step + one step that has NO choices (misconfiguration).
        axios.get.mockImplementation((url) => {
            if (url.includes('item-category/show') || url.includes('item/show')) return Promise.resolve({ data: { name: 'Sandwich Cayenne' } });
            if (url.includes('preview-projection')) return Promise.resolve({
                data: { data: { item: { id: 41, name: ITEM.name, price: 8.9, composer_profile: { id: 8, item_id: null, item_category_id: 6, is_published: true, version: 2, steps: [
                    { id: 1, step_key: 'sauce', label: 'Quelle sauce ?', is_active: true, choices: [{ id: 1, name: 'Spicy' }] },
                    { id: 2, step_key: 'viande', label: 'Quelle viande ?', is_active: true, choices: [] },
                ] } } } },
            });
            if (url.includes('/profile')) return Promise.resolve({ data: { ...PROFILE, item_id: null, item_category_id: 6 } });
            return Promise.reject(new Error(`unexpected url ${url}`));
        });
        const wrapper = mount(WizardStudioComponent, {
            props: { entityType: 'category', entityId: 6 },
            global: { stubs: STUBS, mocks: { $router: { push: vi.fn(), back: vi.fn() } } },
        });
        await flushPromises();
        // operator-safety computeds: zero-choice step detected (drives the DOM warning banner
        // via v-if) + category inheritance is made explicit in the header.
        expect(wrapper.vm.draftItem).not.toBe(null);
        expect(wrapper.vm.zeroChoiceSteps).toContain('Quelle viande ?');
        expect(wrapper.vm.zeroChoiceSteps).not.toContain('Quelle sauce ?');
        expect(wrapper.vm.inheritanceLabel).toContain('catégorie');
    });

    it('shows the empty state (no wizard mount) when the draft has no steps', async () => {
        wireAxios({ steps: [] });
        const wrapper = mountStudio();
        await flushPromises();
        expect(wrapper.findComponent({ name: 'KioskWizardComponent' }).exists()).toBe(false);
        expect(wrapper.find('[data-testid="wizard-studio-preview-empty"]').exists()).toBe(true);
    });
});
