/**
 * [OSS-UIUX-2026-06-08 P2] OSS list() must show the full-board overlay spinner ONLY on the
 * first load. list() is called on every Echo push + each poll tick; flashing the spinner over
 * the already-populated columns made the customer/staff wall strobe on every realtime update.
 */
import { describe, it, expect, vi } from 'vitest';
import Comp from '../../resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue';

function ctx() {
    return {
        loading: { isActive: false },
        _didInitialLoad: false,
        _hydrateFromRows: vi.fn(),
        $store: { dispatch: vi.fn().mockResolvedValue({ data: { data: [] } }) },
        $t: (k) => k,
    };
}

const flush = () => new Promise((r) => setTimeout(r, 0));

describe('OSS PreparingAndReadyComponent.list() — spinner only on first load', () => {
    it('shows the spinner on the first load, then NOT on background refreshes', async () => {
        const c = ctx();

        // First load → overlay spinner shown synchronously.
        Comp.methods.list.call(c);
        expect(c.loading.isActive, 'spinner shown on first load').toBe(true);
        await flush();
        expect(c.loading.isActive, 'spinner cleared after first hydrate').toBe(false);
        expect(c._didInitialLoad, 'first-load flag set').toBe(true);
        expect(c._hydrateFromRows).toHaveBeenCalledTimes(1);

        // Background refresh (Echo push / poll) → must NOT flash the overlay.
        Comp.methods.list.call(c);
        expect(c.loading.isActive, 'no spinner strobe on background refresh').toBe(false);
        await flush();
        expect(c._hydrateFromRows).toHaveBeenCalledTimes(2);
    });
});
