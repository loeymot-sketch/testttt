/**
 * usePosOfflineState — Vue 3 composable: POS offline status + queue (V1).
 * ---------------------------------------------------------------------------
 * Reactive refs the cashier UI binds to a VISIBLE "MODE DÉGRADÉ" banner
 * (D1 UX decision) and a "n commande(s) en attente" counter.
 *
 * tryFlush(postFn) replays queued orders with a stable X-Idempotency-Key per
 * entry. [Wave 5F SHIPPED 2026-05-17, commit 55edb83ba] PosComponent.vue
 * integration is now live (see PosComponent.vue:1104 / :1148 / :1626) — the
 * "deferred to V1.0.2" claim from Wave H3.6 was stale and corrected as part
 * of the P1 V1 Cloud-Prep insights heal (2026-05-18).
 */
import { getCurrentScope, onScopeDispose, ref } from 'vue';

import {
    enqueueOrder as _enqueueOrder,
    getQueueDepth,
    listPending,
    markFailed,
    markSynced,
} from '../helpers/posOfflineQueue';

/**
 * [OFF-01 FIX 2026-06-06] Decide whether a FAILED live order submit should be
 * routed into the offline enqueue path. The live POST happens inside the frozen
 * PaymentComponent; this pure classifier is the in-scope, unit-provable seam the
 * PosComponent pre-modal gate uses to broaden "offline" from navigator.onLine
 * only → "offline OR server-unreachable-at-submit".
 *
 * TRUE  → transport failure / timeout / 5xx: the server did NOT process the
 *         order, so the sale is safe to queue + replay (idempotency-key stable;
 *         server is SSOT on fiscal seq at replay per NF525 CLAUDE.md §8).
 * FALSE → any 4xx (422 validation, 409 conflict, 401/403 authz, 429 throttle)
 *         OR a 2xx: the server received + decided. Enqueuing a 4xx-rejected
 *         order would duplicate / resubmit a business-rejected sale — never do
 *         it. A 2xx is not a failure at all.
 *
 * Accepts either an axios error (`err.response.status`) or a raw response
 * (`{ status }`). Absence of any status ⇒ transport-level failure ⇒ TRUE.
 */
export function shouldEnqueueOnSubmitFailure(errOrResponse) {
    if (errOrResponse == null) return true;
    const status = errOrResponse?.response?.status ?? errOrResponse?.status;
    if (typeof status !== 'number') return true; // no HTTP response → transport failure
    if (status >= 500) return true;              // server did not process
    return false;                                // 2xx / 4xx → server decided
}

export function usePosOfflineState() {
    const initialOnline = typeof navigator !== 'undefined' ? navigator.onLine !== false : true;
    const isOnline = ref(initialOnline);
    const queueDepth = ref(getQueueDepth());
    const isFlushing = ref(false);

    let _boundPostFn = null;
    let _onResult = null; // [OFF-03 FIX] auto-flush result notifier (cashier feedback)
    let _onOnline = null;
    let _onOffline = null;

    async function refresh() {
        const pending = await listPending();
        queueDepth.value = pending.length;
        return pending;
    }

    async function tryFlush(postFn) {
        if (typeof postFn !== 'function' || isFlushing.value) {
            return { synced: 0, failed: 0, skipped: true };
        }
        isFlushing.value = true;
        let synced = 0;
        let failed = 0;
        try {
            for (const entry of await listPending()) {
                const config = { headers: { 'X-Idempotency-Key': entry.idempotencyKey } };
                try {
                    await postFn('admin/pos', entry.payload, config);
                    await markSynced(entry.idempotencyKey);
                    synced += 1;
                } catch (error) {
                    await markFailed(entry.idempotencyKey, error?.response || error);
                    failed += 1;
                }
            }
            await refresh();
        } finally {
            isFlushing.value = false;
        }
        return { synced, failed, skipped: false };
    }

    // Eager state listeners: isOnline must reflect navigator.onLine even when
    // no postFn is bound yet (banner renders before tryFlush wiring).
    if (typeof window !== 'undefined') {
        _onOnline = () => {
            isOnline.value = true;
            if (typeof _boundPostFn === 'function') {
                // [OFF-03 FIX 2026-06-06] The online-event auto-flush used to
                // swallow its result (.catch(() => {})), so a FAILED replay was
                // invisible to the cashier. Surface the result to the bound
                // notifier so PosComponent can raise the SAME alertService
                // warning/success messages as the manual flush (tryManualFlush).
                Promise.resolve()
                    .then(() => tryFlush(_boundPostFn))
                    .then((result) => {
                        if (typeof _onResult === 'function' && result && !result.skipped) {
                            _onResult(result);
                        }
                    })
                    .catch(() => {});
            }
        };
        _onOffline = () => { isOnline.value = false; };
        window.addEventListener('online', _onOnline);
        window.addEventListener('offline', _onOffline);
    }

    // [OFF-03 FIX] onResult (optional, 2nd arg) receives { synced, failed } from
    // the composable's own online-event auto-flush so the cashier is told when a
    // background replay failed. Backward compatible: bindAutoFlush(postFn) alone
    // keeps working (onResult stays null → silent, as before).
    function bindAutoFlush(postFn, onResult) {
        _boundPostFn = typeof postFn === 'function' ? postFn : null;
        _onResult = typeof onResult === 'function' ? onResult : null;
    }

    function unbindAutoFlush() {
        if (typeof window === 'undefined') return;
        if (_onOnline) window.removeEventListener('online', _onOnline);
        if (_onOffline) window.removeEventListener('offline', _onOffline);
        _onOnline = null;
        _onOffline = null;
        _boundPostFn = null;
        _onResult = null;
    }

    // Only register dispose hook inside a Vue effect scope (component setup).
    // Outside (e.g. unit tests), caller invokes unbindAutoFlush() manually.
    if (getCurrentScope()) onScopeDispose(() => unbindAutoFlush());

    return {
        isOnline,
        queueDepth,
        isFlushing,
        enqueueOrder: _enqueueOrder,
        refresh,
        tryFlush,
        bindAutoFlush,
        unbindAutoFlush,
    };
}
