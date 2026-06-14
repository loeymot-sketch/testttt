import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest';

vi.mock('../../resources/js/helpers/kioskAnalytics', () => ({ track: vi.fn() }));

import * as queue from '../../resources/js/helpers/kioskOfflineQueue.js';

function deferred() {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { promise, resolve };
}

beforeEach(() => { queue.clearQueue(); });
afterEach(() => { vi.clearAllMocks(); });

describe('F4 · syncQueue does not drop an order enqueued mid-sync', () => {
  it('an order saved via saveOrder() while a replay POST is in flight survives', async () => {
    queue.saveOrder({ order_type: 25, source: 5, payment_method: 1 }, 'key-A', { branchId: 1 });

    const reached = deferred(); // fires when the replay POST is entered (sync now blocked)
    const gate = deferred();    // releases the blocked POST
    let first = true;
    const postFn = vi.fn(async (url) => {
      if (url === 'frontend/order/quote') {
        // valid fresh quote so the replay proceeds to the order POST
        return { data: { data: { quote_token: 't', signature: 's', total_ttc: 10, subtotal: 8, discount: 0, delivery_charge: 0 } } };
      }
      if (url === 'frontend/order' && first) {
        first = false;
        reached.resolve();
        await gate.promise; // simulate a slow network
      }
      return { data: { data: { id: 1 } } };
    });

    const syncing = queue.syncQueue(postFn);
    await reached.promise;                                   // sync is blocked inside the order POST
    queue.saveOrder({ order_type: 10, source: 5, payment_method: 1 }, 'key-B', { branchId: 1 }); // enqueue mid-sync
    gate.resolve();
    await syncing;

    const keys = queue.__unsafeGetQueueForTests().map((e) => e.localKey);
    expect(keys, 'mid-sync enqueued order (key-B) must survive').toContain('key-B');
    expect(keys, 'replayed order (key-A) must be drained').not.toContain('key-A');
  });

  it('common path (no concurrent enqueue) still drains synced entries', async () => {
    queue.saveOrder({ order_type: 25, source: 5, payment_method: 1 }, 'key-solo', { branchId: 1 });
    const postFn = vi.fn(async (url) => {
      if (url === 'frontend/order/quote') return { data: { data: { quote_token: 't', signature: 's', total_ttc: 10, subtotal: 8, discount: 0, delivery_charge: 0 } } };
      return { data: { data: { id: 7 } } };
    });
    const res = await queue.syncQueue(postFn);
    expect(res.synced).toBe(1);
    expect(queue.__unsafeGetQueueForTests().map((e) => e.localKey)).not.toContain('key-solo');
  });
});
