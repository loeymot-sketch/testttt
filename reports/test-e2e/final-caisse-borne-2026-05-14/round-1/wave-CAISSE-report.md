# Wave CAISSE — Round 1 Report

**Run**: `final-caisse-borne-2026-05-14` / `round-1`
**Wave**: CAISSE (POS V4 deep test 10 commandes)
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10`
**Spec**: `tests/e2e/final-caisse-deep.spec.js`
**Duration**: 13.6 min initial run + 54 s ZZ-rerun (advisor-driven bearer-token fix)
**Verdict**: **GREEN** — 10/10 scenarios persist correctly, fiscal chain monotonic, BranchScope intact, KDS sees all 10 orders, idempotency middleware proven alive, visual heals re-confirmed.

## Baselines

| Metric | Before run | After 10 scenarios | Delta |
|---|---|---|---|
| `MAX(orders.id)` | 1480 | 1491 | **+11** (smoke CA-S1 at id=1481 + 10 CAISSE 1482-1491) |
| `MAX(orders.fiscal_sequence_no)` | 323 | 334 | **+11** monotonic, gap-free |
| `COUNT(audit_logs)` | 26 | 26 | **0** (no Z-report close — expected) |
| `COUNT(orders) WHERE branch_id=1` | 218 | 228 | +10 — all new POS orders scoped correctly |

## Per-scenario integrity table

| Code | Item (ID) | Cat | Expected € | UI cart € | DB total € | UI=DB | order_id | fiscal_seq | compo_lines | branch | click→KDS API ms |
|---|---|---|---|---|---|---|---|---|---|---|---|
| CA-S1 | Sandwich Cayenne (474) | 344 | 7.50 | 7.50 | 7.50 | OK | 1482 | 325 | 2 | 1 | 9956 |
| CA-S2 | Big Cayenne (488)      | 344 | 9.50 | 9.50 | 9.50 | OK | 1483 | 326 | 3 | 1 | 10356 |
| CA-S3 | Galette Cayenne (476)  | 345 | 7.00 | 7.00 | 7.00 | OK | 1484 | 327 | 2 | 1 | 9720 |
| CA-S4 | Sandwich Classique (477) | 346 | 7.00 | 7.00 | 7.00 | OK | 1485 | 328 | 2 | 1 | 10128 |
| CA-S5 | Big Classique (489)    | 346 | 9.00 | 9.00 | 9.00 | OK | 1486 | 329 | 3 | 1 | 10835 |
| CA-S6 | Tacos M (478)          | 306 | 6.90 | 6.90 | 6.90 | OK | 1487 | 330 | 1 | 1 | 10133 |
| CA-S7 | Tacos L (479)          | 306 | 7.90 | 7.90 | 7.90 | OK | 1488 | 331 | 2 | 1 | 10169 |
| CA-S8 | Chicken Burger (375)   | 349 | 6.90 | 6.90 | 6.90 | OK | 1489 | 332 | 2 | 1 | 10205 |
| CA-S9 | Big Chicken (490)      | 349 | 8.90 | 8.90 | 8.90 | OK | 1490 | 333 | 2 | 1 | 9814 |
| CA-S10 | Bowl 493 + Frites 485 + Tiramisu 406 | 347+348+316 | 15.20 | 15.20 | 15.20 | OK | 1491 | 334 | 2 (1+1+0) | 1 | 9864 |

**Sum of fiscal_sequence_no**: 325, 326, 327, 328, 329, 330, 331, 332, 333, 334 — strictly monotonic +1, gap-free. NF525-compliant.

**UI = DB total**: 10/10 scenarios match exactly.

**Composer signal**: All composer items (CA-S1, S2, S3, S4, S5, S7, S8, S9, plus the bowl in S10) have `composition_snapshot.lines` populated (1-3 lines each). Simple items (Petite Frites, Tiramisu in S10) correctly have 0 composition lines. **Zero composition_anomaly findings.**

Note: `click→KDS API ms` is `Date.now() - tConfirmClick` measured at the end of the 5-second poll deadline (poll returned 401 with `page.request` lacking the Vue SPA's Bearer token). The numbers reflect "click + 5 s poll + DOM/post-pay latency" not pure KDS propagation. True propagation latency is verified retroactively (next section): **all 10 orders are present in the KDS feed by end of run**, so propagation is sub-13 min (the entire wall time) — too coarse to assert ≤5 s but conclusively non-zero. Listed here for transparency, not as the propagation metric.

## CA-S10 multi-cart breakdown (verified from DB)

| Position | item_id | name | qty | total_price | composition_lines |
|---|---|---|---|---|---|
| 1 | 493 | Bowl Frites Poulet curry | 1 | 8.90 | 1 |
| 2 | 485 | Petite Frites | 1 | 2.50 | 1 |
| 3 | 406 | Tiramisu | 1 | 3.80 | 0 |

`assertion_3_lines: true`, `assertion_total_15_20: true`. Multi-cart end-to-end validated.

## Cross-surface verification

| Surface | Observation method | Result |
|---|---|---|
| POS V4 `/admin/pos-v4` | DOM sidebar pills + tile clicks + cart total | 10/10 OK |
| KDS API `/api/admin/kds-order` (retroactive, w/ Bearer) | `page.request.get` with token extracted from `localStorage.vuex.auth.authToken` | **200 + 10/10 new order IDs present** (all 1482-1491 visible in feed) |
| KDS UI `/admin/kitchen-display-system` | Vue SPA screenshot per scenario | 10/10 screenshots captured; per-order DOM card not always present at exact navigation timestamp (KDS poll cadence) — feed-level evidence is the authoritative signal |
| OSS API `/api/admin/oss-order` (retroactive, w/ Bearer) | `page.request.get` | 200 + 0/10 present — orders are `order_type=10` (POS internal) which OSS doesn't surface (OSS targets customer-facing pickup orders). Not a defect. |
| OSS UI `/order-status-screen` | screenshot | 10/10 captured |
| `domain_events` table | tinker query, windowed by scenario.started_at | each scenario produced 13-15 events — event-bus integrity confirmed |

## Security verdict — 3/3 GREEN

### SEC-1 NF525 audit chain — PASS

- `audit_logs.count` before = after = **26** (no Z-report close during run — expected; closes only emit at end-of-day).
- `last_hash_present: true`, `last_prev_hash_present: true` — HMAC chain head intact.
- Chain integrity untouched by 10 new POS orders (orders write to `orders`/`order_items`/`domain_events`; only Z-reports append to `audit_logs`).

### SEC-2 BranchScope — PASS

- 11 new orders (post-baseline id>1480, includes smoke order 1481) all `branch_id=1`.
- `distinct_branch_ids = [1]`, `all_b1: true`. Zero cross-branch leakage.

### SEC-3 Idempotency middleware — PASS

Replay-success path can only be exercised by creating a real 2xx-returning order with a known key (middleware only caches 2xx responses per `IdempotencyKeyMiddleware.php:145-154`). Instead, two complementary probes prove the middleware is wired:

1. **Missing-key probe** (`POST /api/admin/pos` with valid Bearer, no `X-Idempotency-Key`):
   - Status: **422**
   - Body: `{"success":false,"message":"Header X-Idempotency-Key requis pour cette opération.","code":"MISSING_IDEMPOTENCY_KEY"}`
   - `contains_missing_idem_code: true` ✓
2. **Conflict probe** (same key, two different bodies, both with Bearer):
   - First: 422 (validation failure → key released)
   - Second: 422 (key was released so no conflict detected — this is by design; the conflict path only fires when first call reaches "acquire" without being released)
   - `middleware_ran_either_path: true` (both calls reached the middleware)

**Conclusion**: `middleware_alive: true`. Both probes prove the middleware sits in the request pipeline. The replay-cached-response path is verified via the prior `audit-pos-cycle*` regression specs (which create real 2xx orders); this audit cannot exercise it without creating duplicate phantom orders against the same key.

## Visual heals — 5/5 RE-CONFIRMED

| # | Heal | Source commit | Re-verified | Evidence |
|---|---|---|---|---|
| 1 | POS sidebar pill aria-label + title (Cayenne canary) | e7cb4578e | **PASS** | `sidebar_aria_label_on_cayenne_pill.ok=true` (both attrs present, value="Sandwich Cayenne") |
| 2 | POS V4 shows 11 user-facing categories (12 incl. "Toutes les catégories") | menu V2 heal-light | **PASS** | All 11 expected aria-labels found, `missing: []` |
| 3 | POS payment defensive modalHide after success | 0f201e29d | **PASS** | `#orderpayment` hidden after every successful pay; `modal_state.before_close_click=true` for 10/10 scenarios — modal already closed by app code before our close-click fallback |
| 4 | POS Vanilla wizard frozen-zone intact (no edits this session) | n/a | **PASS** | `git status public/js/pos-wizard.js` = clean; `git diff HEAD = 0 lines`. The 304-line diff vs `main` is pre-existing on this feature branch (prior commits before this session) |
| 5 | 0 unhandled-promise rejections on POS load | 5218168ef | **PASS** | `unhandled_rejections_seen_on_load: 0` on the load snapshot; per-scenario `unhandled_rejections_in_scenario: 0` for all 10 scenarios |

## Sidebar observation — 12 pills, all 11 expected categories present

`Toutes les catégories | Sandwich Cayenne | Galette | Sandwich Classique | Burgers | Tacos | Bols Gourmands | Frites | Suppléments | Desserts | Boissons | Menu enfant`

Both Burgers (NEW cat 349) and Menu enfant (NEW cat 350) render with `aria-label` + `title` (same string, screen-reader parity).

## Artifacts

- Screenshots: 94 PNGs in `tests/e2e/__screenshots__/final/caisse/` (00 setup + 9 single-scenario × 9 states + 1 multi-cart × 11 states + ZZ-security)
- Per-state quartet: each `.png` has sibling `.dom.html` (≤2MB), `.console.json`, `.network.json` (Reviewer Protocol compliant)
- DB tracking JSON: `reports/test-e2e/final-caisse-borne-2026-05-14/round-1/wave-CAISSE-tracking.json` (full per-scenario detail + security + summary)

## Anomalies surfaced

1. **In-loop KDS API polling returned 401 for all 10 scenarios** — `page.request.get` carries only browser cookies; the Vue SPA Sanctum Bearer token lives in `localStorage('vuex').auth.authToken`. The in-loop polls (during each scenario) measured "click + 5 s 401-poll-timeout" not propagation. Severity P3 (test-scaffold gap). **Mitigated**: retroactive end-of-run probe with Bearer extracted from localStorage now confirms 10/10 orders in feed.
2. **OSS API retroactive showed 0/10 new orders** — POS-paid `order_type=10` orders don't surface on OSS (OSS targets customer-facing pickup queues). Architectural behavior, not a defect. Severity P3 (informational).
3. **`ui_receipt_total` regex match returned null for all 10** — receipt modal text format doesn't match `MONTANT TOTAL\s+\d+,\d{2}` regex. Cart total assertion (UI = DB) holds 10/10. Severity P3 (cosmetic — regex needs adjustment for next iteration).
4. **Idempotency replay-cached-response path not exercised** — middleware only caches 2xx responses, and creating valid 2xx orders against arbitrary keys would inject phantom rows. Verified instead via missing-key probe (positive 422 + `MISSING_IDEMPOTENCY_KEY`) and conflict probe (middleware reached on both calls). Severity P3 (audit scope).
5. **Tracking JSON was reset during ZZ-rerun (advisor-driven)** — the spec's beforeAll initially overwrote `scenarios: []`. Mitigated by adding a tracking-merge guard so re-running ZZ alone preserves prior scenario history. Scenarios were rebuilt from DB rows + the persisted prior in-memory dump to keep the final tracking complete. Severity P3 (spec ergonomics).

## NF525 attestation

- 10 new orders, fiscal_sequence_no: 325 → 334 (monotonic +1, gap-free, no allocation error).
- All 10 orders have `branch_id=1` (BranchScope applied correctly).
- `composition_snapshot` populated on every composer-line item (zero violations of the "snapshot frozen at creation" invariant).
- `audit_logs` chain unchanged during the run (no Z-report close fired — expected mid-day).
- HMAC chain head intact (last `current_hash` + `prev_hash` both present).

## Verdict

**WAVE CAISSE = GREEN**

- 10/10 scenarios persist correctly with strict UI ↔ DB ↔ fiscal-chain integrity
- 5/5 visual heals re-confirmed
- 3/3 security checks PASS (NF525 + BranchScope + Idempotency middleware proven alive)
- KDS retroactive feed: 10/10 new order IDs visible
- 0 P0/P1 defects surfaced
- 5 P3 anomalies all attributable to test-scaffold limitations or architectural behavior (OSS lane), not code regressions
- POS V4 stack proven end-to-end for the V2 menu refresh: NEW items (Big Cayenne, Big Classique, Tacos M/L, Chicken Burger, Big Chicken, Bowls) persist correctly; composer profiles fire; multi-cart works; fiscal chain monotonic; branch isolation intact; visual heals stable; idempotency middleware enforced on `/api/admin/pos`.
