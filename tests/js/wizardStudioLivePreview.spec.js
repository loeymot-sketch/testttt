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
// draggable: render-fn passthrough (renders its slot rows without sortablejs/template compiler).
const STUBS = {
    KioskWizardComponent: true,
    draggable: { inheritAttrs: false, emits: ['end', 'update:modelValue'], render() { return this.$slots.default ? this.$slots.default() : null; } },
};

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

// [WIZARD-STUDIO W2 2026-06-15] EDIT pillar: page CRUD/reorder via bulk PUT + live refresh.
const CAT_PROFILE = { id: 8, item_id: null, item_category_id: 6, template: 'custom', is_published: false, version: 2, steps: [
    { id: 1, step_key: 'pain', label: 'Pain', position: 0, min_select: 1, max_select: 1 },
    { id: 2, step_key: 'sauce', label: 'Sauce', position: 1, min_select: 1, max_select: 2 },
] };

function wireCategoryEdit() {
    axios.get.mockImplementation((url) => {
        if (url.includes('item-category/show')) return Promise.resolve({ data: { name: 'Sandwich Cayenne' } });
        if (url.includes('preview-projection')) return Promise.resolve(draftProjection([{ id: 1, step_key: 'pain', label: 'Pain', is_active: true, choices: [{ id: 1, name: 'Galette' }] }]));
        if (url.includes('/profile')) return Promise.resolve({ data: CAT_PROFILE });
        return Promise.reject(new Error(`unexpected url ${url}`));
    });
    // bulk PUT echoes back a bumped profile so the component re-hydrates.
    axios.put.mockImplementation((url, payload) => Promise.resolve({
        data: { ...CAT_PROFILE, version: (payload.version || 2) + 1, steps: payload.steps.map((s, i) => ({ ...s, id: 100 + i })) },
    }));
}

const mountCat = () => mount(WizardStudioComponent, {
    props: { entityType: 'category', entityId: 6 },
    global: { stubs: STUBS, mocks: { $router: { push: vi.fn(), back: vi.fn() } } },
});

describe('WizardStudio edit (W2)', () => {
    it('addPage appends a step, bulk-PUTs the profile (no price field), and refreshes the preview', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        const previewCallsBefore = axios.get.mock.calls.filter((c) => String(c[0]).includes('preview-projection')).length;
        expect(wrapper.vm.steps.length).toBe(2);

        await wrapper.vm.addPage();
        await flushPromises();

        // one bulk PUT to the profile endpoint
        expect(axios.put).toHaveBeenCalledTimes(1);
        const [url, payload] = axios.put.mock.calls[0];
        expect(url).toBe('admin/composer/profiles/8');
        expect(payload.steps.length).toBe(3);
        expect(payload.version).toBe(2);
        // NF525: no price on any step payload
        expect(JSON.stringify(payload.steps)).not.toMatch(/"price"|"amount"|"unit_price"/);
        // preview refreshed after save
        const previewCallsAfter = axios.get.mock.calls.filter((c) => String(c[0]).includes('preview-projection')).length;
        expect(previewCallsAfter).toBe(previewCallsBefore + 1);
        // re-hydrated from server (version bumped)
        expect(wrapper.vm.version).toBe(3);
    });

    it('removePage drops the step and bulk-PUTs the shorter list', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        await wrapper.vm.removePage(wrapper.vm.steps[0]);
        await flushPromises();
        expect(axios.put).toHaveBeenCalledTimes(1);
        expect(axios.put.mock.calls[0][1].steps.length).toBe(1);
    });

    it('onReorder persists new positions (0,1,2…) via bulk PUT', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        // simulate draggable having reordered the array
        wrapper.vm.steps = [wrapper.vm.steps[1], wrapper.vm.steps[0]];
        await wrapper.vm.onReorder();
        await flushPromises();
        const payload = axios.put.mock.calls[0][1];
        expect(payload.steps.map((s) => s.position)).toEqual([0, 1]);
        expect(payload.steps[0].step_key).toBe('sauce'); // the formerly-second step is now first
    });

    it('surfaces a 409 version conflict instead of silently losing edits', async () => {
        wireCategoryEdit();
        axios.put.mockRejectedValueOnce({ response: { status: 409, data: { message: 'conflict' } } });
        const wrapper = mountCat();
        await flushPromises();
        await wrapper.vm.addPage();
        await flushPromises();
        expect(wrapper.vm.conflictDetected).toBe(true);
    });
});
