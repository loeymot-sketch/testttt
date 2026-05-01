/**
 * @FK-ID FK-053 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md
 * Offline kiosk order refs must stay local-only: the waiting screen may show a
 * sync-pending state, but must not poll frontend/order/show/offline_*.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

function readRepoFile(relativePath) {
    return fs.readFileSync(path.join(process.cwd(), relativePath), 'utf8');
}

test.describe('Kiosk offline waiting contract', () => {
    test('offline_ waiting ids render sync-pending UX without backend polling', async () => {
        const waitingSource = readRepoFile('resources/js/components/frontend/kiosk/KioskWaitingComponent.vue');
        const routesSource = readRepoFile('resources/js/router/modules/kioskRoutes.js');
        const offlineQueueSource = readRepoFile('resources/js/helpers/kioskOfflineQueue.js');

        const offlineGuardIndex = waitingSource.indexOf("String(this.orderId).startsWith('offline_')");
        const offlineReturnIndex = waitingSource.indexOf('return;', offlineGuardIndex);
        const startPollingIndex = waitingSource.indexOf('this.startPolling();');

        expect(offlineGuardIndex).toBeGreaterThanOrEqual(0);
        expect(offlineReturnIndex).toBeGreaterThan(offlineGuardIndex);
        expect(offlineReturnIndex).toBeLessThan(startPollingIndex);
        expect(waitingSource).toContain('v-if="isOfflineOrder"');
        expect(waitingSource).toContain('offline_queue.title');
        expect(waitingSource).toContain('offline_queue.will_send');

        expect(routesSource).toContain('/^(offline_)?\\d+$/.test');
        expect(routesSource).toContain('path: "waiting/:orderId"');

        expect(offlineQueueSource).toContain("candidate.startsWith('offline_')");
        expect(offlineQueueSource).toContain('return `offline_${savedAt}_${Math.random().toString(36).slice(2, 8)}`');
    });
});
