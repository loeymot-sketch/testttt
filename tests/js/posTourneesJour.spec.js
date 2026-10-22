import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// Forme RÉELLE de GET /api/admin/pos-delivery/board après d1633f64f (curl :8766, 2026-10-22 ~18:00) : chaque tournée porte
// date (Y-m-d), is_today, day_label (« aujourd'hui » / « demain » / « hier » / « sam. 24/10 »). Deux tournées « 23:30 »
// coexistent sur la planche : celle d'hier (partie) et celle de ce soir.
const stop = (id) => ({ id, order_id: id, order_number: String(id), customer: 'X', address: 'rue', collect_method: 'paid_online', amount_due: 0, left_at: null, arrived_at: null, delivered_at: null, has_photo: false });
const BOARD = {
    status: true, enabled: true, couriers: [],
    data: [
        { wave_at: '2026-10-21 23:30:00', time: '23:30', date: '2026-10-21', is_today: false, day_label: 'hier', left: true, courier: null, courier_id: null, stops: [stop(7940)] },
        { wave_at: '2026-10-22 23:30:00', time: '23:30', date: '2026-10-22', is_today: true, day_label: "aujourd'hui", left: false, courier: null, courier_id: null, stops: [stop(8099)] },
        { wave_at: '2026-10-23 00:30:00', time: '00:30', date: '2026-10-23', is_today: false, day_label: 'demain', left: false, courier: null, courier_id: null, stops: [stop(8115)] },
        { wave_at: '2026-10-24 19:30:00', time: '19:30', date: '2026-10-24', is_today: false, day_label: 'sam. 24/10', left: false, courier: null, courier_id: null, stops: [stop(8500)] },
    ],
};

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: BOARD })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));

import PosDeliveryBoard from '../../resources/js/components/admin/pos/PosDeliveryBoard.vue';

// [VG-2026-10-21 M4-005] La planche « Tournées » n'affichait que l'heure : « Départ 00:30 » se lisait comme ce soir
// alors que la tournée partait le lendemain, et deux tournées « 23:30 » (hier, ce soir) portaient le MÊME data-testid.
describe('caisse — planche « Tournées » : le jour de chaque départ (M4-005)', () => {
    it('dit le jour quand ce n\'est pas aujourd\'hui, l\'heure seule sinon', async () => {
        const w = mount(PosDeliveryBoard);
        await flushPromises();
        const titres = w.findAll('section header > div:first-child').map((d) => d.text().replace(/\s+/g, ' '));
        expect(titres[0]).toContain('Départ hier 23:30');
        expect(titres[1]).toMatch(/^Départ 23:30/);
        expect(titres[2]).toContain('Départ demain 00:30');
        expect(titres[3]).toContain('Départ sam. 24/10 19:30');
        w.unmount();
    });

    it('le data-testid d\'une tournée porte sa date : plus de doublon après minuit', async () => {
        const w = mount(PosDeliveryBoard);
        await flushPromises();
        const ids = w.findAll('section').map((s) => s.attributes('data-testid'));
        expect(ids).toEqual([
            'pos-delivery-wave-2026-10-21-23:30',
            'pos-delivery-wave-2026-10-22-23:30',
            'pos-delivery-wave-2026-10-23-00:30',
            'pos-delivery-wave-2026-10-24-19:30',
        ]);
        expect(new Set(ids).size).toBe(ids.length);
        w.unmount();
    });
});
