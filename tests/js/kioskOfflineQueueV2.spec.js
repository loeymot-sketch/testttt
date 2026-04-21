import 'fake-indexeddb/auto';

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import axe from 'axe-core';

import KioskOfflineConflictModalComponent from '../../resources/js/components/frontend/kiosk/KioskOfflineConflictModalComponent.vue';

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

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
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

  it('skips sync while backoff window is still active and tracks the skip', async () => {
    const { queue, analytics } = await loadQueueModule('backoff-skip');
    const trackSpy = analytics.track;

    queue.saveOrder({ items: JSON.stringify([{ item_id: 90 }]) }, 'skip-key');
    const postFn = vi.fn(async () => ({ status: 201 }));
    const result = await queue.syncQueue(postFn);

    expect(result.synced).toBe(0);
    expect(result.failed).toBe(0);
    expect(postFn).not.toHaveBeenCalled();
    expect(trackSpy).toHaveBeenCalledWith(
      'offline.queue.v2.backoff_skip',
      expect.objectContaining({ idempotency_key: 'skip-key', attempts: 1 }),
    );
  });

  it('retries successfully once the backoff window has elapsed', async () => {
    const { queue } = await loadQueueModule('backoff-pass');

    queue.saveOrder({ items: JSON.stringify([{ item_id: 91 }]) }, 'retry-key');
    await sleep(1200);
    const postFn = vi.fn(async () => ({ status: 201 }));
    const result = await queue.syncQueue(postFn);

    expect(result.synced).toBe(1);
    expect(queue.getPendingCount()).toBe(0);
    expect(postFn).toHaveBeenCalledTimes(1);
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
    await sleep(1200);

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
    await sleep(1200);

    const result = await queue.syncQueue(vi.fn(async () => ({ status: 201 })));
    expect(result.synced).toBe(1);
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
