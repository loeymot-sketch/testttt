import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  clearQueue,
  getPendingCount,
  saveOrder,
  syncQueue,
} from '../../resources/js/helpers/kioskOfflineQueue';

describe('kioskOfflineQueue', () => {
  beforeEach(() => {
    clearQueue();
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
});
