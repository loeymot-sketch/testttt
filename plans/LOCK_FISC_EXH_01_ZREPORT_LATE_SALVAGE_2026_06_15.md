# LOCK — ZReportService late-salvage window (FISC-EXH-01)

**ID:** LOCK_FISC-EXH-01_ZREPORT_LATE_SALVAGE_2026-06-14
**Frozen file touched:** `app/Services/Fiscal/ZReportService.php` (CLAUDE.md §7 — NF525-critical)
**Owner gate:** APPROVED 2026-06-14 via AskUserQuestion — owner chose **"Approve the LOCK"** (add the late-salvage block + `fiscal_seq_allocated_at` column) over "cron-tighten only" or "defer".
**Cycle:** GOAL 2.0 HARDENING (`plans/GOAL_2DOT0_HARDENING_CLOUD_CUTOVER_2026-06-14.md` Phase 2 NF-1). Builds on the ultra-review NF525 forward-leak heals (DV-T1 `60a464ef0`, FISC-EXH-CPS-01).
**Status:** EXECUTED 2026-06-15 under owner authorization (OD-3 "Approve the LOCK" via AskUserQuestion + `/goal` "lance le goal"). NF-1-prereq landed green first (`4c40aa0a7`); the frozen edit applied with frozen-diff = ONLY the late-salvage block; SHA-256 baseline re-recorded with this LOCK as authority. Triple-green below.

---

## 1. Why a LOCK (the FISC-EXH-01 defect)

NF525 requires every realized sale to appear in exactly one daily Z. The frozen
`ZReportService::aggregate()` selects a Z's orders by `created_at ∈ (opened_at, closed_at]`.

The retry-cron (`RetryFiscalAllocCommand`, every minute) allocates a `fiscal_sequence_no`
for a realized-PAID order that initially failed allocation (`fiscal_alloc_error_at` set —
e.g. a COD delivery whose `FiscalSequenceService::next()` threw on a cache blip). If that
allocation lands **AFTER** the day's Z closed at 23:59:
- the order's `created_at` is in the **prior** (already-closed) window → not re-aggregated
  (closed Z is immutable + signed);
- the order has no presence in the **current** window either (`created_at` is yesterday);
- → the realized sale is aggregated **NOWHERE** — it escapes every Z. **NF525 exhaustivity
  violation.**

This is the cross-check of my own G-DELIV-FISCAL/DV-T1 heals: those guarantee a seq is
*eventually* allocated, but a *late* allocation still needs a Z to land in.

## 2. Scope (surgical, additive — ONE block)

**Prerequisite (NON-FROZEN, lands FIRST — NF-1-prereq, not part of this LOCK):**
- Add nullable `orders.fiscal_seq_allocated_at` (migration).
- Stamp it at EVERY `FiscalSequenceService::next()` caller (enumerate ALL before landing):
  `FrontendOrderService` kiosk happy-path, `RetryFiscalAllocCommand` generic salvage, the
  DV-T1 / FISC-EXH-CPS-01 write points (`OrderService`), and any other `next()` site the
  enumeration surfaces. Backfill existing `seq != NULL` rows with
  `COALESCE(fiscal_seq_allocated_at, updated_at)`.

**The FROZEN edit (this LOCK), inside `ZReportService::aggregate()` only:**
1. Add a 4th `lateSalvage` sub-query: rows where
   `fiscal_sequence_no IS NOT NULL`
   AND `payment_status != UNPAID`
   AND `status NOT IN (terminal: CANCELED, REJECTED)`
   AND `created_at <= opened_at` (belongs to a PRIOR window)
   AND `fiscal_seq_allocated_at ∈ (opened_at, closed_at]` (allocated DURING this window).
2. Apply each such row `+1` into `totals` / `byMethod` / `taxBreakdownForOrders` /
   `order_count`, exactly as a normal in-window row.

**Three DISJOINT window keys prove no double-count:**
- normal in-window rows key on `created_at ∈ (opened_at, closed_at]`;
- post-Z negative-adjustment block keys on `updated_at` (existing);
- late-salvage keys on `fiscal_seq_allocated_at` with `created_at <= opened_at`.
A row matches at most one branch.

## 3. Safety / blast radius

- **Inert for normal same-day allocations.** A kiosk/POS sale allocated the same day has
  `created_at` AND `fiscal_seq_allocated_at` both in-window → caught by the STANDARD
  `created_at` branch; the late-salvage branch's `created_at <= opened_at` excludes it.
  Only a genuinely cross-midnight late allocation matches → exactly the escaping sale.
- **Forward-only — historical Z immutable.** `verifyChain()` reads the STORED, signed
  totals of already-closed Z rows; this patch changes only FUTURE aggregation. No
  re-aggregation, no re-signing of any past Z. `fiscal:verify-chain --all` must stay
  CHAIN OK before AND after.
- **No chain/signature algorithm touched** — no `sign()` / `computeSignature()` /
  `prev_hash` change. Only the SELECT that feeds `aggregate()` totals.

## 4. Evidence (TDD, required before merge)

- NEW `ZReportLateSalvageTest`: a COD/non-COD delivery created day D, seq allocated day
  D+1 (cron, post-Z), lands in D+1's signed Z (and NOT in D's closed Z).
- NEW **no-double-count** regression: a same-day-allocated sale appears in exactly ONE Z;
  total order_count + TTC unchanged vs pre-patch on a non-late dataset.
- `fiscal:verify-z-membership` (existing detector) = 0 orphans on a representative clone
  after the patch.
- `fiscal:verify-chain --all` = CHAIN OK.
- Full `tests/Feature/Fiscal` green.
- **Frozen-diff = ONLY this one late-salvage block** (`git diff app/Services/Fiscal/ZReportService.php` reviewed line-by-line).

## 5. Owner sign-off

By signing, the owner authorizes the single additive late-salvage block in the frozen
`ZReportService::aggregate()` as scoped in §2, contingent on the §4 evidence being green
and the frozen-diff containing nothing else.

- [x] Owner authorization: AskUserQuestion OD-3 "Approve the LOCK" + /goal "lance le goal" (2026-06-15).
- [x] Triple-green: full PHPUnit 3324/0 · Fiscal 229/0 · ZReportLateSalvageTest 3/3 (RED-proven) · live clone `foodking_2dot0` `fiscal:verify-chain --all` = **CHAIN OK** (forward-only, signed chain intact) · backfill stamped all 2427 seq rows (allocated_at=created_at).
- [x] Frozen-diff = ONLY the late-salvage block (zero deletions/logic changes); SHA-256 baseline re-recorded (f8f22911…) with this LOCK as authority; commit gated by the prior commit's LOCK citation (no --no-verify — §3quater respected).
- Note: `fiscal:verify-z-membership` on the clone still flags a few pre-existing "NO-Z-GAP (created while no Z open)" rows — a SEPARATE class (Z-lifecycle gap, GATE-Z-GAP-1), NOT the late-allocation class this LOCK closes.
