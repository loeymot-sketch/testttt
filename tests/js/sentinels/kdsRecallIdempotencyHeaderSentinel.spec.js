import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [GOAL-2026-05-29 F6 DEAD-BUTTON-FIX] The KDS "Annuler bump" (recall) button
 * was a dead button: routes/api.php:1160 puts the `idempotency` middleware on
 * POST /admin/kds-order/recall/{order}, which REQUIRES an X-Idempotency-Key
 * header — but KdsHistoryDrawer.recall() POST'd WITHOUT it, so it ALWAYS 422'd,
 * and the catch then set recalledMap[id] (faking a RAPPELÉ badge) while the order
 * was never recalled. Confirmed by the from-roots adversarial campaign + verified
 * directly against the route middleware.
 * Fix: send a stable per-minute X-Idempotency-Key (mirrors POS counter-collect /
 * livreur cash), and stop faking the badge on a genuine 422 (window expired) —
 * only 409 (already recalled by a peer) marks recalled. This sentinel locks both.
 */
const src = readFileSync(
  resolve(process.cwd(), 'resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue'),
  'utf-8',
);

describe('KDS recall ("Annuler bump") — idempotency header + honest 422 handling', () => {
  it('sends an X-Idempotency-Key on the recall POST (route requires it)', () => {
    expect(src).toMatch(/axios\.post\(\s*`admin\/kds-order\/recall\/\$\{order\.id\}`[\s\S]*?X-Idempotency-Key/);
  });

  it('does NOT fake a recalled badge on a 422 (only 409 marks recalled)', () => {
    // The old bug FAKED a RAPPELÉ badge on 422: it set recalledMap from a
    // branch whose guard included 422, e.g.
    //   if (status === 409 || status === 422) { recalledMap = ... }
    // The invariant is about the BADGE WRITE, not about the literal substring
    // "409 || 422" appearing anywhere — the legit #12 refetch trigger
    //   if (status === 403 || status === 409 || status === 422) { this.fetch(); }
    // is a resync, NOT a badge fake, and must be allowed.
    //
    // Assert precisely on the BADGE WRITE:
    //  (a) the catch-block badge set IS guarded by a bare `status === 409`, and
    //  (b) NO recalledMap badge set is guarded by a condition containing 422.
    // The legit #12 refetch `if (... || status === 422) { this.fetch(); }`
    // has no recalledMap in its body, so it is correctly allowed.
    expect(src, '409-only badge guard present')
      .toMatch(/if\s*\(\s*status\s*===\s*409\s*\)\s*\{[^}]*recalledMap\s*=/);
    expect(src, 'no badge fake under a 422 guard')
      .not.toMatch(/if\s*\([^)]*422[^)]*\)\s*\{[^}]*recalledMap\s*=/);
  });
});
