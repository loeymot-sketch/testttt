import 'fake-indexeddb/auto';

import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../resources/js/helpers/kioskAnalytics', () => ({
  track: vi.fn(),
}));

function flush() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

async function loadQueueModule(tag = 'migration') {
  vi.resetModules();
  const queue = await import('../../resources/js/helpers/kioskOfflineQueue');
  const db = await import('../../resources/js/helpers/kioskOfflineQueueDb');
  return { queue, db };
}

describe('kioskOfflineQueue v1 -> v2 migration', () => {
  beforeEach(async () => {
    localStorage.clear();
    const { db } = await loadQueueModule('reset');
    await db.clearQueueEntries();
    vi.restoreAllMocks();
  });

  it('migrates legacy localStorage entries into IndexedDB and clears v1 key', async () => {
    const legacyEntries = [
      {
        localKey: 'legacy-a',
        payload: { items: JSON.stringify([{ item_id: 10 }]) },
        savedAt: 1700000000000,
        attempts: 0,
        synced: false,
      },
      {
        localKey: 'legacy-b',
        payload: { items: JSON.stringify([{ item_id: 11 }]) },
        savedAt: 1700000000100,
        attempts: 2,
        synced: false,
      },
    ];
    localStorage.setItem('kiosk_offline_queue_v1', JSON.stringify(legacyEntries));

    const { queue, db } = await loadQueueModule('migrates');
    const entries = await queue.getStaleEntries();
    const storedQueue = await db.getQueueEntry('kiosk:offline-queue:v2');

    expect(entries).toEqual([]);
    expect(Array.isArray(storedQueue)).toBe(true);
    expect(storedQueue).toHaveLength(2);
    expect(storedQueue[0].localKey).toBe('legacy-a');
    expect(storedQueue[0].attempts).toBe(1);
    expect(storedQueue[1].attempts).toBe(2);
    expect(localStorage.getItem('kiosk_offline_queue_v1')).toBeNull();
  });

  it('is idempotent when a crash left v1 data behind after v2 write', async () => {
    const { db } = await loadQueueModule('seed-idb');
    await db.setQueueEntry('kiosk:offline-queue:v2', [
      {
        localKey: 'idb-only',
        payload: { items: JSON.stringify([{ item_id: 20 }]) },
        savedAt: 1700000000000,
        attempts: 3,
        lastAttemptAt: 1700000000000,
        abandoned: false,
        abandonedAt: null,
        abandonedReason: null,
        staleItems: [],
      },
    ]);
    localStorage.setItem('kiosk_offline_queue_v1', JSON.stringify([
      {
        localKey: 'legacy-duplicate',
        payload: { items: JSON.stringify([{ item_id: 21 }]) },
        savedAt: 1700000000001,
        attempts: 1,
        synced: false,
      },
    ]));

    const { queue } = await loadQueueModule('idempotent');
    await queue.getStaleEntries();
    const storedAgain = await db.getQueueEntry('kiosk:offline-queue:v2');

    expect(storedAgain).toHaveLength(1);
    expect(storedAgain[0].localKey).toBe('idb-only');
    expect(localStorage.getItem('kiosk_offline_queue_v1')).toBeNull();
  });

  it('keeps legacy payloads replayable after upgrade', async () => {
    localStorage.setItem('kiosk_offline_queue_v1', JSON.stringify([
      {
        localKey: 'legacy-sync',
        payload: { items: JSON.stringify([{ item_id: 30 }]), source: 5 },
        savedAt: 1700000001000,
        attempts: 0,
        synced: false,
      },
    ]));

    const { queue } = await loadQueueModule('replayable');
    const postFn = vi.fn(async () => ({ status: 201 }));
    const result = await queue.syncQueue(postFn);

    expect(result.synced).toBe(1);
    expect(postFn).toHaveBeenCalledTimes(1);
    expect(postFn.mock.calls[0][1]).toEqual({
      items: JSON.stringify([{ item_id: 30 }]),
      source: 5,
    });
  });

  it('preserves abandoned entries and stale markers already present in v2', async () => {
    const { db } = await loadQueueModule('seed-v2-state');
    await db.setQueueEntry('kiosk:offline-queue:v2', [
      {
        localKey: 'stale-and-abandoned',
        payload: { items: JSON.stringify([{ item_id: 41 }]) },
        savedAt: 1700000000000,
        attempts: 10,
        lastAttemptAt: 1700000005000,
        abandoned: true,
        abandonedAt: 1700000006000,
        abandonedReason: 'network',
        staleItems: [41],
      },
    ]);

    const { queue } = await loadQueueModule('preserve-state');
    const staleEntries = await queue.getStaleEntries();

    expect(queue.getAbandonedCount()).toBe(1);
    expect(staleEntries).toHaveLength(0);
    expect(queue.__unsafeGetQueueForTests()[0].abandonedReason).toBe('network');
  });

  it('removes entries cancelled after migration from the persisted v2 queue', async () => {
    localStorage.setItem('kiosk_offline_queue_v1', JSON.stringify([
      {
        localKey: 'legacy-cancel',
        payload: { items: JSON.stringify([{ item_id: 50 }]) },
        savedAt: 1700000010000,
        attempts: 1,
        synced: false,
      },
    ]));

    const { queue, db } = await loadQueueModule('cancel-after-migrate');
    await queue.getStaleEntries();
    await queue.cancelStaleEntry('legacy-cancel');
    await flush();

    const storedQueue = await db.getQueueEntry('kiosk:offline-queue:v2');
    expect(storedQueue).toEqual([]);
  });
});
