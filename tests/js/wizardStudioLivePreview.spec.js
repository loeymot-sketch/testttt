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

const SOURCES = {
    item_attribute: [{ id: 7, name: 'Pain', source_type: 'item_attribute', source_ref: 'Pain' }, { id: 9, name: 'Sauce', source_type: 'item_attribute', source_ref: 'Sauce' }],
    extra_group: [{ id: 'supplement', name: 'supplement', source_type: 'extra_group', source_ref: 'supplement', count: 3 }],
    addon: [{ id: 'drink', name: 'Boisson', source_type: 'addon', source_ref: 'drink', addon_role: 'drink' }],
};

function wireCategoryEdit() {
    axios.get.mockImplementation((url) => {
        if (url.includes('item-category/show')) return Promise.resolve({ data: { name: 'Sandwich Cayenne' } });
        if (url.includes('available-sources')) return Promise.resolve({ data: { data: { category_id: 6, ...SOURCES } } });
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

describe('WizardStudio selection rules (W3)', () => {
    const lastPutSteps = () => axios.put.mock.calls.at(-1)[1].steps;

    it('single↔multi toggles max_select and persists', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        const sauce = wrapper.vm.steps.find((s) => s.step_key === 'sauce'); // starts min1 max2 (multi)
        expect(wrapper.vm.isMulti(sauce)).toBe(true);

        await wrapper.vm.setChoiceType(sauce, 'single');
        await flushPromises();
        expect(sauce.max_select).toBe(1);
        expect(lastPutSteps().find((s) => s.step_key === 'sauce').max_select).toBe(1);

        await wrapper.vm.setChoiceType(sauce, 'multi');
        await flushPromises();
        expect(sauce.max_select).toBeGreaterThanOrEqual(2);
    });

    it('required toggle sets/clears min_select and keeps min ≤ max', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        const pain = wrapper.vm.steps.find((s) => s.step_key === 'pain'); // min1 max1
        await wrapper.vm.setRequired(pain, false);
        await flushPromises();
        expect(pain.min_select).toBe(0);
        await wrapper.vm.setRequired(pain, true);
        await flushPromises();
        expect(pain.min_select).toBeGreaterThanOrEqual(1);
        expect(pain.max_select).toBeGreaterThanOrEqual(pain.min_select);
    });

    it('setBound enforces min ≤ max coherence and persists', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        const sauce = wrapper.vm.steps.find((s) => s.step_key === 'sauce');
        await wrapper.vm.setBound(sauce, 'min_select', '5'); // min 5 > max 2 → max bumped to 5
        await flushPromises();
        expect(sauce.min_select).toBe(5);
        expect(sauce.max_select).toBeGreaterThanOrEqual(5);
        const put = lastPutSteps().find((s) => s.step_key === 'sauce');
        expect(put.min_select).toBe(5);
        expect(put.max_select).toBeGreaterThanOrEqual(5);
    });

    it('repeat toggle persists allow_repeat (NF525: still no price on the step)', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        const sauce = wrapper.vm.steps.find((s) => s.step_key === 'sauce');
        await wrapper.vm.setRepeat(sauce, true);
        await flushPromises();
        expect(sauce.allow_repeat).toBe(true);
        const put = lastPutSteps().find((s) => s.step_key === 'sauce');
        expect(put.allow_repeat).toBe(true);
        expect(JSON.stringify(lastPutSteps())).not.toMatch(/"price"|"amount"/);
    });

    it('toggleRule opens then closes the rule editor', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        const s = wrapper.vm.steps[0];
        expect(wrapper.vm.expandedUid).toBe(null);
        wrapper.vm.toggleRule(s);
        expect(wrapper.vm.expandedUid).toBe(s._uid);
        wrapper.vm.toggleRule(s);
        expect(wrapper.vm.expandedUid).toBe(null);
    });
});

describe('WizardStudio source binding (W6)', () => {
    it('loads category sources and exposes them as flattened options', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        const calls = axios.get.mock.calls.map((c) => c[0]);
        expect(calls.some((u) => u === 'admin/composer/categories/6/available-sources')).toBe(true);
        const keys = wrapper.vm.sourceOptions.map((o) => o.key);
        expect(keys).toContain('item_attribute:Sauce');
        expect(keys).toContain('extra_group:supplement');
        expect(keys).toContain('addon:drink');
    });

    it('setSource binds the page to a real source (type+ref+role) and persists', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        const pain = wrapper.vm.steps.find((s) => s.step_key === 'pain');
        await wrapper.vm.setSource(pain, 'addon:drink');
        await flushPromises();
        expect(pain.source_type).toBe('addon');
        expect(pain.source_ref).toBe('drink');
        expect(pain.addon_role).toBe('drink');
        const put = axios.put.mock.calls.at(-1)[1].steps.find((s) => s.step_key === 'pain');
        expect(put.source_type).toBe('addon');
        expect(put.source_ref).toBe('drink');
        // turnkey: source_ref is no longer '' → the page can resolve real options
        expect(put.source_ref).not.toBe('');
    });
});

describe('WizardStudio adversarial heals (WS-4 / WS-5)', () => {
    it('WS-4: keyboard movePage reorders + persists 0-based positions', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        const first = wrapper.vm.steps[0].step_key; // 'pain'
        await wrapper.vm.movePage(wrapper.vm.steps[0], 1); // move pain down
        await flushPromises();
        const payload = axios.put.mock.calls.at(-1)[1];
        expect(payload.steps.map((s) => s.position)).toEqual([0, 1]);
        expect(payload.steps[1].step_key).toBe(first); // pain is now second
    });

    it('WS-5: an edit during an in-flight save is queued (not dropped) and not lost', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        axios.put.mockClear();
        // simulate a save in flight
        wrapper.vm.savingDraft = true;
        await wrapper.vm.saveStudioDraft(); // should NOT issue a PUT, should queue
        expect(axios.put).not.toHaveBeenCalled();
        expect(wrapper.vm._pendingSave).toBe(true);
    });

    it('STATE-01: an edit made during an in-flight save is PERSISTED by the trailing flush (not clobbered by the stale echo)', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        axios.put.mockClear();
        // Hold the FIRST PUT in flight so a second edit can land while it runs. It echoes the
        // ORIGINAL labels (snapshot of the first save), exactly like the real backend would.
        let resolveFirst;
        const firstEcho = { data: { ...CAT_PROFILE, version: 50, steps: CAT_PROFILE.steps.map((s, i) => ({ ...s, id: 200 + i })) } };
        axios.put.mockImplementationOnce(() => new Promise((res) => { resolveFirst = () => res(firstEcho); }));
        // Trailing PUT echoes whatever payload it is sent.
        axios.put.mockImplementation((url, payload) => Promise.resolve({ data: { ...CAT_PROFILE, version: 51, steps: payload.steps.map((s, i) => ({ ...s, id: 300 + i })) } }));

        const p1 = wrapper.vm.saveStudioDraft(); // in flight (not awaited)
        wrapper.vm.steps[0].label = 'PENDING-EDIT'; // operator edits DURING the save
        wrapper.vm.saveStudioDraft(); // coalesced → queued
        expect(wrapper.vm._pendingSave).toBe(true);

        resolveFirst();
        await p1;
        await flushPromises();
        await flushPromises();

        expect(axios.put).toHaveBeenCalledTimes(2); // in-flight save + trailing flush
        // The trailing PUT must carry the operator's pending edit (the bug: stale echo overwrote it).
        expect(axios.put.mock.calls[1][1].steps[0].label).toBe('PENDING-EDIT');
        // …and local state must still reflect the pending edit, not the first save's server copy.
        expect(wrapper.vm.steps[0].label).toBe('PENDING-EDIT');
    });

    it('STATE-05: a non-409 save failure blocks further saves (no silent re-commit of divergent state) until reload', async () => {
        wireCategoryEdit();
        const wrapper = mountCat();
        await flushPromises();
        axios.put.mockClear();
        axios.put.mockRejectedValueOnce({ response: { status: 500, data: { message: 'boom' } } });

        await wrapper.vm.addPage(); // optimistic local mutation + a PUT that fails 500
        await flushPromises();
        expect(wrapper.vm.saveFailed).toBe(true);
        expect(wrapper.vm.conflictDetected).toBe(false); // distinct from the 409 path
        const putsAfterFail = axios.put.mock.calls.length; // 1

        // A subsequent edit must NOT silently re-PUT the divergent local state.
        await wrapper.vm.addPage();
        await flushPromises();
        expect(axios.put.mock.calls.length).toBe(putsAfterFail); // still blocked → no second PUT

        // Reloading restores server truth and unblocks saving.
        await wrapper.vm.reloadAll();
        await flushPromises();
        expect(wrapper.vm.saveFailed).toBe(false);
    });

    it('STATE-04: togglePublish bails while a save is QUEUED (_pendingSave), closing the microtask window', async () => {
        wireCategoryEdit();
        let publishCalled = false;
        axios.post.mockImplementation((url) => {
            if (url.endsWith('/publish')) { publishCalled = true; return Promise.resolve({ data: { ...CAT_PROFILE, is_published: true, version: 3 } }); }
            return Promise.resolve({ data: CAT_PROFILE });
        });
        const wrapper = mountCat();
        await flushPromises();
        wrapper.vm._pendingSave = true; // a trailing save is queued though savingDraft already cleared
        await wrapper.vm.togglePublish();
        await flushPromises();
        expect(publishCalled).toBe(false);
        expect(wrapper.vm.isPublished).toBe(false);
    });

    it('W8: createStudioProfile POSTs to the category endpoint and sets the new profile', async () => {
        // category with NO profile yet (profile fetch 404 → null)
        axios.get.mockImplementation((url) => {
            if (url.includes('item-category/show')) return Promise.resolve({ data: { name: 'Nouvelle Cat' } });
            if (url.includes('available-sources')) return Promise.resolve({ data: { data: { category_id: 6, ...SOURCES } } });
            if (url.includes('preview-projection')) return Promise.resolve(draftProjection([]));
            if (url.includes('/profile')) return Promise.reject({ response: { status: 404 } });
            return Promise.reject(new Error(`unexpected ${url}`));
        });
        axios.post.mockResolvedValueOnce({ data: { id: 99, item_id: null, item_category_id: 6, template: 'custom', is_published: false, version: 1, steps: [] } });
        const wrapper = mountCat();
        await flushPromises();
        expect(wrapper.vm.hasProfile).toBe(false); // create CTA state
        await wrapper.vm.createStudioProfile();
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith('admin/composer/categories/6/profile', expect.objectContaining({ template: 'custom', steps: [] }));
        expect(wrapper.vm.hasProfile).toBe(true);
        expect(wrapper.vm.profile.id).toBe(99);
    });

    it('W8: togglePublish publishes a draft then unpublishes (live ⇄ draft workflow)', async () => {
        wireCategoryEdit(); // CAT_PROFILE is a draft (is_published false)
        axios.post.mockImplementation((url) => {
            if (url.endsWith('/publish')) return Promise.resolve({ data: { ...CAT_PROFILE, is_published: true, version: 3 } });
            if (url.endsWith('/unpublish')) return Promise.resolve({ data: { ...CAT_PROFILE, is_published: false, version: 4 } });
            return Promise.resolve({ data: CAT_PROFILE });
        });
        const wrapper = mountCat();
        await flushPromises();
        expect(wrapper.vm.isPublished).toBe(false);
        await wrapper.vm.togglePublish(); // publish
        await flushPromises();
        expect(axios.post.mock.calls.some((c) => String(c[0]).endsWith('/publish'))).toBe(true);
        expect(wrapper.vm.isPublished).toBe(true);
        await wrapper.vm.togglePublish(); // unpublish
        await flushPromises();
        expect(axios.post.mock.calls.some((c) => String(c[0]).endsWith('/unpublish'))).toBe(true);
        expect(wrapper.vm.isPublished).toBe(false);
    });

    it('WS-8race: togglePublish is a no-op while a draft save is in flight (version race guard)', async () => {
        wireCategoryEdit(); // CAT_PROFILE is a draft (is_published false)
        let publishCalled = false;
        axios.post.mockImplementation((url) => {
            if (url.endsWith('/publish')) { publishCalled = true; return Promise.resolve({ data: { ...CAT_PROFILE, is_published: true, version: 3 } }); }
            return Promise.resolve({ data: CAT_PROFILE });
        });
        const wrapper = mountCat();
        await flushPromises();
        wrapper.vm.savingDraft = true; // a bulk-PUT is in flight (will bump version)
        await wrapper.vm.togglePublish(); // must bail — publishing now would 409 on a stale version
        await flushPromises();
        expect(publishCalled).toBe(false);
        expect(wrapper.vm.isPublished).toBe(false); // unchanged
    });

    it('W8: a 422 assertPublishable error is surfaced (cannot publish a broken wizard)', async () => {
        wireCategoryEdit();
        axios.post.mockImplementation((url) => (url.endsWith('/publish')
            ? Promise.reject({ response: { status: 422, data: { errors: { steps: ['Une page obligatoire est vide.'] } } } })
            : Promise.resolve({ data: CAT_PROFILE })));
        const wrapper = mountCat();
        await flushPromises();
        await wrapper.vm.togglePublish();
        await flushPromises();
        expect(wrapper.vm.publishError).toContain('obligatoire');
        expect(wrapper.vm.isPublished).toBe(false); // not flipped on failure
    });

    it('W4c: optionsForStep surfaces the live projected choices (name+thumb) for a page', async () => {
        // wire a preview whose 'pain' step resolves two real choices
        axios.get.mockImplementation((url) => {
            if (url.includes('item-category/show')) return Promise.resolve({ data: { name: 'Sandwich Cayenne' } });
            if (url.includes('available-sources')) return Promise.resolve({ data: { data: { category_id: 6, ...SOURCES } } });
            if (url.includes('preview-projection')) return Promise.resolve(draftProjection([
                { id: 1, step_key: 'pain', label: 'Pain', is_active: true, choices: [
                    { id: 11, name: 'Galette', thumb: '/storage/g.png', is_available: true },
                    { id: 12, name: 'Pain brioché', thumb: null, is_available: false, unavailable_reason: 'ingredient_rupture' },
                ] },
            ]));
            if (url.includes('/profile')) return Promise.resolve({ data: CAT_PROFILE });
            return Promise.reject(new Error(`unexpected url ${url}`));
        });
        const wrapper = mountCat();
        await flushPromises();
        const pain = wrapper.vm.steps.find((s) => s.step_key === 'pain');
        const opts = wrapper.vm.optionsForStep(pain);
        expect(opts.map((o) => o.name)).toEqual(['Galette', 'Pain brioché']);
        expect(opts[0].thumb).toBe('/storage/g.png');
        expect(opts[1].is_available).toBe(false);
        // an unbound/unknown step → no options
        expect(wrapper.vm.optionsForStep({ step_key: 'nope' })).toEqual([]);
    });
});
