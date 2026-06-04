/**
 * Idempotency key generator — Supervisor Wave C / Z4 LATENCY infra.
 *
 * The IdempotencyKeyMiddleware (frozen-zone §7) hashes the (branch_id,
 * user_id, key, request_path, payload) tuple and stores it for 2xx replay.
 *   - Same key + same payload  → replay cache hit (Idempotency-Replayed: true)
 *   - Same key + different payload → HTTP 409 Conflict
 *   - Missing key on idempotency-protected route → HTTP 422
 *
 * This helper produces RFC-4122 v4 UUIDs (collision-free across parallel
 * stress workers) and exposes a `prefix-uuid` shape so the audit chain
 * easily attributes a key back to the test that generated it.
 *
 * No external dependency — uses Node 14+ `crypto.randomUUID` when available,
 * falls back to a `randomBytes` v4 implementation for older runtimes.
 */

const crypto = require('crypto');

/**
 * Generate a fresh RFC-4122 v4 UUID, optionally prefixed for traceability.
 *
 * @param {string} [prefix='test'] human-readable tag (e.g. 'SUPERVISOR-WAVE-C')
 * @returns {string} `<prefix>-<uuid>` (lowercased, hex+hyphens UUID body)
 *
 * @example
 *   const k = generateIdempotencyKey('Z4-LATENCY');
 *   // 'Z4-LATENCY-21f0b9e7-8d22-4b66-9a02-1f1d2c0e7f1a'
 */
function generateIdempotencyKey(prefix = 'test') {
  const uuid = typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : uuidV4Fallback();
  const safePrefix = String(prefix || 'test').replace(/[^A-Za-z0-9_-]/g, '-');
  return `${safePrefix}-${uuid}`;
}

/**
 * Same as `generateIdempotencyKey` but returns the bare UUID without the
 * prefix — useful when calling endpoints that strictly validate the key
 * format against a UUID regex.
 *
 * @returns {string} a pure RFC-4122 v4 UUID
 */
function generatePureUuid() {
  return typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : uuidV4Fallback();
}

/**
 * Fallback v4 UUID for pre-Node-14 runtimes (defensive — Playwright already
 * requires Node 18+ but the helper stays portable).
 *
 * @returns {string}
 */
function uuidV4Fallback() {
  const b = crypto.randomBytes(16);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = b.toString('hex');
  return (
    `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20, 32)}`
  );
}

/**
 * Build the standard idempotency header object — drop into any axios /
 * Playwright APIRequest call to satisfy IdempotencyKeyMiddleware.
 *
 * @param {string} key value previously returned by `generateIdempotencyKey`
 * @returns {{ 'X-Idempotency-Key': string }}
 */
function idempotencyHeader(key) {
  if (typeof key !== 'string' || key.length === 0) {
    throw new Error('idempotencyHeader: key must be a non-empty string');
  }
  return { 'X-Idempotency-Key': key };
}

module.exports = {
  generateIdempotencyKey,
  generatePureUuid,
  idempotencyHeader,
};
