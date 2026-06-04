import 'fake-indexeddb/auto';

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import axe from 'axe-core';

import KioskAppComponent from '../../resources/js/components/frontend/kiosk/KioskAppComponent.vue';
import KioskOfflineConflictModalComponent from '../../resources/js/components/frontend/kiosk/KioskOfflineConflictModalComponent.vue';

// [GOAL-2026-05-29] The conflict modal renders every user-facing string via $t
// (completed i18n migration — keys present in fr.json). A bare mount throws
// "$t is not a function". Provide a $t stub for the conflict-modal mounts; the
// component requiring callers to supply $t is correct framework behavior, not a bug.
const conflictModalGlobal = { global: { mocks: { $t: (key) => key } } };

vi.mock('../../resources/js/helpers/kioskAnalytics', () => ({
  track: vi.fn(),
}));

class MockBroadcastChannel {
  static channels = new Map();

  constructor(name) {
    this.name = name;
    this.listeners = new Set();
    const peers = MockBroadcastChannel.channels.get(name) || new Set();
    peers.add(this);
    MockBroadcastChannel.channels.set(name, peers);
  }

  postMessage(message) {
    const peers = MockBroadcastChannel.channels.get(this.name) || new Set();
    peers.forEach((peer) => {
      if (peer === this) return;
      peer.listeners.forEach((listener) => listener({ data: message }));
    });
  }

  addEventListener(type, listener) {
    if (type === 'message') {
      this.listeners.add(listener);
    }
  }

  removeEventListener(type, listener) {
    if (type === 'message') {
      this.listeners.delete(listener);
    }
  }

  close() {
    const peers = MockBroadcastChannel.channels.get(this.name) || new Set();
    peers.delete(this);
  }
}

globalThis.BroadcastChannel = MockBroadcastChannel;

function flush() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

function makeKioskAppMethodContext(overrides = {}) {
  const ctx = {
    _pendingStaleItemIds: new Set(),
    _staleToastDebounceTimer: null,
    _showToast: vi.fn(),
    $t: vi.fn((key, params = {}) => {
      if (key === 'kiosk.offline.stale_multiple') {
        return `${params.count} produits indisponibles...`;
      }
      return 'Produit indisponible...';
    }),
    $store: {
      commit: vi.fn(),
      dispatch: vi.fn(() => Promise.resolve()),
      getters: { 'kioskCart/branchId': 7 },
      state: { kioskCart: { branchId: 7 } },
    },
    pruneOfflineQueueOnAvailabilityChanged: vi.fn(() => Promise.resolve({
      updatedEntries: 0,
      entries: [],
    })),
    refreshOfflineConflictEntries: vi.fn(() => Promise.resolve()),
    showOfflineConflictCta: false,
    ...overrides,
  };

  ctx._normalizeBranchId = KioskAppComponent.methods._normalizeBranchId.bind(ctx);
  ctx._getActiveBranchId = KioskAppComponent.methods._getActiveBranchId.bind(ctx);
  ctx._flushStaleToast = KioskAppComponent.methods._flushStaleToast.bind(ctx);
  ctx._scheduleStaleToast = KioskAppComponent.methods._scheduleStaleToast.bind(ctx);
  ctx._handleItemAvailabilityChanged = KioskAppComponent.methods._handleItemAvailabilityChanged.bind(ctx);
  ctx._handleCatalogChanged = KioskAppComponent.methods._handleCatalogChanged.bind(ctx);

  return ctx;
}

function makeSharedQueueDbMockFactory() {
  const storage = new Map();
  const clone = (value) => (value === undefined ? undefined : JSON.parse(JSON.stringify(value)));

  return () => ({
    getQueueEntry: vi.fn(async (key) => (storage.has(key) ? clone(storage.get(key)) : null)),
    setQueueEntry: vi.fn(async (key, value) => {
      storage.set(key, clone(value));
      return clone(value);
    }),
    delQueueEntry: vi.fn(async (key) => {
      storage.delete(key);
      return null;
    }),
    clearQueueEntries: vi.fn(async () => {
      storage.clear();
      return null;
    }),
    isIndexedDbReady: vi.fn(() => true),
  });
}

async function loadQueueModule(tag = 'default', mockDbFactory = null) {
  vi.resetModules();
  if (mockDbFactory) {
    vi.doMock('../../resources/js/helpers/kioskOfflineQueueDb', mockDbFactory);
  }
  const queue = await import('../../resources/js/helpers/kioskOfflineQueue');
  const analytics = await import('../../resources/js/helpers/kioskAnalytics');
  const db = mockDbFactory ? null : await import('../../resources/js/helpers/kioskOfflineQueueDb');
  return { queue, analytics, db };
}

async function runAxe(el) {
  return axe.run(el, {
    rules: {
      'color-contrast': { enabled: false },
    },
  });
}

describe('kioskOfflineQueue v2', () => {
  beforeEach(async () => {
    localStorage.clear();
    const { db } = await loadQueueModule('reset-suite');
    await db.clearQueueEntries();
    MockBroadcastChannel.channels.clear();
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('computes exponential backoff base delays', async () => {
    const { queue } = await loadQueueModule('delay-base');

    expect(queue.__retryDelayForTests(1, 0.5)).toBe(1000);
    expect(queue.__retryDelayForTests(2, 0.5)).toBe(2000);
    expect(queue.__retryDelayForTests(3, 0.5)).toBe(4000);
  });

  it('applies jitter within +/-20%', async () => {
    const { queue } = await loadQueueModule('delay-jitter');

    expect(queue.__retryDelayForTests(1, 0)).toBe(800);
    expect(queue.__retryDelayForTests(1, 1)).toBe(1200);
    expect(queue.__retryDelayForTests(3, 0.25)).toBeGreaterThanOrEqual(3200);
    expect(queue.__retryDelayForTests(3, 0.25)).toBeLessThanOrEqual(4800);
  });

  it('caps retry delay at 30 seconds even for high attempts', async () => {
    const { queue } = await loadQueueModule('delay-cap');

    expect(queue.__retryDelayForTests(12, 0.5)).toBe(30000);
    expect(queue.__retryDelayForTests(12, 1)).toBe(30000);
  });

  it('syncs queued orders immediately on the first replay attempt', async () => {
    const { queue } = await loadQueueModule('backoff-first-immediate');

    queue.saveOrder({ items: JSON.stringify([{ item_id: 90 }]) }, 'first-try-key');
    const postFn = vi.fn(async () => ({ status: 201 }));
    const result = await queue.syncQueue(postFn);

    expect(result.synced).toBe(1);
    expect(result.failed).toBe(0);
    expect(postFn).toHaveBeenCalledTimes(1);
    expect(queue.getPendingCount()).toBe(0);
  });

  it('refreshes a kiosk cash quote before offline order replay', async () => {
    const { queue } = await loadQueueModule('fresh-quote-replay');

    queue.saveOrder({
      order_type: 25,
      source: 5,
      payment_method: 1,
      is_advance_order: 10,
      items: JSON.stringify([{ item_id: 90, quantity: 1 }]),
    }, 'fresh-quote-key');

    const postFn = vi.fn(async (url, payload) => {
      if (url === 'frontend/order/quote') {
        expect(payload).not.toHaveProperty('quote_token');
        expect(payload).not.toHaveProperty('quote_signature');
        expect(payload).not.toHaveProperty('total');
        return {
          data: {
            data: {
              quote_token: '00000000-0000-4000-8000-000000000123',
              signature: 'a'.repeat(64),
              subtotal: 10,
              discount: 0,
              delivery_charge: 0,
              total_ttc: 11,
            },
          },
        };
      }

      expect(url).toBe('frontend/order');
      expect(payload.quote_token).toBe('00000000-0000-4000-8000-000000000123');
      expect(payload.quote_signature).toBe('a'.repeat(64));
      expect(payload.total).toBe(11);
      return { status: 201 };
    });

    const result = await queue.syncQueue(postFn);

    expect(result.synced).toBe(1);
    expect(result.failed).toBe(0);
    expect(postFn).toHaveBeenCalledTimes(2);
    expect(postFn.mock.calls.map(([url]) => url)).toEqual(['frontend/order/quote', 'frontend/order']);
    expect(queue.getPendingCount()).toBe(0);
  });

  it('backs off only after a failed replay attempt and retries after the delay window', async () => {
    vi.useFakeTimers();
    const sharedDbMock = makeSharedQueueDbMockFactory();
    const { queue, analytics } = await loadQueueModule('backoff-after-failure', sharedDbMock);
    const trackSpy = analytics.track;

    queue.saveOrder({ items: JSON.stringify([{ item_id: 91 }]) }, 'retry-key');
    const postFn = vi.fn()
      .mockRejectedValueOnce({ response: { status: 503 } })
      .mockResolvedValueOnce({ status: 201 });

    const first = await queue.syncQueue(postFn);
    expect(first.synced).toBe(0);
    expect(first.failed).toBe(1);
    expect(postFn).toHaveBeenCalledTimes(1);

    const second = await queue.syncQueue(postFn);
    expect(second.synced).toBe(0);
    expect(second.failed).toBe(0);
    expect(postFn).toHaveBeenCalledTimes(1);
    expect(trackSpy).toHaveBeenCalledWith(
      'offline.queue.v2.backoff_skip',
      expect.objectContaining({ idempotency_key: 'retry-key', attempts: 1 }),
    );

    await vi.advanceTimersByTimeAsync(1500);
    const third = await queue.syncQueue(postFn);

    expect(third.synced).toBe(1);
    expect(queue.getPendingCount()).toBe(0);
    expect(postFn).toHaveBeenCalledTimes(2);
  });

  it('skips immediately when an active lock is already present in the backend', async () => {
    const { queue, db } = await loadQueueModule('manual-lock');
    await db.setQueueEntry('kiosk:offline-queue:lock', {
      owner: 'other-tab',
      expiresAt: Date.now() + 10000,
    });
    queue.saveOrder({ items: JSON.stringify([{ item_id: 101 }]) }, 'locked');

    const result = await queue.syncQueue(vi.fn(async () => ({ status: 201 })));

    expect(result.skippedByLock).toBe(true);
  });

  it('reuses the same in-flight sync promise inside one tab to avoid duplicate replay', async () => {
    const { queue } = await loadQueueModule('single-tab-mutex');
    queue.saveOrder({ items: JSON.stringify([{ item_id: 102 }]) }, 'single-tab-key');

    let calls = 0;
    const postFn = vi.fn(async () => {
      calls += 1;
      await new Promise((resolve) => setTimeout(resolve, 20));
      return { status: 201 };
    });

    await Promise.all([queue.syncQueue(postFn), queue.syncQueue(postFn)]);

    expect(calls).toBe(1);
  });

  it('allows sync again once a stale lock has expired', async () => {
    const { queue, db } = await loadQueueModule('expired-lock');
    await db.setQueueEntry('kiosk:offline-queue:lock', {
      owner: 'expired-owner',
      expiresAt: Date.now() - 1,
    });
    queue.saveOrder({ items: JSON.stringify([{ item_id: 103 }]) }, 'expired-lock-key');

    const result = await queue.syncQueue(vi.fn(async () => ({ status: 201 })));
    expect(result.synced).toBe(1);
  });

  it('refreshes the cross-tab lock heartbeat while a long sync is still running', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-04-21T00:00:00Z'));
    const sharedDbMock = makeSharedQueueDbMockFactory();

    const { queue: queueA } = await loadQueueModule('lock-heartbeat-a', sharedDbMock);
    queueA.saveOrder({ items: JSON.stringify([{ item_id: 104 }]) }, 'heartbeat-key');

    let resolvePost;
    const longPost = vi.fn(() => new Promise((resolve) => {
      resolvePost = resolve;
    }));

    const pendingSync = queueA.syncQueue(longPost);
    await vi.advanceTimersByTimeAsync(0);

    const { queue: queueB } = await loadQueueModule('lock-heartbeat-b', sharedDbMock);
    const otherTabPost = vi.fn(async () => ({ status: 201 }));

    const beforeRefresh = await queueB.syncQueue(otherTabPost);
    expect(beforeRefresh.skippedByLock).toBe(true);

    await vi.advanceTimersByTimeAsync(61_000);
    const whileHeartbeatActive = await queueB.syncQueue(otherTabPost);
    expect(whileHeartbeatActive.skippedByLock).toBe(true);
    expect(otherTabPost).not.toHaveBeenCalled();

    resolvePost({ status: 201 });
    await pendingSync;

    const afterRelease = await queueB.syncQueue(otherTabPost);
    expect(afterRelease.synced).toBe(1);
    expect(otherTabPost).toHaveBeenCalledTimes(1);
  });

  it('marks stale entries when availability changes and deduplicates item ids', async () => {
    const { queue } = await loadQueueModule('stale-mark');
    queue.saveOrder({ items: JSON.stringify([{ item_id: 11 }, { item_id: 12 }]) }, 'stale-a');
    queue.saveOrder({ items: JSON.stringify([{ item_id: 12 }]) }, 'stale-b');
    await flush();

    const first = await queue.markStaleItems({ itemId: 12, branchId: 7 });
    const second = await queue.markStaleItems({ itemId: 12, branchId: 7 });

    expect(first.updatedEntries).toBe(2);
    expect(second.updatedEntries).toBe(0);
    expect((await queue.getStaleEntries()).map((entry) => entry.localKey).sort()).toEqual(['stale-a', 'stale-b']);
  });

  it('does not mark a queued entry stale when the provided branch does not match', async () => {
    const { queue } = await loadQueueModule('stale-branch-scope');
    queue.saveOrder(
      { items: JSON.stringify([{ item_id: 12 }]) },
      'branch-scoped-entry',
      { branchId: 7 },
    );
    await flush();

    const result = await queue.markStaleItems({ itemId: 12, branchId: 8 });

    expect(result.updatedEntries).toBe(0);
    expect(result.markedItems).toBe(0);
    expect(await queue.getStaleEntries()).toEqual([]);
  });

  it('ignores availability events from another branch before marking stale items', async () => {
    const ctx = makeKioskAppMethodContext({
      pruneOfflineQueueOnAvailabilityChanged: vi.fn(() => Promise.resolve({
        updatedEntries: 1,
        entries: [{ localKey: 'stale-entry' }],
      })),
    });

    const result = await ctx._handleItemAvailabilityChanged({
      branchId: 8,
      payload: { id: 55, branch_id: 8 },
    }, 7);

    expect(result).toEqual({ ignored: true, reason: 'branch_mismatch' });
    expect(ctx.$store.commit).not.toHaveBeenCalled();
    expect(ctx.pruneOfflineQueueOnAvailabilityChanged).not.toHaveBeenCalled();
    expect(ctx._showToast).not.toHaveBeenCalled();
  });

  it('refreshes kiosk menu on CatalogChanged for the active branch', async () => {
    const dispatch = vi.fn(() => Promise.resolve());
    const ctx = makeKioskAppMethodContext({
      $store: {
        commit: vi.fn(),
        dispatch,
        getters: { 'kioskCart/branchId': 7 },
        state: { kioskCart: { branchId: 7 } },
      },
    });

    const result = await ctx._handleCatalogChanged({
      branchId: 7,
      payload: { entity_type: 'composer_profile', branch_id: 7 },
    }, 7);

    expect(result).toEqual({ refreshed: true, entityType: 'composer_profile' });
    expect(dispatch).toHaveBeenCalledWith('kioskMenu/fetchMenu', { force: true, branchId: 7 });
  });

  it('ignores CatalogChanged events from another branch', async () => {
    const dispatch = vi.fn(() => Promise.resolve());
    const ctx = makeKioskAppMethodContext({
      $store: {
        commit: vi.fn(),
        dispatch,
        getters: { 'kioskCart/branchId': 7 },
        state: { kioskCart: { branchId: 7 } },
      },
    });

    const result = await ctx._handleCatalogChanged({
      branchId: 8,
      payload: { entity_type: 'composer_profile', branch_id: 8 },
    }, 7);

    expect(result).toEqual({ ignored: true, reason: 'branch_mismatch' });
    expect(dispatch).not.toHaveBeenCalled();
  });

  it('debounces stale-item toasts into a single aggregated warning', async () => {
    vi.useFakeTimers();
    const toastSpy = vi.fn();
    const ctx = makeKioskAppMethodContext({ _showToast: toastSpy });

    for (let itemId = 1; itemId <= 5; itemId += 1) {
      ctx._scheduleStaleToast(itemId);
      if (itemId < 5) {
        await vi.advanceTimersByTimeAsync(25);
      }
    }

    await vi.advanceTimersByTimeAsync(700);
    expect(toastSpy).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(200);
    expect(toastSpy).toHaveBeenCalledTimes(1);
    expect(toastSpy).toHaveBeenCalledWith('5 produits indisponibles...', 'warning', 6000);
  });

  it('force retry clears stale markers and cancel removes the queued command', async () => {
    const { queue } = await loadQueueModule('stale-resolve');
    queue.saveOrder({ items: JSON.stringify([{ item_id: 77 }]) }, 'resolve-me');
    await queue.markStaleItems({ itemId: 77, branchId: 4 });

    expect((await queue.getStaleEntries())).toHaveLength(1);
    await queue.forceRetryEntry('resolve-me');
    expect((await queue.getStaleEntries())).toHaveLength(0);

    await queue.cancelStaleEntry('resolve-me');
    expect(queue.getPendingCount()).toBe(0);
  });

  it('emits a quota event and keeps the order in memory when persistence fails', async () => {
    const quotaError = new DOMException('Quota reached', 'QuotaExceededError');
    const quotaListener = vi.fn();
    window.addEventListener('kiosk-offline-queue:quota-exceeded', quotaListener);

    const { queue } = await loadQueueModule('quota', () => ({
      getQueueEntry: vi.fn(async () => []),
      setQueueEntry: vi.fn(async (key) => {
        if (key === 'kiosk:offline-queue:v2') throw quotaError;
        return null;
      }),
      delQueueEntry: vi.fn(async () => null),
      clearQueueEntries: vi.fn(async () => null),
      isIndexedDbReady: vi.fn(() => true),
    }));

    queue.saveOrder({ items: JSON.stringify([{ item_id: 88 }]) }, 'quota-key');
    await flush();

    expect(queue.getPendingCount()).toBe(1);
    expect(quotaListener).toHaveBeenCalledTimes(1);
    window.removeEventListener('kiosk-offline-queue:quota-exceeded', quotaListener);
  });

  it('renders the conflict modal entries and emits cancel/force events', async () => {
    const wrapper = mount(KioskOfflineConflictModalComponent, {
      ...conflictModalGlobal,
      attachTo: document.body,
      props: {
        modelValue: true,
        entries: [
          { localKey: 'entry-1', savedAt: Date.now(), staleItems: [12, 13] },
        ],
      },
    });

    expect(wrapper.find('[data-testid="kiosk-offline-conflict-list"]').exists()).toBe(true);
    await wrapper.find('[data-testid="kiosk-offline-cancel-entry-1"]').trigger('click');
    await wrapper.find('[data-testid="kiosk-offline-force-entry-1"]').trigger('click');

    expect(wrapper.emitted('cancel-entry')[0]).toEqual(['entry-1']);
    expect(wrapper.emitted('force-entry')[0]).toEqual(['entry-1']);
    wrapper.unmount();
  });

  it('keeps focus trapped inside the conflict modal', async () => {
    const wrapper = mount(KioskOfflineConflictModalComponent, {
      ...conflictModalGlobal,
      attachTo: document.body,
      props: {
        modelValue: true,
        entries: [
          { localKey: 'entry-2', savedAt: Date.now(), staleItems: [77] },
        ],
      },
    });
    await flush();

    const buttons = wrapper.findAll('button');
    expect(buttons.length).toBeGreaterThanOrEqual(3);
    buttons[buttons.length - 1].element.focus();
    await wrapper.find('[role="dialog"]').trigger('keydown', { key: 'Tab' });

    expect(document.activeElement).toBe(buttons[0].element);
    wrapper.unmount();
  });

  it('is accessible to axe when opened', async () => {
    const wrapper = mount(KioskOfflineConflictModalComponent, {
      ...conflictModalGlobal,
      attachTo: document.body,
      props: {
        modelValue: true,
        entries: [
          { localKey: 'entry-3', savedAt: Date.now(), staleItems: [78] },
        ],
      },
    });
    await flush();

    const result = await runAxe(wrapper.element);
    expect(result.violations).toEqual([]);
    wrapper.unmount();
  });

  it('shows an empty state when there are no conflict entries', async () => {
    const wrapper = mount(KioskOfflineConflictModalComponent, {
      ...conflictModalGlobal,
      props: {
        modelValue: true,
        entries: [],
      },
    });

    expect(wrapper.find('[data-testid="kiosk-offline-conflict-empty"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('tracks modal opening via the emitted hook', async () => {
    const wrapper = mount(KioskOfflineConflictModalComponent, {
      ...conflictModalGlobal,
      props: {
        modelValue: false,
        entries: [{ localKey: 'entry-4', savedAt: Date.now(), staleItems: [79] }],
      },
    });

    await wrapper.setProps({ modelValue: true });
    expect(wrapper.emitted('opened')).toHaveLength(1);
    wrapper.unmount();
  });
});
