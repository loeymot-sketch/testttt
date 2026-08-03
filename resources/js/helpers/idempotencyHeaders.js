/**
 * Idempotency-Key header builder for client-side axios mutations.
 *
 * Backend middleware (`config/idempotency.php` + `IdempotencyKeyMiddleware`)
 * requires `X-Idempotency-Key` on ~23 mutation routes (cash-drawer open/close,
 * refund-with-counter, change-status × 6, kiosk paid order confirm, etc.).
 * In production (Foundation Audit F-3 P0 / AppServiceProvider boot guard)
 * the middleware is forced ON — clients that don't send the header receive
 * 422 "Header X-Idempotency-Key requis".
 *
 * Original inline helper from `resources/js/store/modules/posOrder.js`
 * (PS-2 audit 2026-05-18) extracted here so all 6+ Vue stores and Kiosk
 * components share the same key-generation logic without duplication.
 *
 * Usage:
 *   import { buildIdempotencyHeaders } from '../helpers/idempotencyHeaders';
 *   axios.post(url, payload, { headers: buildIdempotencyHeaders(payload) });
 *
 * Behaviour:
 *   - If payload.idempotency_key is explicitly provided, reuses it (allows
 *     retry-on-network-error with the SAME key for true idempotency).
 *   - Otherwise generates a fresh UUID v4 via crypto.randomUUID() (modern
 *     browsers) or fallback `pos-mut-<ts>-<rand>` string.
 *
 * Backed-by sentinel: Vitest spec in tests/js (TBD V1.0.X).
 */
export function buildIdempotencyHeaders(payload) {
    const explicit = payload && typeof payload === 'object' ? payload.idempotency_key : null;
    const key = explicit
        || crypto.randomUUID?.()
        || (`pos-mut-${Date.now()}-${Math.random().toString(36).slice(2)}`);
    return { 'X-Idempotency-Key': String(key) };
}
