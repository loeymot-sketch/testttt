/**
 * [VGAP-01 close] Runtime companion to ossPublicWallErrorToastGating.spec.js.
 *
 * The sibling spec asserts the OSS-01 gate via a SOURCE regex — it proves the bytes are present but never
 * EXECUTES the `.catch` path, so it cannot catch a logic inversion (e.g. `<= 0` vs `> 0`) that still matches
 * the pattern. The CAISSE-S1 lesson: source-grep is not proof a consumer behaves. This spec invokes the real
 * `list()` method with a rejecting dispatch and a controllable `authBranchId`, asserting at RUNTIME that:
 *   - public customer wall (authBranchId() <= 0)  → alertService.error SUPPRESSED
 *   - attended staff surface (authBranchId() > 0)  → alertService.error FIRES
 *
 * We invoke `methods.list.call(fakeThis)` rather than full-mount: the component is a 24/7 wall with Echo +
 * wakeLock + AudioContext wiring in mounted(), too heavy to mount, and `list()`'s catch is self-contained.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import alertService from '../../resources/js/services/alertService';
import PreparingAndReadyComponent from '../../resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue';

vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        success: vi.fn(),
        warning: vi.fn(),
        info: vi.fn(),
        error: vi.fn(),
        default: vi.fn(),
    },
}));

// Build a minimal `this` that drives list() down its .catch branch with a controllable branch id.
function fakeThisWithBranch(branchId) {
    return {
        loading: { isActive: false },
        authBranchId: () => branchId,
        $t: (key) => key,
        _hydrateFromRows: vi.fn(), // only reached on resolve; the reject path never calls it
        $store: {
            dispatch: vi.fn().mockRejectedValue({ response: { data: { message: 'poll blip' } } }),
        },
    };
}

describe('OSS public-wall error-toast gating — RUNTIME (VGAP-01)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('SUPPRESSES alertService.error on the unattended public wall (authBranchId() <= 0)', async () => {
        const ctx = fakeThisWithBranch(0);

        await PreparingAndReadyComponent.methods.list.call(ctx);
        await flushPromises();

        expect(ctx.$store.dispatch).toHaveBeenCalledWith('orderStatusScreenOrder/lists');
        expect(alertService.error).not.toHaveBeenCalled(); // a transient blip must NOT stack a toast no one can dismiss
        expect(ctx.loading.isActive).toBe(false);          // loading still resolved in the catch
    });

    it('FIRES alertService.error on the attended staff surface (authBranchId() > 0)', async () => {
        const ctx = fakeThisWithBranch(1);

        await PreparingAndReadyComponent.methods.list.call(ctx);
        await flushPromises();

        expect(alertService.error).toHaveBeenCalledTimes(1);
        expect(alertService.error).toHaveBeenCalledWith('poll blip'); // staff get the real backend message
    });

    it('falls back to the generic i18n message when the error carries no backend message', async () => {
        const ctx = fakeThisWithBranch(1);
        ctx.$store.dispatch = vi.fn().mockRejectedValue(new Error('network'));

        await PreparingAndReadyComponent.methods.list.call(ctx);
        await flushPromises();

        expect(alertService.error).toHaveBeenCalledWith('message.something_wrong');
    });
});
