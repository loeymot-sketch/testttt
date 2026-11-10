import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

// [GOAL LIVRAISON-TICKETS 2026-11-10 · T-1.2.2 / T-1.3.3] Planche « Tournées » : téléphone du client (même source que le
// ticket) et bouton « Ticket » qui imprime le ticket client de la livraison via le pont de la caisse.
// Forme RÉELLE de GET /api/admin/pos-delivery/board après PosDeliveryController (phone ajouté le 2026-11-10).
const BOARD = {
    status: true, enabled: true, couriers: [],
    data: [{
        wave_at: '2026-11-10 20:30:00', time: '20:30', left: false, courier: null, courier_id: null,
        stops: [
            { id: 1, order_id: 9936, order_number: 'VIS1110021327', customer: 'Karim', address: '10 Rue Test, Apt 2B, 62110 Hénin-Beaumont', phone: '0612345678', collect_method: 'card', amount_due: 27.5, left_at: null, arrived_at: null, delivered_at: null, has_photo: false },
            { id: 2, order_id: 9937, order_number: '9937', customer: 'Sans tel', address: '2 rue B', phone: null, collect_method: 'cash', amount_due: 5.9, left_at: null, arrived_at: null, delivered_at: null, has_photo: false },
            { id: 3, order_id: 9938, order_number: '9938', customer: 'Remis', address: '3 rue C', phone: '0600000000', collect_method: 'cash', amount_due: 4.9, left_at: '2026-11-10 20:31:00', arrived_at: '2026-11-10 20:40:00', delivered_at: '2026-11-10 20:41:00', has_photo: false },
        ],
    }],
};

const { imprimer, alertes } = vi.hoisted(() => ({
    imprimer: vi.fn(),
    alertes: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));
vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: BOARD })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/helpers/posImprimerTicketClient', () => ({ imprimerTicketClient: (...a) => imprimer(...a) }));
vi.mock('../../resources/js/services/alertService', () => ({ default: alertes }));

import PosDeliveryBoard from '../../resources/js/components/admin/pos/PosDeliveryBoard.vue';

describe('caisse — planche « Tournées » : téléphone et ticket client de la livraison', () => {
    beforeEach(() => { imprimer.mockReset(); alertes.success.mockReset(); alertes.error.mockReset(); });

    it('affiche le téléphone cliquable quand le serveur le donne, rien sinon', async () => {
        const w = mount(PosDeliveryBoard);
        await flushPromises();
        const tel = w.find('[data-testid="pos-delivery-phone-1"]');
        expect(tel.exists()).toBe(true);
        expect(tel.attributes('href')).toBe('tel:0612345678');
        expect(tel.text()).toContain('0612345678');
        expect(w.find('[data-testid="pos-delivery-phone-2"]').exists()).toBe(false);
        expect(w.text()).toContain('10 Rue Test, Apt 2B, 62110 Hénin-Beaumont');
    });

    it('« Ticket » imprime le ticket client de LA commande de l\'arrêt et le dit ; absent sur un arrêt remis', async () => {
        imprimer.mockResolvedValue({ ok: true });
        const w = mount(PosDeliveryBoard);
        await flushPromises();
        expect(w.find('[data-testid="pos-delivery-ticket-3"]').exists()).toBe(false); // remis : plus de ticket à sortir
        await w.find('[data-testid="pos-delivery-ticket-1"]').trigger('click');
        await flushPromises();
        expect(imprimer).toHaveBeenCalledTimes(1);
        expect(imprimer).toHaveBeenCalledWith(9936);
        expect(alertes.success).toHaveBeenCalledTimes(1);
        expect(alertes.error).not.toHaveBeenCalled();
    });

    it('pont injoignable : l\'échec est annoncé, jamais silencieux, et le bouton redevient actif', async () => {
        imprimer.mockResolvedValue({ ok: false, raison: 'pont' });
        const w = mount(PosDeliveryBoard);
        await flushPromises();
        await w.find('[data-testid="pos-delivery-ticket-2"]').trigger('click');
        await flushPromises();
        expect(alertes.error).toHaveBeenCalledTimes(1);
        expect(String(alertes.error.mock.calls[0][0])).toMatch(/Pont d'impression/);
        expect(w.find('[data-testid="pos-delivery-ticket-2"]').attributes('disabled')).toBeUndefined();
    });
});
