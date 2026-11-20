import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// Forme RÉELLE de GET /api/admin/pos-delivery/board (curl :8766, 2026-10-22) : collect_method ∈ cash | card |
// meal_voucher (depuis 03d8cd801) | paid_online, amount_due en euros.
const BOARD = {
    status: true,
    enabled: true,
    couriers: [],
    data: [{
        wave_at: '2026-10-22 20:30:00', time: '20:30', left: false, courier: null, courier_id: null,
        stops: [
            { id: 1, order_id: 8329, order_number: '8329', customer: 'Alice', address: '1 rue A', collect_method: 'meal_voucher', amount_due: 5.8, left_at: null, arrived_at: null, delivered_at: null, has_photo: false },
            { id: 2, order_id: 8377, order_number: '8377', customer: 'Bob', address: '2 rue B', collect_method: 'cash', amount_due: 5.9, left_at: null, arrived_at: null, delivered_at: null, has_photo: false },
            { id: 3, order_id: 8378, order_number: '8378', customer: 'Chloé', address: '3 rue C', collect_method: 'card', amount_due: 4.9, left_at: null, arrived_at: null, delivered_at: null, has_photo: false },
            { id: 4, order_id: 8077, order_number: '8077', customer: 'Dan', address: '4 rue D', collect_method: 'paid_online', amount_due: 0, left_at: null, arrived_at: null, delivered_at: null, has_photo: false },
            // [AUDIT ABUSIF tour 3 · ECR-R3-003/004] remis en plusieurs moyens ; annulé en route.
            { id: 5, order_id: 8400, order_number: '8400', customer: 'Eve', address: '5 rue E', collect_method: 'split', amount_due: 9.6, left_at: '2026-10-22 20:31:00', arrived_at: '2026-10-22 20:40:00', delivered_at: '2026-10-22 20:41:00', has_photo: false },
            { id: 6, order_id: 8401, order_number: '8401', customer: 'Fred', address: '6 rue F', collect_method: 'cash', amount_due: 9.6, left_at: '2026-10-22 20:31:00', arrived_at: null, delivered_at: null, has_photo: false, cancelled: true },
        ],
    }],
};

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: BOARD })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));

import PosDeliveryBoard from '../../resources/js/components/admin/pos/PosDeliveryBoard.vue';

// [VG-2026-10-21 R2-cuisine-livraison-003] La planche « Tournées » affichait « Espèces 5,80 € » pour un arrêt payé en
// titre-restaurant : la caisse attendait du livreur des espèces qu'il n'avait pas.
describe('caisse — planche « Tournées » : vrai mode d\'encaissement de chaque arrêt', () => {
    it('titre-restaurant, espèces, carte, payé : chaque arrêt dit ce que le livreur rapporte', async () => {
        const w = mount(PosDeliveryBoard);
        await flushPromises();
        const lignes = w.findAll('li').map((li) => li.text());
        expect(lignes[0]).toContain('Titre-restaurant 5,80 €');
        expect(lignes[0]).not.toContain('Espèces');
        expect(lignes[1]).toContain('Espèces 5,90 €');
        expect(lignes[2]).toContain('Carte 4,90 €');
        expect(lignes[3]).toContain('Payé');
        expect(lignes[4]).toContain('Encaissé 9,60 € (plusieurs moyens)');
        expect(lignes[4]).not.toContain('à encaisser');
        expect(lignes[5]).toContain('Annulée · rien à encaisser');
        expect(lignes[5]).not.toContain('Espèces 9,60');
        w.unmount();
    });
});
