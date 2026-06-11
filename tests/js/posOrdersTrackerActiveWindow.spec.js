import { describe, it, expect, vi } from 'vitest';

/**
 * [UIUX-W2 F4 2026-06-11] Kanban tracker borné à aujourd'hui : une commande
 * ACTIVE (PREPARING/PREPARED/...) créée hier soir DISPARAISSAIT du board à
 * minuit (`_todayRange()` → from=to=aujourd'hui côté API).
 *
 * Fix scope-minimal :
 *  - fenêtre de fetch élargie à 48h (`_activeWindowRange()`, from = J-1) ;
 *  - la lane « Terminées » (DELIVERED) garde sa sémantique JOUR : les
 *    livrées d'hier sont filtrées côté client pour ne pas inonder la lane.
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

const iso = (d) => d.toISOString();
const localYmd = (d) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
};

const today = new Date();
const yesterday = new Date(Date.now() - 24 * 3600 * 1000);

const makeCtx = (orders) => ({
    filteredOrders: orders,
    isCashPending: () => false,
    _tsOf: PosOrdersTrackerComponent.methods._tsOf,
    _isCreatedToday: PosOrdersTrackerComponent.methods._isCreatedToday,
    _localYmd: PosOrdersTrackerComponent.methods._localYmd,
});

describe('PosOrdersTrackerComponent active window (F4 UIUX-W2)', () => {
    it('_activeWindowRange spans 48h (from = yesterday, to = today)', () => {
        // `this` = l'objet methods → _localYmd résolu comme au runtime.
        const range = PosOrdersTrackerComponent.methods._activeWindowRange.call(
            PosOrdersTrackerComponent.methods,
        );
        expect(range.from).toBe(localYmd(yesterday));
        expect(range.to).toBe(localYmd(today));
    });

    it('keeps a yesterday-evening PREPARING order on the active lane (no midnight vanish)', () => {
        const o = { id: 1, status: orderStatusEnum.PREPARING, created_at: iso(yesterday) };
        const buckets = PosOrdersTrackerComponent.computed.ordersByStatus.call(makeCtx([o]));
        expect(buckets.preparing.map((x) => x.id)).toContain(1);
    });

    it('keeps the « Terminées » lane day-scoped: yesterday DELIVERED is filtered out, today kept', () => {
        const oldDelivered = { id: 2, status: orderStatusEnum.DELIVERED, created_at: iso(yesterday) };
        const newDelivered = { id: 3, status: orderStatusEnum.DELIVERED, created_at: iso(today) };
        const buckets = PosOrdersTrackerComponent.computed.ordersByStatus.call(
            makeCtx([oldDelivered, newDelivered]),
        );
        const ids = buckets.delivered.map((x) => x.id);
        expect(ids).toContain(3);
        expect(ids).not.toContain(2);
    });
});
