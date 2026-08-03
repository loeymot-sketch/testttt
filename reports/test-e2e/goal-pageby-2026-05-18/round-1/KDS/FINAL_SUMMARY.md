# KDS Cuisine — GOAL Page-by-Page Audit, Round 1 FINAL SUMMARY

**Agent**: E2E Agent KDS Specialist
**Date**: 2026-05-18
**Branch**: v1-0-1-hardening-2026-05-17
**Surface**: `/admin/kitchen-display-system` (V2 default-on per `config/kds.php`)
**Verdict**: **AMBER** — 0 P0, 2 P1, 2 P2, 1 P3 (+1 investigated-not-defect: duplicate queue_number A0002 = cross-day seed artifact, allocator-confirmed safe in prod)

## What ran
Real Playwright (chromium, port 8000) E2E spec
`tests/e2e/test-e2e-kds-goal-pageby-2026-05-18.spec.js` with 6 tests covering 11
visual states (P1 board / P2 bump-cta zoom / P3 items expanded / P4 banner
stack / P5 cta-focused / P6 recall surface / P7 allergen pill / P8
single-station / P9 sync baseline / P10 status mix). All 6 PASS in 55–58 s.
40 artifacts captured under
`tests/e2e/__screenshots__/goal-pageby-kds-2026-05-18/` (PNG + DOM + console +
network quartet per state). axe-core run with WCAG 2A + AA tags persisted to
`axe-results.json`. Bump CTA bounding box measured = 268×52 px (above WCAG 44,
below owner-pref 60).

## Key findings (5 total — see `wave-KDS-findings.json`)
- **KDS-R1-01 P1** — Queue + elapsed visual collision on every rendered card
  (elapsed digits "15781:" overlap "A0001" + clip the "ATT…" label).
  Affects 100% of cards. Fix: `KdsOrderCard.vue` flexbox in `.kds-card__main`.
- **KDS-R1-02 P1** — `.kds-card__elapsed-label` "Attente" at 1.94:1 contrast
  (#b5bac3 on #fff). axe-confirmed serious. Functionally unreadable.
- **KDS-R1-03 P2** — Shortcut badges [A]/[B] at 3.63/4.43:1 (below AA).
- **KDS-R1-04 P3** — Multi-item body-fade clip (observed in intermediate Run-2; final capture overwritten — needs Round-2 re-seed to elevate).
- **KDS-R1-05 P2** — One Safari `scrollable-region-focusable` a11y violation.

## Historic P0 status (8-item baseline from project_kds_audit_2026-05-11)
- 4 **VISUALLY CLOSED** Round 1: accordion (items always expanded in V2), grid
  4×2 emptiness (8/8 fill confirmed with seeded data), 5-banner stack (1
  sticky banner only), 18 raw FR labels (zero leak per spec regex).
- 1 **CONFIRMED NOT A BUG** (Round 1 static): `allergenModal` vs
  `allergensModal` — intentionally distinct return-focus reference.
- 1 **HIT-AREA OK** at 52 px (above WCAG 44, P2 informational vs owner-pref 60).
- 1 **PARTIALLY RESOLVED → 1 new P1**: contrast palette upgraded for body
  text BUT regression on Attente label (1.94:1) — see KDS-R1-02.
- 1 **UNVERIFIED-VISUAL**: age-bucket colour (all 8 cards rendered as
  --critical because promoted to 4h+ elapsed; needs staggered-age reseed).

## Cross-system flags
- POS→KDS sync covered by adjacent
  `test-e2e-pos-kds-sync-2026-05-10-wave-E.spec.js`; documentary capture only
  this round (P9).
- Multi-station = single-station per GOAL §5.3.3 DRIFT-CORRECTION (P8).
- DB state mutated this round (6 well-formed orders reverted; 5 R4TEST
  + 856/1494 retain shift). Next agent: `php artisan db:seed --class=OrderTableSeeder`
  if a clean baseline is required.
