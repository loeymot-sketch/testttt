import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMetricsBatcher } from '../../resources/js/services/MetricsBatcher.js';

function emitter() {
  const handlers = new Map();
  return {
    on(event, handler) {
      if (!handlers.has(event)) handlers.set(event, new Set());
      handlers.get(event).add(handler);
      return () => handlers.get(event)?.delete(handler);
    },
    emit(event, payload) {
      handlers.get(event)?.forEach((handler) => handler(payload));
    },
    count(event) {
      return handlers.get(event)?.size || 0;
    },
  };
}

describe('MetricsBatcher flush lifecycle', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(console, 'warn').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  it('batches and flushes after intervalMs', async () => {
    const ws = emitter();
    const fetcher = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    const batcher = createMetricsBatcher({ wsService: ws, endpoint: '/api/admin/observability/client-metrics', fetcher, intervalMs: 1000 });

    ws.emit('reconnect_storm', { delay_ms: 500, attempts_in_window: 3 });
    batcher.start();
    await vi.advanceTimersByTimeAsync(1000);

    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(JSON.parse(fetcher.mock.calls[0][1].body).metrics).toHaveLength(1);
    expect(batcher.getBufferSize()).toBe(0);
  });

  it('caps buffer at maxBatch entries', () => {
    const ws = emitter();
    const batcher = createMetricsBatcher({ wsService: ws, endpoint: '/metrics', fetcher: vi.fn(), maxBatch: 2 });

    ws.emit('reconnect_storm', { delay_ms: 1, attempts_in_window: 1 });
    ws.emit('reconnect_storm', { delay_ms: 2, attempts_in_window: 2 });
    ws.emit('reconnect_storm', { delay_ms: 3, attempts_in_window: 3 });

    expect(batcher.getBufferSize()).toBe(2);
  });

  it('graceful 5xx warns once and keeps buffer', async () => {
    const ws = emitter();
    const fetcher = vi.fn().mockResolvedValue({ ok: false, status: 500 });
    const batcher = createMetricsBatcher({ wsService: ws, endpoint: '/metrics', fetcher });

    ws.emit('reconnect_storm', { delay_ms: 100, attempts_in_window: 4 });
    await batcher._flushNow();
    await batcher._flushNow();

    expect(console.warn).toHaveBeenCalledTimes(1);
    expect(batcher.getBufferSize()).toBe(1);
  });

  // [Audit T T-MISS-E] On flush failure (network reject), the spliced batch
  // must be restored to the buffer in the SAME order it left — otherwise the
  // next successful POST would deliver metrics out-of-order, breaking the
  // dashboard's chronological assumptions for percentile rollups.
  it('restores buffer in original order when flush rejects', async () => {
    const ws = emitter();
    let attempt = 0;
    const fetcher = vi.fn().mockImplementation(() => {
      attempt++;
      if (attempt === 1) return Promise.reject(new Error('network down'));
      return Promise.resolve({ ok: true, status: 202 });
    });
    const batcher = createMetricsBatcher({ wsService: ws, endpoint: '/metrics', fetcher });

    ws.emit('reconnect_storm', { delay_ms: 1, attempts_in_window: 1 });
    ws.emit('reconnect_storm', { delay_ms: 2, attempts_in_window: 2 });
    ws.emit('reconnect_storm', { delay_ms: 3, attempts_in_window: 3 });

    await batcher._flushNow();
    expect(batcher.getBufferSize()).toBe(3);

    await batcher._flushNow();
    const sentBody = JSON.parse(fetcher.mock.calls[1][1].body);
    expect(sentBody.metrics.map((m) => m.value)).toEqual([1, 2, 3]);
  });

  it('destroy cleans the timer and unsubscribes from services', () => {
    const ws = emitter();
    const kds = emitter();
    const batcher = createMetricsBatcher({ wsService: ws, kdsSyncService: kds, endpoint: '/metrics', fetcher: vi.fn(), intervalMs: 1000 });

    batcher.start();
    expect(ws.count('reconnect_storm')).toBe(1);
    expect(kds.count('state_change')).toBe(1);

    batcher.destroy();

    expect(ws.count('reconnect_storm')).toBe(0);
    expect(kds.count('state_change')).toBe(0);
  });
});
