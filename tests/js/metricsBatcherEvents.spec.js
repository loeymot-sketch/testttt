import { describe, expect, it, vi } from 'vitest';
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
  };
}

async function flushedMetricFrom(service, emit) {
  const fetcher = vi.fn().mockResolvedValue({ ok: true, status: 202 });
  const batcher = createMetricsBatcher({ ...service, endpoint: '/metrics', fetcher });
  emit();
  await batcher._flushNow();
  return JSON.parse(fetcher.mock.calls[0][1].body).metrics[0];
}

describe('MetricsBatcher event mapping', () => {
  it('records reconnect_storm with delay_ms and attempts_in_window', async () => {
    const ws = emitter();
    const metric = await flushedMetricFrom({ wsService: ws }, () => {
      ws.emit('reconnect_storm', { delay_ms: 1500, attempts_in_window: 8 });
    });

    expect(metric.type).toBe('ws.reconnect_storm');
    expect(metric.value).toBe(1500);
    expect(metric.labels.attempts_in_window).toBe(8);
  });

  it('records state_change disconnect when previous connected and current not connected', async () => {
    const ws = emitter();
    const metric = await flushedMetricFrom({ wsService: ws }, () => {
      ws.emit('state_change', { previous: 'CONNECTED', current: 'DISCONNECTED' });
    });

    expect(metric.type).toBe('ws.disconnect_event');
    expect(metric.value).toBe(1);
    expect(metric.labels.previous).toBe('CONNECTED');
    expect(metric.labels.current).toBe('DISCONNECTED');
  });

  it('records kds sync fallback only when reason changes for a transition', async () => {
    const kds = emitter();
    const fetcher = vi.fn().mockResolvedValue({ ok: true, status: 202 });
    const batcher = createMetricsBatcher({ kdsSyncService: kds, endpoint: '/metrics', fetcher });

    kds.emit('state_change', { from: 'CONNECTED', to: 'POLLING', reason: 'WS_DISCONNECTED', intervalMs: 5000 });
    kds.emit('state_change', { from: 'CONNECTED', to: 'POLLING', reason: 'WS_DISCONNECTED', intervalMs: 5000 });
    kds.emit('state_change', { from: 'CONNECTED', to: 'POLLING', reason: 'HIGH_ACTIVITY', intervalMs: 1000 });

    await batcher._flushNow();
    const metrics = JSON.parse(fetcher.mock.calls[0][1].body).metrics;

    expect(metrics).toHaveLength(2);
    expect(metrics[0].type).toBe('kds.sync_fallback_interval_ms');
    expect(metrics[0].labels.reason).toBe('WS_DISCONNECTED');
    expect(metrics[1].labels.reason).toBe('HIGH_ACTIVITY');
  });
});
