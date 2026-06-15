import { describe, it, expect, vi } from 'vitest';

/**
 * [B-OSS-01 sentinel 2026-06-14] Anti-regression lock for the caisse order
 * tracker lane ordering.
 *
 * The post-ship backlog suspected « lane LIVRÉS morte + tri actif inversé ».
 * Verify-before-report REFUTED both claims on the trunk: the DELIVERED lane is
 * reachable (today-DELIVERED kept) and the sort direction is correct —
 *   - active lanes (preparing/...) = oldest-first (FIFO for the kitchen/caisse);
 *   - delivered lane = newest-first (recently completed on top).
 *
 * No code fix was needed. This sentinel PINS the correct behavior so a future
 * refactor cannot silently invert the lanes. It must PASS as-is.
 */

vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })),
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: { modalShow: vi.fn(), modalHide: vi.fn() },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({
    default: { name: 'ReceiptComponent', template: '<div />', props: ['order'] },
}));

import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

const iso = (ms) => new Date(ms).toISOString();
const now = Date.now();

const makeCtx = (orders) => ({
    filteredOrders: orders,
    isCashPending: () => false,
    _tsOf: PosOrdersTrackerComponent.methods._tsOf,
    _isCreatedToday: PosOrdersTrackerComponent.methods._isCreatedToday,
    _localYmd: PosOrdersTrackerComponent.methods._localYmd,
});

describe('B-OSS-01 sentinel — caisse tracker lane ordering', () => {
    it('preparing lane is OLDEST-first (FIFO)', () => {
        const oldest = { id: 1, status: orderStatusEnum.PREPARING, created_at: iso(now - 30 * 60000) };
        const middle = { id: 2, status: orderStatusEnum.PREPARING, created_at: iso(now - 15 * 60000) };
        const newest = { id: 3, status: orderStatusEnum.PREPARING, created_at: iso(now - 1 * 60000) };

        // shuffled input order to prove the sort, not the insertion order
        const buckets = PosOrdersTrackerComponent.computed.ordersByStatus.call(
            makeCtx([newest, oldest, middle]),
        );

        expect(buckets.preparing.map((x) => x.id)).toEqual([1, 2, 3]);
    });

    it('delivered lane is NEWEST-first (recently completed on top)', () => {
        const earlier = { id: 10, status: orderStatusEnum.DELIVERED, created_at: iso(now - 40 * 60000) };
        const later = { id: 11, status: orderStatusEnum.DELIVERED, created_at: iso(now - 5 * 60000) };

        const buckets = PosOrdersTrackerComponent.computed.ordersByStatus.call(
            makeCtx([earlier, later]),
        );

        expect(buckets.delivered.map((x) => x.id)).toEqual([11, 10]);
    });

    it('today-DELIVERED is present (lane reachable, not dead)', () => {
        const todayDelivered = { id: 20, status: orderStatusEnum.DELIVERED, created_at: iso(now) };
        const buckets = PosOrdersTrackerComponent.computed.ordersByStatus.call(
            makeCtx([todayDelivered]),
        );
        expect(buckets.delivered.map((x) => x.id)).toContain(20);
    });
});
