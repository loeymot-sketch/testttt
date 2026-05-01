const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

function readRepoFile(relativePath) {
    return fs.readFileSync(path.join(process.cwd(), relativePath), 'utf8');
}

test.describe('POS receives kiosk realtime contract', () => {
    test('outbox payload, event contract, and POS consumer share kiosk identity keys', async () => {
        const eventContract = readRepoFile('app/Domain/Events/EventContract.php');
        const createdListener = readRepoFile('app/Listeners/PersistOrderCreatedToOutbox.php');
        const statusListener = readRepoFile('app/Listeners/PersistOrderStatusChangedToOutbox.php');
        const posOrderStore = readRepoFile('resources/js/store/modules/posOrder.js');
        const posComponent = readRepoFile('resources/js/components/admin/pos/PosComponent.vue');

        ['_origin', 'payment_method', 'queue_number'].forEach((key) => {
            expect(eventContract).toContain(`'${key}'`);
            expect(createdListener).toContain(`'${key}'`);
            expect(statusListener).toContain(`'${key}'`);
        });

        expect(posOrderStore).toContain('normalizeRealtimeOrderEvent');
        expect(posOrderStore).toContain('shouldNotifyPosRealtimeOrder');
        expect(posOrderStore).toContain('payload._origin');
        expect(posOrderStore).toContain('payload.payment_method');
        expect(posOrderStore).toContain('payload.queue_number');
        expect(posComponent).toContain('shouldNotifyPosRealtimeOrder(event)');
    });
});
