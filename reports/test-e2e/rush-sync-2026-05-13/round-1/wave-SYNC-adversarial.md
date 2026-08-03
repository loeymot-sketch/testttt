# Wave SYNC — Round 1 — Adversarial Review

**Verdict: GO-CONDITIONAL** (0 P0, 0 P1, 2 P2, 1 P3 — coverage + spec-matcher artifacts, no regression).

## Verified (primary-source evidence)

- **Frozen-zone diff**: 0 lines across pos-wizard.js / pos-wizard.css / FiscalSequenceService / PricingService / BranchScope vs phase0 anchor `a26a56afe`.
- **NF525 chain**: `SELECT COUNT(*) FROM audit_logs = 26` (unchanged since 2026-05-13 13:23) — GStack "no fiscal events" holds.
- **kds_station data contract**: lives at `items.kds_station` (enum bar/cuisine_chaude/cuisine_froide/none), exposed via `OrderItemResource::kds_station ?? 'none'`. All 5 items (474/475/478/480/485) default to `'none'`. Not in composition_snapshot, despite task wording.
- **composition_snapshot STRUCTURE**: lines + addons + extras + captured_at + schema_version=1 present for all 5 orders.
- **DB totals == kiosk-API totals**: 7 / 6.5 / 8.5 / 10.5 / 2.5 match.
- **BranchScope**: branch_id=1 for all 5 new orders.
- **Fiscal seq**: 317..321 monotonic above baseline 316, gap-free.
- **Network anomalies**: only 1 unallowlisted 4xx across 50 network.json (POST 401 S1-02 `/api/frontend/order/quote`). 401 is NOT user-visible — DOM is on kiosk-idle screen with toast "Session rafraîchie automatiquement". No silent error.
- **Console**: 42/43 errors = Pusher WS port 6001. 1 remaining = same 401.
- **i18n**: zero raw-label patterns in 50 DOM HTMLs.

## Disputed

1. **"Wave A heals VERIFIED in SYNC captures"** — REFUTED. All 11 kiosk PNGs are idle-state. The spec injects orders via API and never walks the wizard. Code review confirms commits `7322940a3` + `0a83f0795` remain in HEAD — coverage gap, not regression.
2. **"Idempotency-Replayed: true header"** — DOWNGRADED. Asserted in db-tracking only; network.json sinks don't capture response headers for 2xx.
3. **"composition_snapshot FULL coverage YES (all orders)"** — SHALLOW. Field-present check passes, but order 1397 (Tacos) has `lines[]=[]`. See WS-R1-04.
4. **OSS_latency 33s** — true poll = 2..506 ms per observations log; the summary table publishes 33s misleadingly.
5. **"kds_latency 83-91ms / possible cached?"** (task pre-flag) — DISMISSED. 8ms variance is normal HTTP noise.

## Open findings

- **WS-R1-01 (P2)** : No wizard PNGs — kiosk visual coverage limited to idle.
- **WS-R1-02 (P3)** : `kds/S1-04` is whited-out; S2/S5/S7/S9-04 are normal. One-off capture timing.
- **WS-R1-03 (P2)** : `kds_dom_found=false` is a spec-matcher artifact (cards ARE visible in S?-02 captures). 30s figure misleading; true DOM render <few s. Fix the spec's selector.
- **WS-R1-04 (P2)** : Order 1397 (Tacos) `composition_snapshot.lines=[]` — API-direct bypass allowed an empty composition. Tighten coverage check to assert lines populated for wizard-required items.

No P0/P1. Cross-surface sync proven end-to-end via DB + API. Recommend GO-CONDITIONAL: ship V1 sync contract; schedule a UI-walked wave + spec-matcher fix.
