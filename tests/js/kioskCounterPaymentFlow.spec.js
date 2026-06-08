import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const root = resolve(process.cwd());
const read = (rel) => readFileSync(resolve(root, rel), 'utf-8');

describe('B5b kiosk cash-at-counter flow', () => {
    it('routes kiosk cash orders to the counter instruction screen without opening a drawer', () => {
        const source = read('resources/js/components/frontend/kiosk/KioskPaymentComponent.vue');

        expect(source).toMatch(/name:\s*['"]kiosk\.cash-instruction['"]/);
        expect(source).toMatch(/number:\s*queueNum/);
        expect(source).not.toMatch(/kioskHardware\.openDrawer\(\)/);
    });

    it('uses the dedicated POS counter collection endpoints', () => {
        const source = read('resources/js/components/admin/pos/PosComponent.vue');

        expect(source).toContain("axios.get('admin/pos/counter-collect/pending')");
        expect(source).toMatch(/admin\/pos\/counter-collect\/\$\{order\.id\}\/cancel/);
        expect(source).toContain('posPaymentMethodEnum.CASH');
        expect(source).toContain('OrderPaidAtCounter');
        // [GOAL-2026-05-29] The confirm-POST moved to the dedicated PosCounterCollectModal
        // (it owns the confirm step now, with X-Idempotency-Key); PosComponent keeps
        // pending/cancel. Assert confirm against its real owner (uses ${orderId}).
        expect(read('resources/js/components/admin/pos/PosCounterCollectModal.vue'))
            .toMatch(/admin\/pos\/counter-collect\/\$\{orderId\}\/confirm/);
    });

    it('marks pending counter payment orders on the KDS card and listens for confirmation', () => {
        const source = read('resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');

        expect(source).toContain('paymentStatusEnum.PENDING_COUNTER');
        expect(source).toContain('payment_pending_counter');
        // [FP-11] The unpaid-counter badge renders via the namespaced i18n key
        // ($t('label.kds_counter_payment_unpaid')), NOT a hardcoded literal. The
        // previous assertion checked an uppercase literal that never existed in the
        // component (this test was red at HEAD) — assert the real i18n key the card uses.
        expect(source).toContain('label.kds_counter_payment_unpaid');
        expect(source).toContain('OrderPaidAtCounter');
    });
});
