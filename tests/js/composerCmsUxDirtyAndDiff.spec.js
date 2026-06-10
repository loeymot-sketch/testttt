import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// ── ProductComposerEditorComponent : CMS-UX-1 dirty guard ────────────────────

const alertServiceMock = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));

vi.mock('axios', () => ({
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));
vi.mock('../../resources/js/services/alertService', () => ({ default: alertServiceMock }));
vi.mock('vue-draggable-next', () => ({
    VueDraggableNext: {
        name: 'draggable',
        props: ['modelValue'],
        emits: ['update:modelValue', 'end'],
        template: '<div data-testid="draggable"><slot /></div>',
    },
}));

import axios from 'axios';
import ProductComposerEditorComponent from '../../resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue';
import ComposerPublishDiffModal from '../../resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue';

const item = { id: 7, name: 'Tacos XL', category_name: 'Tacos', preview: '/uploads/tacos.jpg' };
const sources = {
    item_id: 7,
    item_attribute: [{ id: 5, name: 'Viande', source_type: 'item_attribute' }],
    extra_group: [{ id: 'sauces', name: 'Sauces extras', source_type: 'extra_group' }],
    addon: [{ id: 88, name: 'Boisson', source_type: 'addon' }],
};

function profile(overrides = {}) {
    return {
        id: 55, item_id: 7, template: 'custom', is_published: false, branch_id_scope: null, version: 3,
        steps: [
            { id: 101, profile_id: 55, step_key: 'viande', label: 'Viande', source_type: 'item_attribute', source_ref: '5', min_select: 1, max_select: 1, visible_on: ['pos', 'kiosk'], position: 0, is_active: true },
        ],
        ...overrides,
    };
}

const t = (key) => ({
    'label.composer.new_page': 'Nouvelle page',
    'label.composer.unsaved_changes': 'Modifications non sauvegardées',
    'message.composer.unsaved_leave_confirm': 'Quitter sans sauvegarder ?',
}[key] || key);

function primeAxios({ profilePayload = profile() } = {}) {
    axios.get.mockImplementation((url) => {
        if (url === 'admin/item/show/7') return Promise.resolve({ data: { data: item } });
        if (url === 'admin/composer/items/7/profile') return Promise.resolve({ data: { data: profilePayload } });
        if (url === 'admin/composer/items/7/available-sources') return Promise.resolve({ data: { data: sources } });
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
        getters: { 'backendGlobalState/branches': [{ id: 1, name: 'Paris Centre' }, { id: 2, name: 'Lyon' }] },
    };
    const routerPush = vi.fn();
    const wrapper = mount(ProductComposerEditorComponent, {
        props: { itemId: 7 },
        global: {
            stubs: {
                ItemPreviewComponent: { name: 'ItemPreviewComponent', template: '<section></section>', methods: { refreshAll: vi.fn() } },
            },
            mocks: { $t: t, $store: store, $router: { push: routerPush } },
        },
    });
    await flushPromises();
    await flushPromises();
    return { wrapper, store, routerPush };
}

describe('CMS-UX-1 — composer unsaved-changes guard', () => {
    beforeEach(() => { vi.clearAllMocks(); });

    it('n_affiche PAS le badge dirty juste après le chargement (état propre)', async () => {
        const { wrapper } = await mountEditor();
        expect(wrapper.vm.isDirty).toBe(false);
        expect(wrapper.find('[data-testid="admin-composer-dirty-badge"]').exists()).toBe(false);
    });

    it('affiche le badge dirty dès qu_une page est modifiée', async () => {
        const { wrapper } = await mountEditor();
        await wrapper.find('[data-testid="admin-composer-add-step"]').trigger('click');
        await flushPromises();
        expect(wrapper.vm.isDirty).toBe(true);
        const badge = wrapper.find('[data-testid="admin-composer-dirty-badge"]');
        expect(badge.exists()).toBe(true);
        expect(badge.text()).toContain('Modifications non sauvegardées');
    });

    it('redevient propre après saveDraft (snapshot rebaselined)', async () => {
        const { wrapper } = await mountEditor();
        await wrapper.find('[data-testid="admin-composer-add-step"]').trigger('click');
        await flushPromises();
        expect(wrapper.vm.isDirty).toBe(true);
        // saveDraft re-hydrates with the saved payload → markClean.
        axios.put.mockResolvedValueOnce({ data: { data: { ...profile(), version: 4, steps: wrapper.vm.steps.map((s, i) => ({ ...s, id: s.id ?? 900 + i, position: i })) } } });
        await wrapper.vm.saveDraft();
        await flushPromises();
        await flushPromises();
        expect(wrapper.vm.isDirty).toBe(false);
    });

    it('returnToItem demande confirmation quand dirty et ANNULE la nav si refusé', async () => {
        const { wrapper, routerPush } = await mountEditor();
        await wrapper.find('[data-testid="admin-composer-add-step"]').trigger('click');
        await flushPromises();
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        await wrapper.find('[data-testid="admin-composer-back"]').trigger('click');
        expect(confirmSpy).toHaveBeenCalledOnce();
        expect(routerPush).not.toHaveBeenCalled();
        confirmSpy.mockRestore();
    });

    it('returnToItem navigue sans confirmation quand l_état est propre', async () => {
        const { wrapper, routerPush } = await mountEditor();
        const confirmSpy = vi.spyOn(window, 'confirm');
        await wrapper.find('[data-testid="admin-composer-back"]').trigger('click');
        expect(confirmSpy).not.toHaveBeenCalled();
        expect(routerPush).toHaveBeenCalledOnce();
        confirmSpy.mockRestore();
    });

    it('beforeRouteLeave bloque la sortie quand dirty et refusé', async () => {
        const { wrapper } = await mountEditor();
        await wrapper.find('[data-testid="admin-composer-add-step"]').trigger('click');
        await flushPromises();
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const next = vi.fn();
        wrapper.vm.$options.beforeRouteLeave.call(wrapper.vm, {}, {}, next);
        expect(next).toHaveBeenCalledWith(false);
        confirmSpy.mockRestore();
    });
});

// ── ComposerPublishDiffModal : CMS-UX-2 value-by-value diff ───────────────────

function modalDiff(overrides = {}) {
    return { is_clean: false, added: [], removed: [], modified: [], ...overrides };
}

function mountModal(props = {}) {
    return mount(ComposerPublishDiffModal, {
        props: { profileId: 42, isOpen: true, ...props },
        global: {
            mocks: {
                $t: (key) => ({
                    'studio.composer.diff.before': 'Avant',
                    'studio.composer.diff.after': 'Après',
                    'studio.composer.diff.empty_value': '—',
                    'studio.composer.diff.yes': 'Oui',
                    'studio.composer.diff.no': 'Non',
                    'studio.composer.diff.fields.label': 'Titre de la page',
                    'studio.composer.diff.fields.max_select': 'Sélection maximale',
                    'studio.composer.diff.fields.visible_on': 'Visible sur',
                    'studio.composer.diff.fields.is_active': 'Page active',
                    'label.composer.visible_pos': 'Caisse (POS)',
                    'label.composer.visible_kiosk': 'Borne (Kiosk)',
                }[key] || key),
            },
        },
    });
}

describe('CMS-UX-2 — value-by-value publish diff', () => {
    beforeEach(() => { vi.clearAllMocks(); });

    it('rend une ligne par champ modifié avec ancienne → nouvelle valeur', async () => {
        axios.get.mockResolvedValueOnce({
            data: modalDiff({
                modified: [{
                    step_key: 'viande',
                    changed_fields: ['label', 'max_select'],
                    before: { label: 'Viande', max_select: 1 },
                    after: { label: 'Choix viande', max_select: 2 },
                }],
            }),
        });
        const wrapper = mountModal();
        await flushPromises();
        const table = wrapper.find('[data-testid="diff-field-table-viande"]');
        expect(table.exists()).toBe(true);
        const labelRow = wrapper.find('[data-testid="diff-field-row-viande-label"]');
        expect(labelRow.text()).toContain('Titre de la page');
        expect(labelRow.find('del').text()).toBe('Viande');
        expect(labelRow.find('ins').text()).toBe('Choix viande');
        const maxRow = wrapper.find('[data-testid="diff-field-row-viande-max_select"]');
        expect(maxRow.find('del').text()).toBe('1');
        expect(maxRow.find('ins').text()).toBe('2');
    });

    it('formate booléens en Oui/Non et visible_on en surfaces lisibles', async () => {
        axios.get.mockResolvedValueOnce({
            data: modalDiff({
                modified: [{
                    step_key: 'sauce',
                    changed_fields: ['is_active', 'visible_on'],
                    before: { is_active: true, visible_on: ['pos', 'kiosk'] },
                    after: { is_active: false, visible_on: ['kiosk'] },
                }],
            }),
        });
        const wrapper = mountModal();
        await flushPromises();
        const activeRow = wrapper.find('[data-testid="diff-field-row-sauce-is_active"]');
        expect(activeRow.find('del').text()).toBe('Oui');
        expect(activeRow.find('ins').text()).toBe('Non');
        const visRow = wrapper.find('[data-testid="diff-field-row-sauce-visible_on"]');
        expect(visRow.find('del').text()).toBe('Caisse (POS), Borne (Kiosk)');
        expect(visRow.find('ins').text()).toBe('Borne (Kiosk)');
    });

    it('affiche — pour les valeurs vides/null', async () => {
        axios.get.mockResolvedValueOnce({
            data: modalDiff({
                modified: [{
                    step_key: 'extra',
                    changed_fields: ['label'],
                    before: { label: '' },
                    after: { label: 'Extras' },
                }],
            }),
        });
        const wrapper = mountModal();
        await flushPromises();
        const row = wrapper.find('[data-testid="diff-field-row-extra-label"]');
        expect(row.find('del').text()).toBe('—');
        expect(row.find('ins').text()).toBe('Extras');
    });

    it('NF525 — aucun champ prix n_est jamais rendu dans le diff', async () => {
        axios.get.mockResolvedValueOnce({
            data: modalDiff({
                // Even if the backend ever leaked a price-ish field, the renderer only
                // walks changed_fields and maps them to the fixed FR field labels.
                modified: [{
                    step_key: 'viande',
                    changed_fields: ['label'],
                    before: { label: 'Viande', price: 4.5, amount: 12 },
                    after: { label: 'Choix viande', price: 9.9, amount: 30 },
                }],
            }),
        });
        const wrapper = mountModal();
        await flushPromises();
        const text = wrapper.text();
        expect(text).not.toContain('4.5');
        expect(text).not.toContain('9.9');
        expect(text).not.toMatch(/€|prix|price|montant|amount/i);
    });
});
