/**
 * MetricsBatcher — [NEW-04] non-blocking client telemetry buffer + uploader.
 *
 * Subscribes to wsService and KdsSyncService events, batches whitelisted
 * client metrics, and POSTs them to /api/admin/observability/client-metrics
 * on a fixed cadence (default 60s).
 *
 * Non-functional invariants:
 *   - Buffer is bounded (FIFO) so a stalled uploader can never grow memory
 *     unbounded — drops the OLDEST pending metric once maxBatch is reached.
 *   - Network failures (5xx, fetch reject) preserve the in-flight metrics
 *     by unshifting them back into the buffer; warns ONCE to avoid
 *     console-spam during sustained outages.
 *   - destroy() cleans up the timer AND every wsService/KdsSyncService
 *     listener so SPA navigations or POS-shift swaps don't leak listeners
 *     (mirrors WebSocketService.on() unsubscribe contract from NEW-02).
 *   - Non-whitelisted observability_metric events (e.g. ws.auth_failure
 *     emitted from WebSocketService) are silently dropped — backend's
 *     StoreClientMetricsRequest would 422 the batch otherwise.
 */

const DEFAULT_INTERVAL_MS = 60_000;
const DEFAULT_MAX_BATCH = 200;
// [NEW-04 / Audit T G4] Mirror of SyncMetricsRecorder::ALLOWED_CLIENT_METRICS
// (PHP side). MUST stay in sync — both lists are validated by tests
// (StoreClientMetricsRequest test for the backend rejection, vitest for the
// frontend drop). `ws.auth_failure` is included so handleSubscriptionError()
// emissions land in the batch.
const ALLOWED_CLIENT_METRICS = new Set([
  'ws.connect_latency_ms',
  'ws.disconnect_event',
  'ws.reconnect_storm',
  'ws.auth_failure',
  'kds.sync_fallback_interval_ms',
]);

export function createMetricsBatcher({
  wsService,
  kdsSyncService,
  endpoint,
  fetcher,
  intervalMs = DEFAULT_INTERVAL_MS,
  maxBatch = DEFAULT_MAX_BATCH,
} = {}) {
  if (!endpoint) {
    throw new Error('MetricsBatcher requires an endpoint');
  }

  const resolvedFetcher = fetcher || globalThis.fetch?.bind(globalThis);

  if (!resolvedFetcher) {
    throw new Error('MetricsBatcher requires a fetcher');
  }

  const cappedMaxBatch = Math.min(maxBatch || DEFAULT_MAX_BATCH, DEFAULT_MAX_BATCH);
  const buffer = [];
  const unsubscribers = [];
  const lastKdsReasonByTransition = new Map();
  let timer = null;
  let warned = false;

  function pushMetric(metric) {
    if (!metric || !ALLOWED_CLIENT_METRICS.has(metric.type)) {
      return;
    }

    if (buffer.length >= cappedMaxBatch) {
      buffer.shift();
    }

    buffer.push({
      type: metric.type,
      value: Number.isFinite(Number(metric.value)) ? Number(metric.value) : 0,
      labels: metric.labels || {},
      occurred_at: metric.occurred_at || new Date().toISOString(),
    });
  }

  function subscribe(service, event, handler) {
    if (!service || typeof handler !== 'function') {
      return;
    }

    if (typeof service.on === 'function') {
      const unsubscribe = service.on(event, handler);

      if (typeof unsubscribe === 'function') {
        unsubscribers.push(unsubscribe);
      } else if (typeof service.off === 'function') {
        unsubscribers.push(() => service.off(event, handler));
      }

      return;
    }

    const camelEvent = `on${event.charAt(0).toUpperCase()}${event.slice(1)}`;
    if (typeof service[camelEvent] === 'function') {
      const unsubscribe = service[camelEvent](handler);
      if (typeof unsubscribe === 'function') {
        unsubscribers.push(unsubscribe);
      }
    }
  }

  function readCorrelationId() {
    try {
      if (globalThis.window?.__correlationId) {
        return globalThis.window.__correlationId;
      }

      if (globalThis.localStorage) {
        return globalThis.localStorage.getItem('correlation_id') || globalThis.localStorage.getItem('X-Correlation-ID');
      }
    } catch (_) {
      return null;
    }

    return null;
  }

  async function flushNow() {
    if (buffer.length === 0) {
      return { sent: 0 };
    }

    const metrics = buffer.splice(0, cappedMaxBatch);
    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    };
    const correlationId = readCorrelationId();

    if (correlationId) {
      headers['X-Correlation-ID'] = correlationId;
    }

    try {
      const response = await resolvedFetcher(endpoint, {
        method: 'POST',
        headers,
        body: JSON.stringify({ metrics }),
      });

      if (!response || !response.ok) {
        buffer.unshift(...metrics);
        if (!warned) {
          warned = true;
          console.warn('MetricsBatcher failed to flush metrics', { status: response?.status });
        }
        return { sent: 0 };
      }

      return { sent: metrics.length };
    } catch (error) {
      buffer.unshift(...metrics);
      if (!warned) {
        warned = true;
        console.warn('MetricsBatcher failed to flush metrics', error);
      }
      return { sent: 0 };
    }
  }

  function start() {
    if (timer !== null) {
      return;
    }

    timer = setInterval(() => {
      void flushNow();
    }, intervalMs || DEFAULT_INTERVAL_MS);
  }

  function stop() {
    if (timer !== null) {
      clearInterval(timer);
      timer = null;
    }
  }

  function destroy() {
    stop();
    while (unsubscribers.length > 0) {
      const unsubscribe = unsubscribers.pop();
      try {
        unsubscribe();
      } catch (_) {
        // Best-effort cleanup only.
      }
    }
    buffer.splice(0, buffer.length);
    lastKdsReasonByTransition.clear();
  }

  subscribe(wsService, 'reconnect_storm', (payload = {}) => {
    pushMetric({
      type: 'ws.reconnect_storm',
      value: payload.delay_ms ?? 0,
      labels: { attempts_in_window: payload.attempts_in_window },
    });
  });

  subscribe(wsService, 'state_change', ({ previous, current } = {}) => {
    if (previous === 'CONNECTED' && current !== 'CONNECTED') {
      pushMetric({
        type: 'ws.disconnect_event',
        value: 1,
        labels: { previous, current },
      });
    }
  });

  subscribe(wsService, 'observability_metric', (metric = {}) => {
    pushMetric(metric);
  });

  subscribe(kdsSyncService, 'state_change', ({ from, to, reason, intervalMs: currentIntervalMs, cadenceMs, cadence } = {}) => {
    const key = `${from || ''}:${to || ''}`;
    if (lastKdsReasonByTransition.get(key) === reason) {
      return;
    }
    lastKdsReasonByTransition.set(key, reason);

    pushMetric({
      type: 'kds.sync_fallback_interval_ms',
      value: currentIntervalMs ?? cadenceMs ?? cadence ?? 0,
      labels: { from, to, reason },
    });
  });

  return {
    start,
    stop,
    destroy,
    getBufferSize: () => buffer.length,
    _flushNow: flushNow,
  };
}

export default createMetricsBatcher;
