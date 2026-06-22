/**
 * [GENIE Wave 6 — B3 2026-06-16] KDS dual-poll de-dup sentinel.
 *
 * When WS is down the coarse auto-refresh poll already runs at 5s (the freshness SoT), so the
 * delta-`sync`-triggered full refetch was a redundant SECOND full board fetch. The heal gates that
 * delta refetch behind `wsConnected !== false` — redundant when fully disconnected (Loop1 5s covers it),
 * kept in every other state. This sentinel pins BOTH halves of the contract:
 *   1. the 5s freshness budget is preserved (_pollingInterval returns 5000 when WS down, 60000 when up),
 *   2. the delta refetch is de-duplicated (gated by wsConnected !== false).
 * (Source-guard for the gate because the sync listener is an inline closure inside mounted(); the 5s
 * budget half is asserted behaviorally on the real method via synthetic-this.)
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SRC = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue'),
    'utf8',
);

// Extract _pollingInterval()'s body and run it under synthetic-this to prove the 5s budget is intact.
function pollingInterval(wsConnected) {
    const m = SRC.match(/_pollingInterval\(\)\s*\{([\s\S]*?)\n\s{4}\}/);
    if (!m) throw new Error('_pollingInterval not found');
    // eslint-disable-next-line no-new-func
    return new Function(`return (function(){ ${m[1]} }).call({ wsConnected: ${wsConnected} })`)();
}

describe('KDS poll cadence + dual-poll de-dup (Wave 6 / B3)', () => {
    it('preserves the ≤5s kitchen-freshness budget: 5000ms when WS down, 60000ms when up', () => {
        expect(pollingInterval(false)).toBe(5000);
        expect(pollingInterval(true)).toBe(60000);
    });

    it('de-duplicates the delta-triggered full refetch (gated by wsConnected !== false)', () => {
        // the _debouncedRefresh inside the sync listener must be guarded so it does NOT fire the
        // redundant full refetch when wsConnected === false (the 5s poll already covers it)
        expect(SRC).toMatch(
            /hasFreshOrders\s*\|\|\s*hasDeletes\)\s*&&\s*this\.wsConnected\s*!==\s*false\)\s*\{\s*[\s\S]*?_debouncedRefresh/,
        );
    });
});
