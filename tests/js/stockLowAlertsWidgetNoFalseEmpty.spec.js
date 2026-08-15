import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * [T-5.2 PANNE-MASQUEE 2026-08-15 · GOAL_CONFORT_MAX] `fetchAlerts()` avalait
 * toute erreur (403/500/réseau) en la traitant EXACTEMENT comme "aucune
 * alerte" (`catch (_e) { this.alerts = []; }`) — un propriétaire regardant le
 * dashboard ne pouvait jamais distinguer "stock sain" d'"écran cassé". Même
 * motif que EncaissementComponent (T-4.1).
 */
vi.mock('axios', () => ({ default: { get: vi.fn() } }));

import axios from 'axios';
import StockLowAlertsWidget from '../../resources/js/components/admin/dashboard/StockLowAlertsWidget.vue';

const fetchAlerts = StockLowAlertsWidget.methods.fetchAlerts;

function ctx(overrides = {}) {
    return {
        loading: { isActive: false },
        alerts: [],
        fetchError: false,
        ...overrides,
    };
}

describe('StockLowAlertsWidget.fetchAlerts — pas de panne déguisée en "aucune alerte"', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('succès avec des alertes réelles : alerts rempli, fetchError=false', async () => {
        axios.get.mockResolvedValue({ data: { alerts: [{ stockable_id: 1 }] } });
        const c = ctx({ fetchError: true });
        await fetchAlerts.call(c);
        expect(c.alerts).toEqual([{ stockable_id: 1 }]);
        expect(c.fetchError).toBe(false);
    });

    it('succès sans alerte (vrai stock sain) : alerts=[], fetchError=false', async () => {
        axios.get.mockResolvedValue({ data: { alerts: [] } });
        const c = ctx();
        await fetchAlerts.call(c);
        expect(c.alerts).toEqual([]);
        expect(c.fetchError).toBe(false);
    });

    it('échec réseau/403/500 : fetchError=true — JAMAIS confondu avec "aucune alerte"', async () => {
        axios.get.mockRejectedValue({ response: { status: 500 } });
        const c = ctx();
        await fetchAlerts.call(c);
        expect(c.alerts).toEqual([]);
        expect(c.fetchError, 'une panne doit être TRACÉE, jamais présentée comme un stock sain').toBe(true);
    });
});
