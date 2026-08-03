/**
 * posOfflineReplayUrlSentinel — guards POS offline replay URL drift
 * ---------------------------------------------------------------------------
 * [P0 V1 Cloud-Prep insights 2026-05-18]
 *
 * The POS offline queue (resources/js/composables/usePosOfflineState.js) replays
 * queued cash sales via `tryFlush(postFn)`. The real POS endpoint is
 *     POST /api/admin/pos    (routes/api.php:728 — PosController::store)
 *
 * The historical bug shipped `postFn('admin/pos/order', ...)` — a route that
 * does NOT exist. Every queued cash sale 404-ed silently, then was purged
 * after the 30-min TTL → silent NF525 cash-trail data loss on offline replay.
 *
 * This sentinel guards against URL drift by literal source-string match.
 * Vitest cannot mock route resolution; the existing posOfflineQueueImpl spec
 * inspects only headers, never the URL argument. This file plugs that gap.
 *
 * @FK-ID FK-POS-OFFLINE-Q2 | @source V1 Cloud-Prep P0-#3
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('POS offline replay URL sentinel', () => {
    it('usePosOfflineState.js posts to admin/pos (not admin/pos/order)', () => {
        const src = readFileSync(
            resolve(__dirname, '../../resources/js/composables/usePosOfflineState.js'),
            'utf8',
        );
        // Sanity: file exists + non-trivial
        expect(src.length).toBeGreaterThan(100);
        // The bug pattern (admin/pos/order) must NOT exist
        expect(src).not.toMatch(/admin\/pos\/order/);
        // The correct URL must exist
        expect(src).toMatch(/admin\/pos/);
    });
});
