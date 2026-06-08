// @vitest-environment happy-dom
/**
 * [SEC-FALSIFY-2026-06-08 P1] Cash-drawer close must forward the variance reason.
 *
 * closeSession() previously POSTed an empty `{}` body to /reconcile and kept the
 * cashier's mandatory variance reason only locally. The backend persists the reason
 * (NF525 evidence) and REQUIRES it (422) when |variance| exceeds the 2€ threshold, so
 * dropping it silently discarded the reason and stranded an over-threshold close
 * CLOSED-but-not-RECONCILED. This proves the reason is now sent in the reconcile body.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { closeSession } from '../../resources/js/services/CashDrawerService';

describe('CashDrawerService.closeSession — variance_reason forwarded to /reconcile', () => {
    let post;

    beforeEach(() => {
        post = vi.fn()
            .mockResolvedValueOnce({ data: { data: {} } })                 // close
            .mockResolvedValueOnce({ data: { data: { variance: 3 } } });   // reconcile
        window.axios = { post };
    });

    afterEach(() => {
        delete window.axios;
    });

    it('sends { variance_reason } in the reconcile body when a reason is provided', async () => {
        await closeSession(42, 100, 'Erreur de rendu monnaie');

        expect(post).toHaveBeenCalledTimes(2);
        const [closeUrl] = post.mock.calls[0];
        const [reconcileUrl, reconcileBody] = post.mock.calls[1];
        expect(closeUrl).toContain('/42/close');
        expect(reconcileUrl).toContain('/42/reconcile');
        expect(reconcileBody).toEqual({ variance_reason: 'Erreur de rendu monnaie' });
    });

    it('sends an empty reconcile body when no reason is provided', async () => {
        await closeSession(42, 100, null);
        expect(post.mock.calls[1][1]).toEqual({});
    });

    it('treats a whitespace-only reason as empty (no variance_reason key)', async () => {
        await closeSession(42, 100, '   ');
        expect(post.mock.calls[1][1]).toEqual({});
    });
});
