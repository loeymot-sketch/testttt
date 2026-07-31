import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';

// [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Modale caisse de sortie de stock hors-vente.

const mockGet = vi.fn();
const mockPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) } }));

import PosStockOutflowModal from '../../resources/js/components/admin/pos/PosStockOutflowModal.vue';

let w = null;
const mountOpen = async (items = [], recent = []) => {
    mockGet.mockImplementation((url) => {
        if (String(url).includes('items')) return Promise.resolve({ data: { data: items } });
        return Promise.resolve({ data: { data: recent } });
    });
    // La modale charge à l'OUVERTURE (watch open false→true), pas au montage → on simule l'ouverture.
    w = shallowMount(PosStockOutflowModal, { props: { open: false } });
    await w.setProps({ open: true });
    await flushPromises();
    return w;
};

describe('PosStockOutflowModal', () => {
    beforeEach(() => { mockGet.mockReset(); mockPost.mockReset(); });
    afterEach(() => { if (w) { w.unmount(); w = null; } });

    it('charge items + historique à l\'ouverture', async () => {
        await mountOpen(
            [{ id: 1, name: 'Coca' }],
            [{ id: 9, item_name: 'Frites', quantity: 1, type: 'waste', type_label: 'Perte', created_at_human: '31/07 10:00' }],
        );
        expect(w.vm.items.length).toBe(1);
        expect(w.vm.recent.length).toBe(1);
    });

    it('canSubmit exige un produit sélectionné + quantité valide', async () => {
        await mountOpen([{ id: 1, name: 'Coca' }]);
        expect(w.vm.canSubmit).toBe(false);
        w.vm.search = 'Coca';
        expect(w.vm.selectedItem && w.vm.selectedItem.id).toBe(1);
        expect(w.vm.canSubmit).toBe(true);
    });

    it('submit POST puis prepend à l\'historique + reset', async () => {
        await mountOpen([{ id: 1, name: 'Coca' }]);
        w.vm.search = 'Coca';
        w.vm.form.type = 'staff_meal';
        mockPost.mockResolvedValueOnce({
            data: { outflow: { id: 100, item_name: 'Coca', quantity: 1, type: 'staff_meal', type_label: 'Repas personnel', created_at_human: '31/07 10:05' } },
        });
        await w.vm.submit();
        await flushPromises();
        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(w.vm.recent[0].id).toBe(100);
        expect(w.vm.search).toBe('');
    });

    it('best-effort : un chargement en échec ne jette pas', async () => {
        mockGet.mockRejectedValue(new Error('net'));
        w = shallowMount(PosStockOutflowModal, { props: { open: false } });
        await w.setProps({ open: true });
        await flushPromises();
        expect(w.vm.error).toBeTruthy();
        expect(w.vm.items).toEqual([]);
    });
});
