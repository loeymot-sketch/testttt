import 'fake-indexeddb/auto';

import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  clearQueue,
  getAbandonedCount,
  getPendingCount,
  saveOrder,
  syncQueue,
} from '../../resources/js/helpers/kioskOfflineQueue';
import * as analytics from '../../resources/js/helpers/kioskAnalytics';
import { clearQueueEntries, getQueueEntry } from '../../resources/js/helpers/kioskOfflineQueueDb';

function advanceClock(ms) {
  const next = Date.now() + ms;
  vi.spyOn(Date, 'now').mockImplementation(() => next);
}

describe('kioskOfflineQueue', () => {
  beforeEach(async () => {
    clearQueue();
    await clearQueueEntries();
    localStorage.clear();
    vi.restoreAllMocks();
  });

  it('stores queued orders and reports pending count', () => {
    saveOrder({ items: [] }, 'offline_key_1');
    expect(getPendingCount()).toBe(1);
  });

  it('reuses the same in-flight sync promise to avoid duplicate replay', async () => {
    saveOrder({ items: [{ item_id: 1 }] }, 'offline_key_mutex');
    advanceClock(1500);

    let calls = 0;
    const postFn = vi.fn(async () => {
      calls += 1;
      await new Promise((resolve) => setTimeout(resolve, 20));
      return { status: 201 };
    });

    await Promise.all([syncQueue(postFn), syncQueue(postFn)]);

    expect(calls).toBe(1);
    expect(getPendingCount()).toBe(0);
  });

  // ─── [T14b] Offline lifecycle observability ───────────────────────────────
  describe('[T14b] analytics observability', () => {
    it('emits offline.queued when an order is enqueued', () => {
      const trackSpy = vi.spyOn(analytics, 'track').mockImplementation(() => true);

      saveOrder({ items: [{ item_id: 42 }] }, 'offline_key_track');

      const matched = trackSpy.mock.calls.some(
        ([name, payload]) =>
          name === 'offline.queued'
          && payload
          && payload.idempotency_key === 'offline_key_track'
          && typeof payload.queue_size === 'number',
      );
      expect(matched).toBe(true);
    });

    it('emits offline.replayed when a sync attempt succeeds', async () => {
      const trackSpy = vi.spyOn(analytics, 'track').mockImplementation(() => true);
      saveOrder({ items: [{ item_id: 1 }] }, 'offline_key_replay');
      advanceClock(1500);

      await syncQueue(vi.fn(async () => ({ status: 201 })));

      const matched = trackSpy.mock.calls.some(
        ([name, payload]) =>
          name === 'offline.replayed'
          && payload
          && payload.idempotency_key === 'offline_key_replay',
      );
      expect(matched).toBe(true);
    });

    it('emits offline.abandoned after 10 failed attempts', async () => {
      const trackSpy = vi.spyOn(analytics, 'track').mockImplementation(() => true);
      saveOrder({ items: [{ item_id: 1 }] }, 'offline_key_abandon');

      const failingPost = vi.fn(async () => {
        const err = new Error('network');
        err.code = 'ECONNREFUSED';
        throw err;
      });

      // 10 sync runs → entry.attempts hits 10 → abandoned.
      for (let i = 0; i < 10; i++) {
        advanceClock(35000);
        await syncQueue(failingPost);
      }

      const matched = trackSpy.mock.calls.some(
        ([name, payload]) =>
          name === 'offline.abandoned'
          && payload
          && payload.idempotency_key === 'offline_key_abandon'
          && payload.retry_count === 10,
      );
      expect(matched).toBe(true);
    });
  });

  // ─── [V10 #2] Resilience hardening — partial reconnect, abandoned count, idempotency replay, pruning ───
  describe('[V10 #2] resilience hardening', () => {
    it('partial reconnect: 1 success + 2 failures leaves 2 pending', async () => {
      saveOrder({ items: [{ item_id: 1 }] }, 'partial_a');
      saveOrder({ items: [{ item_id: 2 }] }, 'partial_b');
      saveOrder({ items: [{ item_id: 3 }] }, 'partial_c');
      advanceClock(1500);

      let callIdx = 0;
      const flakyPost = vi.fn(async () => {
        callIdx += 1;
        if (callIdx === 1) return { status: 201 };
        const err = new Error('network');
        err.code = 'ECONNREFUSED';
        throw err;
      });

      const result = await syncQueue(flakyPost);
      expect(result.synced).toBe(1);
      expect(result.failed).toBe(2);
      expect(getPendingCount()).toBe(2);
      // The first entry should be marked synced and the others retry-eligible.
      expect(flakyPost).toHaveBeenCalledTimes(3);
    });

    it('getAbandonedCount stays 0 below 10 attempts then increments at 10', async () => {
      saveOrder({ items: [{ item_id: 99 }] }, 'abandon_check');

      const failingPost = vi.fn(async () => {
        const err = new Error('network');
        err.code = 'ECONNREFUSED';
        throw err;
      });

      // 8 replay attempts after the initial offline failure → still 0 abandoned
      for (let i = 0; i < 8; i++) {
        advanceClock(35000);
        await syncQueue(failingPost);
      }
      expect(getAbandonedCount()).toBe(0);

      // 9th replay attempt (= 10th total attempt incl. initial failure) → abandoned
      advanceClock(35000);
      await syncQueue(failingPost);
      expect(getAbandonedCount()).toBe(1);
    });

    it('replay reuses original idempotency key as X-Idempotency-Key header', async () => {
      const ORIGINAL_KEY = 'idemp_original_xyz';
      saveOrder({ items: [{ item_id: 7 }] }, ORIGINAL_KEY);
      advanceClock(1500);

      const capturedConfigs = [];
      const captureFn = vi.fn(async (url, payload, config) => {
        capturedConfigs.push(config);
        return { status: 201 };
      });

      await syncQueue(captureFn);

      expect(captureFn).toHaveBeenCalledTimes(1);
      expect(capturedConfigs[0]).toBeDefined();
      expect(capturedConfigs[0].headers).toBeDefined();
      expect(capturedConfigs[0].headers['X-Idempotency-Key']).toBe(ORIGINAL_KEY);
    });

    it('synced entries older than 24h are pruned on next syncQueue run', async () => {
      // Manually craft a stale entry by writing directly to localStorage
      // (mirrors what kioskOfflineQueue.js _save() does internally).
      const QUEUE_KEY = 'kiosk_offline_queue_v1';
      const TWENTY_FIVE_HOURS_AGO = Date.now() - (25 * 60 * 60 * 1000);
      const staleEntry = {
        localKey: 'stale_synced',
        payload: { items: [] },
        savedAt: TWENTY_FIVE_HOURS_AGO,
        attempts: 1,
        synced: true,
        syncedAt: TWENTY_FIVE_HOURS_AGO + 1000,
      };
      const freshEntry = {
        localKey: 'fresh_pending',
        payload: { items: [{ item_id: 42 }] },
        savedAt: Date.now(),
        attempts: 0,
        synced: false,
      };
      localStorage.setItem(QUEUE_KEY, JSON.stringify([staleEntry, freshEntry]));
      advanceClock(35000);

      // Trigger a syncQueue run; the fresh pending entry will be POSTed once.
      const okPost = vi.fn(async () => ({ status: 201 }));
      await syncQueue(okPost);

      // After the run: the stale synced entry is discarded during migration and
      // the fresh pending entry is removed after a successful replay.
      const finalQueue = await getQueueEntry('kiosk:offline-queue:v2');
      expect(finalQueue).toEqual([]);
    });
  });
});
