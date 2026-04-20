import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  clearQueue,
  getPendingCount,
  saveOrder,
  syncQueue,
} from '../../resources/js/helpers/kioskOfflineQueue';
import * as analytics from '../../resources/js/helpers/kioskAnalytics';

describe('kioskOfflineQueue', () => {
  beforeEach(() => {
    clearQueue();
    vi.restoreAllMocks();
  });

  it('stores queued orders and reports pending count', () => {
    saveOrder({ items: [] }, 'offline_key_1');
    expect(getPendingCount()).toBe(1);
  });

  it('reuses the same in-flight sync promise to avoid duplicate replay', async () => {
    saveOrder({ items: [{ item_id: 1 }] }, 'offline_key_mutex');

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
});
