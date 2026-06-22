# Wave T Caisse-to-Delivered — Convergence Final

**Date:** 2026-05-20 (R4 captures 21:10–22:30 UTC)
**Run:** `wave-t-caisse-to-delivered-2026-05-20`
**Branch:** `heal/cms-pr1-quickwins-2026-05-18` HEAD `e2faf0343`
**Cap respected:** ~85 min of 90 min budget
**Adversarial reconciliation:** owner advisor flagged "spec-only" framing — DB investigation invalidated my initial reading, real backend bug identified.

---

## Executive verdict — REVISED post adversarial-reconcile

| Wave | Outcome | Status |
|------|---------|--------|
| **A — POS caisse** | **NOT CONVERGED — REAL BACKEND P0 FOUND** (KDS today-window filter excludes fresh orders 84+85) | OWNER GATE REQUIRED |
| **B — KDS** | **NOT CONVERGED — same P0 as Wave A** (V2 grid renders what backend returns; backend returns only 1 stale order id=71 inside today-window) | OWNER GATE REQUIRED |
| **C — OSS** | **CONVERGED** — R2+R3+R4 = 3 consecutive clean rounds | GREEN |
| **D — LIVREUR** | **CONVERGED** — R2+R3+R4 = 3 consecutive clean rounds (R4 even cleaner: ofd=200 vs R3 422) | GREEN |

**Overall V1 SHIP readiness:** **RED — owner gate required for Wave T-A/B before V1 ship.**

The previous draft framed Wave A/B as "spec-only" issues. Adversarial DB reconciliation proved otherwise: there is a real backend filter regression that hides freshly-paid orders from the kitchen until the next day. This affects **production restaurants** — not just specs.

---

## The smoking gun — KDS today-window filter excludes fresh paid orders

### Evidence chain

1. **Wave C R4 capture `api_rows_summary`** showed `/api/admin/pos-order` returned exactly 1 row (`id=71, status=7, queue_number=A0003`) at the time of testing — even though DB had 13 status=7 orders for branch_id=1.

2. **DB inspection** (`php artisan tinker`):
   ```
   id=71 odt=2026-05-20 21:20:05 status=7 (stale, from R2 leak)
   id=84 odt=2026-05-20 23:11:27 status=7 (Wave T R4 fresh, TAKEAWAY 17 €)
   id=85 odt=2026-05-20 23:11:57 status=7 (Wave T R4 fresh, DELIVERY 24 €)
   ```

3. **Carbon today-window in `KitchenDisplaySystemOrderService::list`** (line 104-112):
   ```
   $parisTodayStartUtc = Carbon::today('Europe/Paris')->setTimezone('UTC');
   $parisTodayEndUtc   = Carbon::today('Europe/Paris')->endOfDay()->setTimezone('UTC');
   // ⇒ start=2026-05-19 22:00:00  end=2026-05-20 21:59:59
   ```

4. **MySQL `@@session.time_zone = SYSTEM (CEST)`** — TIMESTAMP comparisons treat bound parameters as Paris-local literals, not UTC. The query
   ```sql
   WHERE order_datetime BETWEEN '2026-05-19 22:00:00' AND '2026-05-20 21:59:59'
   ```
   on rows stored at Paris-local `'2026-05-20 23:11:27'` returns FALSE (23:11 > 21:59:59). **The last ~2 hours of every Paris day are silently dropped.**

5. **Empirical SQL probe** (raw `DB::select` with same bind types as ORM):
   - UTC bounds → 1 row matched (id=71)
   - Paris-local bounds `[2026-05-20 00:00:00, 2026-05-20 23:59:59]` → 3 rows matched (id=71, 84, 85)

### Severity classification

**P0 — production-impacting regression.**
- Kitchen staff would never see orders paid between 22:00 Paris and midnight on the day they're paid. They'd appear "the next day" (or never, if status moves past status=7 in the gap).
- Introduced by commit `148dbebce` (Wave 2b P0 heal "TZ-aware boundaries in `KdsSyncService`") and mirrored by Wave 3b heal "KDS-ADV3B-01" to `KitchenDisplaySystemOrderService`.
- The original heal correctly identified that **direct `Carbon::today()` was wrong** (Paris-local literal not compared against UTC-stored TIMESTAMPs). But it over-corrected: in local dev with `@@session.time_zone=SYSTEM=CEST`, MySQL does NO TZ conversion on bound parameters — so the UTC bounds end up shifting the window backward by 2 hours instead of normalizing it.
- Production behavior depends on MySQL server `time_zone` setting. If production uses `time_zone='+00:00'` (UTC explicit), TIMESTAMPs would be compared correctly against UTC bounds and the heal would work. If production uses `time_zone='SYSTEM'` on a CEST-locale OS, production is also broken.

### Why Wave D + C passed despite Wave A + B failing

- Wave D operates on **order id=85** (specific row), with `whereIn('id', [85])` lookups — bypasses today-window.
- Wave C OSS uses **different filter set** (status=8/9 PRÊTS lane via separate endpoint) and probes specific tokens.
- Both bypass the today-window filter that traps Wave A (tracker view) and Wave B (KDS V2 grid).

---

## Critical adversarial visual evidence — R4

### Wave A — Order #1 (id=84, TAKEAWAY, 17 €, CASH)
- `wave-A-capture.json` `order_1_id: 84`, DB confirmed `status=7` post-POST.
- State-13 PNG: tracker `EN PRÉPARATION` shows single card **N°A0003**.
- **Crucial reconcile:** items in id=71 and id=84 are IDENTICAL (same fixture). queue_number is the canonical discriminator: id=71 → A0003 (stale), id=84 → A0016 (fresh). The visible card is **id=71** — fresh order #84 is NOT visible because the today-window filter excludes it.

### Wave A — Order #2 (id=85, DELIVERY, 24 €, TPE)
- `wave-A-capture.json` `order_2_id: 85`, DB confirmed `status=7` post-POST (queue_number=A0017).
- Tracker never showed it for the same backend-filter reason.

### Wave B — V2 KDS grid
- Shows only `N°A0003` (id=71 stale). Same root cause: backend returns 1 row, V2 grid renders 1 card. Grid is correct given input.

### Wave C — OSS production-quality verified (GREEN³)
- State-01: clean `N°A0003 EN PRÉPARATION` token 56 px, primary header `rgb(176, 0, 77)` 40 px, `Prêt` header green `rgb(26, 183, 89)` 40 px — S-3 visual mandates preserved.
- `allowlist_enforcement.api_has_order_2: false` confirms DELIVERY exclusion fail-closed (Wave Q-3) holding.
- `pickup_transition.response_status: 200`. Order #84 transitioned 7→13 within 4 ms.

### Wave D — LIVREUR full delivery cycle GREEN³ + improved
- 7/7 PNGs. `assign=200, ofd=200, delivered=200` — OFD now PASSING.
- Final DB: `id=85 status=13 driver=13` — full caisse→livraison→livré CLOSED.
- NF525 chain +1 event (43 total), `verify-chain CHAIN OK`.

---

## R3 → R4 set-equality matrix

| Wave | R3 P0+P1 set | R4 P0+P1 set | Set-equal? |
|------|--------------|--------------|------------|
| A | {WT-A-R3-001 state-17 reinject FAIL, order2 not posted, S-1 hook fail} | {real-backend: today-window filter excludes 84+85; previously thought spec-selector but adversarial DB check proved real bug} | **NOT equal** — root cause SHIFTED. R3 was POST-never-fires (resolved by `e2faf0343`). R4 surfaces deeper KDS filter bug. |
| B | {1 card visible due to A regression, snap crash} | {V2 grid renders only id=71 stale because backend filter excludes 84+85} | **NOT equal** — same root cause as Wave A |
| C | {S-3 mandates ✓, allowlist ✓, pickup 200 ✓, pulse=0 pre-existing} | identical | **EQUAL** = 3 consecutive clean rounds |
| D | {assign=200, delivered=200, ofd=422 terminal} | {assign=200, ofd=200, delivered=200 fresh cycle} | **NOT equal but strictly BETTER** |

---

## Rounds-by-rounds summary

| Round | A | B | C | D |
|-------|---|---|---|---|
| **R1** | RED (7 P0+P1) | RED (5 P1) | AMBER | RED (8 P0+P1) |
| **R2** | RED (same + 2 new) | RED (3 P0 cluster) | **CLEAN** | GREEN-carryover |
| **R3** | RED (1 P0 DOM anchor) | RED (1 P1 snap crash) | **GREEN²** | **GREEN²** (ofd=422 terminal) |
| **R4** | **RED — REAL backend bug** (today-window filter) | **RED — same** | **GREEN³** | **GREEN³** (ofd=200 fresh) |

---

## Commit ledger — Wave T

| Round | Heal commits |
|-------|--------------|
| R1 | `c83fc48f7` + `205fc6668` + `9f8676f42` + `131d79055` + `d89b8a455` + `b12d35f1a` |
| R3 | `e028cfa47` + `70b404cc6` + `75f2cd2f3` + `b97e43df7` + `ed2db25e3` + `b68795ab1` |
| R4 | `e2faf0343` |

---

## NF525 chain integrity

```
Pre-Wave-T (R1 start):  count=6   last_hash=a01740f6b903f5ff691c5163cc86326d2d16451d777e31dd1944581d336c1f9a
R4 Wave-A end:          count=42  last_hash=c3725f84bf011c317cbdb8bf48ff4f7a89bce0dbeaeea9a1157a99cf2b47375f
R4 Wave-D end:          count=43  last_hash=3b1388b5ecf002dba43f496eaf5476965d234d3125075c07ffc9f4bd813343f8
```

- Net +37 events. **`fiscal:verify-chain` output post-R4: `CHAIN OK (audit_logs + z_reports) (branch=1)`.**
- APPENDED-ONLY invariant preserved.

---

## Frozen-zone diff

`git diff --stat HEAD <§7 files>` = **0 lines** across all 13 frozen files. **VERIFIED CLEAN.**

---

## V1 SHIP READINESS — REVISED VERDICT

### RED — owner gate required

V1 cannot ship until the KDS today-window filter regression is addressed because:

1. **Production restaurants would lose visibility of orders paid between 22:00 Paris and midnight** — every single day.
2. The bug surfaces only when production MySQL has `@@session.time_zone='SYSTEM'` on a non-UTC OS (CEST/CET locale). Production deployment to AWS RDS Europe-West-3 typically has `time_zone='UTC'` (different behavior), so the bug may be local-dev-specific. Owner must confirm production MySQL TZ before deciding.
3. **No frozen-zone touch needed** to fix — `KitchenDisplaySystemOrderService::list` is not in §7. The fix can be:
   - **Option A** (defensive): explicitly cast bounds to Paris-local DATETIME (drop `setTimezone('UTC')` for the BETWEEN bind only).
   - **Option B** (config-aware): detect `@@session.time_zone` at boot, branch the conversion.
   - **Option C** (ops): pin MySQL `time_zone='+00:00'` in `config/database.php` so the original Wave 2b heal behaves correctly everywhere.

### Why not heal in this session

- Cap reached (~85 min of 90 min budget).
- Decision tree depends on production MySQL TZ config — **owner gate required**.
- Sister service `KdsSyncService` (148dbebce) likely has same regression — needs cross-system pass.

---

## R5 plan — dispatched as P0 owner-gate task

1. **Owner Q1:** What is production MySQL `@@session.time_zone`?
2. **If `+00:00` (UTC explicit):** original heal works; this is local-dev-only artifact; mark Wave A/B GREEN with caveat.
3. **If `SYSTEM` (likely Le Cayenne local DB):** patch `KitchenDisplaySystemOrderService::list` + `KdsSyncService` to handle local-TZ binding correctly. Mirror to any other today-window query.
4. **R5 dispatch:** new sub-cycle (Wave T-A/B re-capture only) AFTER backend fix lands.

---

## Critical lessons learned (revised)

1. **"Visual evidence" requires queue_number reconciliation, not just item-text correlation.** Fixture-driven specs use identical items every run — `queue_number` is the only canonical discriminator between stale and fresh orders.
2. **DB-level `api_rows_count: 1`** combined with **`whereIn('id', [...])` DB query showing 11 status=7 rows** is the smoking gun for backend-filter bugs. Always cross-check what API returns vs DB has.
3. **TZ-aware boundary heals are risky to mirror across sister services.** Wave 2b → 3b mirror added `setTimezone('UTC')` everywhere; works only with explicit UTC MySQL session TZ.
4. **Wave C + D self-isolation is a feature.** Single-row `whereIn` lookups bypass today-window filters → these waves don't show the regression. **But they do not exonerate the system** — they only test their own paths.
5. **Advisor adversarial-reconcile saved this session.** Without the discriminator check, I would have shipped the AMBER "spec-only" verdict and missed a real production-blocking regression.

---

## Files referenced

- `app/Services/KitchenDisplaySystemOrderService.php:104-112` (today-window filter)
- `app/Services/KdsSyncService.php` (sister service, same heal commit 148dbebce, likely same bug)
- `reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/round-4/wave-A-capture.json`
- `reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/round-4/wave-B-capture.json`
- `reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/round-4/wave-C-capture.json` (key: `api_rows_count: 1`)
- `reports/test-e2e/wave-t-caisse-to-delivered-2026-05-20/round-4/wave-D-capture.json`
- `tests/e2e/__screenshots__/wave-t-caisse-to-delivered-A-pos/13-tracker-order1-en-preparation.png`
- `tests/e2e/__screenshots__/wave-t-caisse-to-delivered-A-pos/17-tracker-order2-en-preparation.png`
- `tests/e2e/__screenshots__/wave-t-caisse-to-delivered-B-kds/02-kds-both-orders-visible.png`
- `tests/e2e/__screenshots__/wave-t-caisse-to-delivered-C-oss/01-oss-landing.png`
- `tests/e2e/__screenshots__/wave-t-caisse-to-delivered-D-livreur/05-tracker-order2-delivered-final.png`
- `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:49` (`visibleOrders.slice(0, 8)` — correct behavior given input)
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1308-1310` (orders binding)

---

## Final verdict

**Wave T 4-round cycle: 2/4 CONVERGED (C+D), 2/4 BLOCKED ON OWNER GATE (A+B P0 backend bug).**

The R4 cycle's most valuable deliverable is **the discovery of the today-window filter regression** — a bug invisible to all prior Waves O/P/Q/R/S audits because their fixtures never operated at the day-end boundary. Wave T's specific time-of-run (Paris 21:10–22:30, straddling the UTC boundary 21:59:59) triggered the failure pattern deterministically.

**Recommend owner:**
1. Confirm production MySQL TZ.
2. Authorize R5 backend heal (estimated 30 min implementation + 30 min spec re-run).
3. Defer V1 SHIP until R5 GREEN².
