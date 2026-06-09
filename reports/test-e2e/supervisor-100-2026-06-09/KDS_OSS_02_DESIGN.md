# KDS-OSS-02 — design + deliberate deferral (P2, degraded-mode)
2026-06-09.

## Defect (P2, disclosed)
The RAPPELÉ recall badge is **WebSocket-only**: `KitchenDisplaySystemComponent.onKdsOrderRecalled` is set from the `KdsOrderRecalled` Echo event (60s TTL, not state-backed). A 2nd KDS screen on the same branch in **polling fallback (WS down)** never receives the recall → it keeps showing the order as ready. The recall IS persisted server-side (an `OrderStatusTransition reason='kitchen_recall'` with `occurred_at`), so the data exists — it just isn't surfaced on the poll path.

## Exact fix design (for a focused cycle)
1. **Backend (the risk locus):** in `KitchenDisplaySystemOrderService::list()` (the hot poll query, line 55-172), expose per-order `recalled_at` = the latest `OrderStatusTransition` with `reason='kitchen_recall'` whose `occurred_at` is within the badge window (~60s). MUST be a **constrained eager-load / subquery join (NOT N+1)** to keep the 5s poll cheap. Surface `recalled_at` on the order-level resource (`KDSOrderDetailsResource`).
2. **Frontend:** on each poll `refreshOrderList`, for every order whose `recalled_at` is within the window AND not already in `kdsRecalledMap`, call `onKdsOrderRecalled({orderId, recalledAt, ...})` — so a polling-only screen renders RAPPELÉ.
3. **Test (PHPUnit, no browser-WS-down needed):** recall a PREPARED order, then assert the `list()` poll payload for that order includes a `recalled_at` within the window; assert it's ABSENT for a non-recalled order and EXPIRED (absent) after the window. This makes the fix verifiable without the flaky kill-soketi browser scenario.

## Why deferred (not done in this marathon session)
- `list()` is a **heavily-healed, sentinel-pinned hot path** (TZ window correctness pinned by `KdsTodayWindowTzSentinelTest`, branch-isolation, 50-order pagination cap + overflow flag). A careless join here risks a **silent regression on the kitchen's primary board** (every 5s refresh) — strictly worse than the disclosed degraded-mode P2 it fixes.
- CLAUDE.md §3 (correctness > speed), §11 (production-grade, no silently-dangerous change) + the test-e2e P2 rule (disclose, don't loop) → this is the responsible terminal state: a precise, ready-to-execute design rather than a rushed hot-path edit.
- KDS-OSS-01 (the clearer sibling — legacy inline recall → server) WAS done + verified this session (`340954ec1`, Vitest 5/5).

## Impact bound (why P2, not higher)
- Only affects a **2nd KDS screen** on the **same branch** while **WS is down** (single-box V1-LOCAL with soketi UP = the normal path; this is the degraded fallback). The primary screen (the one that issued the recall) shows RAPPELÉ correctly via the local emit. Single-box Le Cayenne typically runs one KDS surface.
