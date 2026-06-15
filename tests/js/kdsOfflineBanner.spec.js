import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/**
 * [B-KDS-02 2026-06-14] KDS V2 offline-blindness fix.
 *
 * `v2OfflineSince` feeds `KdsStatusBanner` (renders the red OFFLINE banner
 * after 60s). Before the fix it was declared + bound but NEVER assigned →
 * the offline banner was dead code on the V2 board: a dropped WS / failed
 * poll produced NO visual signal for the kitchen.
 *
 * The fix arms `v2OfflineSince` on WS-disconnect and on a failed poll
 * (guarded `useV2Layout && === null` so the 60s threshold is measured from
 * the FIRST failure), and clears it on reconnect / successful poll. Legacy
 * layout keeps its own kdsErrorBanner and must NOT be armed.
 */

vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })),
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: { modalShow: vi.fn(), modalHide: vi.fn(), openFilterSlide: vi.fn(), closeFilterSlide: vi.fn() },
}));

import KitchenDisplaySystemComponent from '../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';

const methods = KitchenDisplaySystemComponent.methods;

// Minimal `this` context for direct method invocation (pattern =
// posOrdersTrackerActiveWindow.spec.js). All collaborators stubbed.
const makeCtx = (overrides = {}) => ({
    useV2Layout: true,
    v2OfflineSince: null,
    wsConnected: true,
    loading: { isActive: false },
    props: { search: {} },
    _kdsOrdersHydrated: false,
    kdsOverflowDetected: false,
    $store: { dispatch: vi.fn(() => Promise.resolve({ data: { data: [], meta: {} } })) },
    _applyOrderBuckets: vi.fn(),
    _clearKdsErrorBanner: vi.fn(),
    _raiseKdsErrorBanner: vi.fn(),
    _restartPolling: vi.fn(),
    refreshOrderList: vi.fn(),
    ...overrides,
});

const flush = () => new Promise((r) => setTimeout(r, 0));

describe('B-KDS-02 — V2 offline chrono (v2OfflineSince)', () => {
    afterEach(() => {
        delete window._wsService;
        vi.restoreAllMocks();
    });

    it('arms v2OfflineSince when a poll FAILS in V2 (was dead code before)', async () => {
        const ctx = makeCtx({
            $store: { dispatch: vi.fn(() => Promise.reject({ response: { status: 500 } })) },
        });
        methods._refreshWithCurrentFilter.call(ctx);
        await flush();
        expect(ctx.v2OfflineSince).not.toBeNull();
    });

    it('clears v2OfflineSince on a SUCCESSFUL poll', async () => {
        const ctx = makeCtx({ v2OfflineSince: Date.now() - 5000 });
        methods._refreshWithCurrentFilter.call(ctx);
        await flush();
        expect(ctx.v2OfflineSince).toBeNull();
    });

    it('does NOT arm v2OfflineSince when useV2Layout is false (legacy keeps kdsErrorBanner)', async () => {
        const ctx = makeCtx({
            useV2Layout: false,
            $store: { dispatch: vi.fn(() => Promise.reject({ response: { status: 500 } })) },
        });
        methods._refreshWithCurrentFilter.call(ctx);
        await flush();
        expect(ctx.v2OfflineSince).toBeNull();
    });

    it('arms on WS disconnect and clears on WS reconnect (V2)', () => {
        const handlers = {};
        window._wsService = {
            on: (evt, cb) => { handlers[evt] = cb; },
            off: vi.fn(),
        };
        const ctx = makeCtx();
        methods._bindWsService.call(ctx);

        handlers.disconnected();
        expect(ctx.v2OfflineSince).not.toBeNull();
        const firstStamp = ctx.v2OfflineSince;

        // A second disconnect must NOT reset the chrono (=== null guard).
        handlers.disconnected();
        expect(ctx.v2OfflineSince).toBe(firstStamp);

        handlers.connected();
        expect(ctx.v2OfflineSince).toBeNull();
    });

    it('does NOT arm on WS disconnect when useV2Layout is false', () => {
        const handlers = {};
        window._wsService = {
            on: (evt, cb) => { handlers[evt] = cb; },
            off: vi.fn(),
        };
        const ctx = makeCtx({ useV2Layout: false });
        methods._bindWsService.call(ctx);
        handlers.disconnected();
        expect(ctx.v2OfflineSince).toBeNull();
    });
});
