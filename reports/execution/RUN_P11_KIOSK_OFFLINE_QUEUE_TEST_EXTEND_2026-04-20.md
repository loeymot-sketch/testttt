# RUN — P11_KIOSK_OFFLINE_QUEUE_TEST_EXTEND

**Date:** 2026-04-20  
**Plan:** `tasks/execute-2026-04-20/V10_02_P11_KIOSK_OFFLINE_QUEUE_TEST_EXTEND.md`  
**Status:** SUCCESS

## Summary

- Extended `tests/js/kioskOfflineQueue.spec.js` with import `getAbandonedCount` and `describe('[V10 #2] resilience hardening')` containing 4 new tests (partial reconnect, abandoned count, idempotency header, 24h prune).
- `resources/js/helpers/kioskOfflineQueue.js` — **not modified** (per plan).

## Vitest

```text
 RUN  v1.6.1 /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt

 ✓ tests/js/kioskOfflineQueue.spec.js  (9 tests) 27ms

 Test Files  1 passed (1)
      Tests  9 passed (9)
   Start at  22:14:04
   Duration  545ms (transform 32ms, setup 0ms, collect 30ms, tests 27ms, environment 149ms, prepare 47ms)
```

**Count:** 9 tests passed (5 existing + 4 new).

## Deviation

None. Pruning test used 25h stale `savedAt` as in the plan; helper prunes with `savedAt < cutoff` where `cutoff = now - 24h`, so 25h-old synced entries are removed as expected.

## Diff — `tests/js/kioskOfflineQueue.spec.js`

```diff
diff --git a/tests/js/kioskOfflineQueue.spec.js b/tests/js/kioskOfflineQueue.spec.js
index 2482b6344..ce355dd64 100644
--- a/tests/js/kioskOfflineQueue.spec.js
+++ b/tests/js/kioskOfflineQueue.spec.js
@@ -1,6 +1,7 @@
 import { beforeEach, describe, expect, it, vi } from 'vitest';
 import {
   clearQueue,
+  getAbandonedCount,
   getPendingCount,
   saveOrder,
   syncQueue,
@@ -91,4 +92,100 @@ describe('kioskOfflineQueue', () => {
       expect(matched).toBe(true);
     });
   });
+
+  // ─── [V10 #2] Resilience hardening — partial reconnect, abandoned count, idempotency replay, pruning ───
+  describe('[V10 #2] resilience hardening', () => {
+    it('partial reconnect: 1 success + 2 failures leaves 2 pending', async () => {
+      saveOrder({ items: [{ item_id: 1 }] }, 'partial_a');
+      saveOrder({ items: [{ item_id: 2 }] }, 'partial_b');
+      saveOrder({ items: [{ item_id: 3 }] }, 'partial_c');
+
+      let callIdx = 0;
+      const flakyPost = vi.fn(async () => {
+        callIdx += 1;
+        if (callIdx === 1) return { status: 201 };
+        const err = new Error('network');
+        err.code = 'ECONNREFUSED';
+        throw err;
+      });
+
+      const result = await syncQueue(flakyPost);
+      expect(result.synced).toBe(1);
+      expect(result.failed).toBe(2);
+      expect(getPendingCount()).toBe(2);
+      // The first entry should be marked synced and the others retry-eligible.
+      expect(flakyPost).toHaveBeenCalledTimes(3);
+    });
+
+    it('getAbandonedCount stays 0 below 10 attempts then increments at 10', async () => {
+      saveOrder({ items: [{ item_id: 99 }] }, 'abandon_check');
+
+      const failingPost = vi.fn(async () => {
+        const err = new Error('network');
+        err.code = 'ECONNREFUSED';
+        throw err;
+      });
+
+      // 9 attempts → still 0 abandoned
+      for (let i = 0; i < 9; i++) {
+        await syncQueue(failingPost);
+      }
+      expect(getAbandonedCount()).toBe(0);
+
+      // 10th attempt → entry is marked abandoned
+      await syncQueue(failingPost);
+      expect(getAbandonedCount()).toBe(1);
+    });
+
+    it('replay reuses original idempotency key as X-Idempotency-Key header', async () => {
+      const ORIGINAL_KEY = 'idemp_original_xyz';
+      saveOrder({ items: [{ item_id: 7 }] }, ORIGINAL_KEY);
+
+      const capturedConfigs = [];
+      const captureFn = vi.fn(async (url, payload, config) => {
+        capturedConfigs.push(config);
+        return { status: 201 };
+      });
+
+      await syncQueue(captureFn);
+
+      expect(captureFn).toHaveBeenCalledTimes(1);
+      expect(capturedConfigs[0]).toBeDefined();
+      expect(capturedConfigs[0].headers).toBeDefined();
+      expect(capturedConfigs[0].headers['X-Idempotency-Key']).toBe(ORIGINAL_KEY);
+    });
+
+    it('synced entries older than 24h are pruned on next syncQueue run', async () => {
+      // Manually craft a stale entry by writing directly to localStorage
+      // (mirrors what kioskOfflineQueue.js _save() does internally).
+      const QUEUE_KEY = 'kiosk_offline_queue_v1';
+      const TWENTY_FIVE_HOURS_AGO = Date.now() - (25 * 60 * 60 * 1000);
+      const staleEntry = {
+        localKey: 'stale_synced',
+        payload: { items: [] },
+        savedAt: TWENTY_FIVE_HOURS_AGO,
+        attempts: 1,
+        synced: true,
+        syncedAt: TWENTY_FIVE_HOURS_AGO + 1000,
+      };
+      const freshEntry = {
+        localKey: 'fresh_pending',
+        payload: { items: [{ item_id: 42 }] },
+        savedAt: Date.now(),
+        attempts: 0,
+        synced: false,
+      };
+      localStorage.setItem(QUEUE_KEY, JSON.stringify([staleEntry, freshEntry]));
+
+      // Trigger a syncQueue run; the fresh pending entry will be POSTed once.
+      const okPost = vi.fn(async () => ({ status: 201 }));
+      await syncQueue(okPost);
+
+      // After the run: stale (synced + savedAt > 24h) is pruned, fresh is now synced.
+      const finalQueue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
+      expect(finalQueue.length).toBe(1);
+      expect(finalQueue[0].localKey).toBe('fresh_pending');
+      expect(finalQueue[0].synced).toBe(true);
+    });
+  });
 });
```
