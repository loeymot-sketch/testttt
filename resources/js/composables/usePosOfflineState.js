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
    listQuarantined,
    markFailed,
    markSynced,
} from '../helpers/posOfflineQueue';

export function usePosOfflineState() {
    const initialOnline = typeof navigator !== 'undefined' ? navigator.onLine !== false : true;
    const isOnline = ref(initialOnline);
    const queueDepth = ref(getQueueDepth());
    const quarantineDepth = ref(0);
    const quarantinedEntries = ref([]);
    const isFlushing = ref(false);

    let _boundPostFn = null;
    let _onOnline = null;
    let _onOffline = null;

    async function refresh() {
        const [pending, quarantined] = await Promise.all([listPending(), listQuarantined()]);
        queueDepth.value = pending.length;
        quarantineDepth.value = quarantined.length;
        quarantinedEntries.value = quarantined;
        return pending;
    }

    async function tryFlush(postFn) {
        if (typeof postFn !== 'function' || isFlushing.value) {
            return { synced: 0, failed: 0, skipped: true };
        }
        isFlushing.value = true;
        let synced = 0;
        let failed = 0;
        let quarantined = 0;
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
            quarantined = quarantineDepth.value;
        } finally {
            isFlushing.value = false;
        }
        return { synced, failed, quarantined, skipped: false };
    }

    // Eager state listeners: isOnline must reflect navigator.onLine even when
    // no postFn is bound yet (banner renders before tryFlush wiring).
    if (typeof window !== 'undefined') {
        _onOnline = () => {
            isOnline.value = true;
            if (typeof _boundPostFn === 'function') {
                Promise.resolve().then(() => tryFlush(_boundPostFn)).catch(() => {});
            }
        };
        _onOffline = () => { isOnline.value = false; };
        window.addEventListener('online', _onOnline);
        window.addEventListener('offline', _onOffline);
    }

    function bindAutoFlush(postFn) {
        _boundPostFn = typeof postFn === 'function' ? postFn : null;
    }

    function unbindAutoFlush() {
        if (typeof window === 'undefined') return;
        if (_onOnline) window.removeEventListener('online', _onOnline);
        if (_onOffline) window.removeEventListener('offline', _onOffline);
        _onOnline = null;
        _onOffline = null;
        _boundPostFn = null;
    }

    // Only register dispose hook inside a Vue effect scope (component setup).
    // Outside (e.g. unit tests), caller invokes unbindAutoFlush() manually.
    if (getCurrentScope()) onScopeDispose(() => unbindAutoFlush());

    return {
        isOnline,
        queueDepth,
        quarantineDepth,
        quarantinedEntries,
        isFlushing,
        enqueueOrder: _enqueueOrder,
        refresh,
        tryFlush,
        bindAutoFlush,
        unbindAutoFlush,
    };
}
