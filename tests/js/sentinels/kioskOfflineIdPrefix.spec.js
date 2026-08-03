import { describe, expect, it, vi, beforeEach } from 'vitest';
import fs from 'fs';
import path from 'path';

/**
 * @FK-ID FK-053 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M11-KIOSK-RUNTIME | @reason every offline kiosk order reference must use the offline_ prefix so CB/TR cannot treat it as a server order.
 */
vi.mock('../../../resources/js/helpers/kioskAnalytics', () => ({
    track: vi.fn(),
}));

vi.mock('../../../resources/js/helpers/kioskOfflineQueueDb', () => ({
    clearQueueEntries: vi.fn(),
    delQueueEntry: vi.fn(),
    getQueueEntry: vi.fn().mockResolvedValue(null),
    isIndexedDbReady: vi.fn(() => false),
    setQueueEntry: vi.fn().mockResolvedValue(undefined),
}));

import { saveOrder } from '../../../resources/js/helpers/kioskOfflineQueue';

describe('KioskOfflineIdPrefixSentinel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('returns only offline-prefixed local order ids, including replay/original-key paths', () => {
        const generated = saveOrder({ items: [] });
        const replayed = saveOrder({ items: [] }, 'legacy-local-key');

        expect(generated).toMatch(/^offline_/);
        expect(replayed).toMatch(/^offline_/);
    });

    it('waiting screen treats offline_ ids as sync-pending without backend polling', () => {
        const waitingSource = fs.readFileSync(
            path.join(process.cwd(), 'resources/js/components/frontend/kiosk/KioskWaitingComponent.vue'),
            'utf8',
        );

        const offlineGuardIndex = waitingSource.indexOf("String(this.orderId).startsWith('offline_')");
        const offlineReturnIndex = waitingSource.indexOf('return;', offlineGuardIndex);
        const pollingIndex = waitingSource.indexOf('this.startPolling();');

        expect(offlineGuardIndex).toBeGreaterThanOrEqual(0);
        expect(offlineReturnIndex).toBeGreaterThan(offlineGuardIndex);
        expect(offlineReturnIndex).toBeLessThan(pollingIndex);
        expect(waitingSource).toContain('v-if="isOfflineOrder"');
        expect(waitingSource).toContain("this.isOfflineOrder = true");
    });

    it('router accepts offline_ waiting references but rejects invalid order ids', () => {
        const routesSource = fs.readFileSync(
            path.join(process.cwd(), 'resources/js/router/modules/kioskRoutes.js'),
            'utf8',
        );

        expect(routesSource).toContain('/^(offline_)?\\d+$/.test');
        expect(routesSource).toContain("return next({ name: 'kiosk.idle' })");
        expect(routesSource).toContain('path: "waiting/:orderId"');
    });
});
