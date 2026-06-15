import { describe, it, expect, vi, afterEach } from 'vitest';

/**
 * [B-KDS-03 2026-06-14] Legacy board: misleading 409 error toast.
 *
 * On the legacy KDS board, double-clicking « Prêt » fired a red error toast
 * (`alertService.error(kds_status_conflict)`) even though a 409 is a benign
 * double-bump (idempotency / state-machine already applied the transition).
 * The V2 path (onV2ChangeStatus) is already silent on 409 — just a refresh.
 *
 * Fix: align legacy `orderStatus()` on V2 — refresh silently on 409, no toast.
 * Non-409 errors (e.g. 422) MUST still surface the error toast.
 * The recall() flow keeps its own 409 message (different semantics) — untouched.
 */

vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })),
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn(), successFlip: vi.fn() },
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: { modalShow: vi.fn(), modalHide: vi.fn(), openFilterSlide: vi.fn(), closeFilterSlide: vi.fn() },
}));

import KitchenDisplaySystemComponent from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';
import alertService from '../../resources/js/services/alertService';

const methods = KitchenDisplaySystemComponent.methods;
const flush = () => new Promise((r) => setTimeout(r, 0));

const makeCtx = (rejectStatus) => ({
    loading: { isActive: false },
    $store: {
        dispatch: vi.fn(() => Promise.reject({ response: { status: rejectStatus } })),
    },
    _debouncedRefresh: vi.fn(),
    kdsAnnounceTransition: vi.fn(),
    $t: (k) => k,
});

const order = { id: 42, status: 5 }; // PREPARING → PREPARED bump

describe('B-KDS-03 — legacy orderStatus() 409 toast', () => {
    afterEach(() => vi.clearAllMocks());

    it('does NOT toast on 409 (benign double-bump) — refreshes silently', async () => {
        const ctx = makeCtx(409);
        methods.orderStatus.call(ctx, order, 6);
        await flush();
        expect(alertService.error).not.toHaveBeenCalled();
        expect(ctx._debouncedRefresh).toHaveBeenCalled();
    });

    it('DOES toast on a real error (422) — error path preserved', async () => {
        const ctx = makeCtx(422);
        methods.orderStatus.call(ctx, order, 6);
        await flush();
        expect(alertService.error).toHaveBeenCalled();
    });
});
