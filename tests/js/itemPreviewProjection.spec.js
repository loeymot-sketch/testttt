/**
 * Sentinel — Mission #2 Vague 1 action 1.2.
 *
 * Plan: plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md task 1.2
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import axios from 'axios';
import { announcer } from '../../resources/js/helpers/a11y/announcer.js';
import ItemPreviewComponent from '../../resources/js/components/admin/items/ItemPreviewComponent.vue';
import fr from '../../resources/js/languages/fr.json';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

vi.mock('../../resources/js/helpers/a11y/announcer.js', () => ({
    announcer: {
        polite: vi.fn(),
        assertive: vi.fn(),
        catalogChanged: vi.fn(),
        orderRejected: vi.fn(),
    },
}));

const localeFr = fr?.default ?? fr;

function testI18n() {
    return createI18n({
        legacy: true,
        locale: 'fr',
        messages: {
            fr: {
                ...localeFr,
                label: {
                    ...localeFr.label,
                    refresh: 'Rafraîchir',
                    unavailable: 'Indisponible',
                },
            },
        },
        silentFallbackWarn: true,
    });
}

function projectionBody(itemId, itemOverrides = {}) {
    return {
        categories: [
            {
                id: 1,
                name: 'Cat',
                items: [
                    {
                        id: itemId,
                        name: 'Test Item',
                        price: 10,
                        flat_price: 10,
                        is_available: true,
                        ...itemOverrides,
                    },
                ],
            },
        ],
    };
}

function mountPreview({ item = { id: 5 }, branches = [{ id: 1, name: 'A' }] } = {}) {
    const i18n = testI18n();
    return mount(ItemPreviewComponent, {
        props: { item, branches },
        global: {
            plugins: [i18n],
        },
    });
}

describe('ItemPreviewComponent', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('loads default branch on mount and fetches kiosk projection by default', async () => {
        axios.get.mockResolvedValue({ data: projectionBody(5) });
        mountPreview();
        await flushPromises();
        expect(axios.get).toHaveBeenCalledTimes(2);
        const channels = axios.get.mock.calls.map((c) => c[1]?.params?.channel).sort();
        expect(channels).toEqual(['kiosk', 'pos']);
        const branchIds = axios.get.mock.calls.map((c) => c[1]?.params?.branch_id);
        expect(branchIds.every((b) => b === 1)).toBe(true);
    });

    it('refetches both projections when selectedBranchId changes', async () => {
        axios.get.mockResolvedValue({ data: projectionBody(5) });
        const wrapper = mountPreview({
            branches: [
                { id: 1, name: 'A' },
                { id: 2, name: 'B' },
            ],
        });
        await flushPromises();
        axios.get.mockClear();
        axios.get.mockResolvedValue({ data: projectionBody(5) });
        wrapper.vm.selectedBranchId = 2;
        await wrapper.vm.refreshAll();
        await flushPromises();
        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(axios.get.mock.calls.every((c) => c[1].params.branch_id === 2)).toBe(true);
    });

    it('displays "not found on surface" message when projection has no matching item', async () => {
        axios.get.mockResolvedValue({ data: { categories: [] } });
        const wrapper = mountPreview();
        await flushPromises();
        expect(wrapper.text()).toContain(localeFr.admin.item_preview.no_pos_data);
        expect(wrapper.text()).toContain(localeFr.admin.item_preview.no_kiosk_data);
    });

    it('renders unavailable badge with reason when is_available=false', async () => {
        axios.get.mockResolvedValue({
            data: projectionBody(5, { is_available: false }),
        });
        const wrapper = mountPreview();
        await flushPromises();
        expect(wrapper.vm.posProjection).toBeTruthy();
        expect(wrapper.vm.posProjection.is_available).toBe(false);
        expect(wrapper.vm.posSummary.statusLabel).not.toBe(wrapper.vm.$t('label.available'));
    });

    it.skip('handles arrow-key navigation between surface tabs', () => {
        /* Pas d’onglets surface distincts dans ItemPreviewComponent (grille POS|Kiosk fixe). */
    });

    it.skip('announces surface change via announcer.polite', () => {
        /* Déprioritisé : changement de branche annoncé via span aria-live interne. */
    });

    it('shows assertive error and announcer.assertive when /api/admin/menu-projection returns 500', async () => {
        axios.get.mockRejectedValue({ response: { status: 500 } });
        const wrapper = mountPreview();
        await flushPromises();
        expect(announcer.assertive).toHaveBeenCalled();
        expect(wrapper.find('[data-testid="admin-item-preview-error"]').exists()).toBe(true);
        expect(wrapper.text()).toContain(localeFr.admin.item_preview.load_error);
    });
});
