# WI-5 — Cross-Surface Adversarial RED Meta

**Date**: 2026-05-19
**Branch**: v1-0-1-hardening-2026-05-17
**Mode**: READ-ONLY adversarial meta-audit (full cross-system chain attacks)
**Mandate**: probe what 8 Wave F sync masters + 5 Wave H heal masters + 7 Wave G + 13 zone audits all missed — the JOINS between systems.

---

## 1. Executive Summary

**Verdict**: cross-system defenses are largely intact. 0 P0 found. 2 P1 (security-adjacent) + 3 P2 (operational/UX) + 2 P3 (forensic / hardening). Detail in matrix §2.

The 13 zones each defended internally were never wired together to fail in unexpected ways. The four real findings are:

- **P1 [SEC-RST-01]** — `POST /api/forgot-password/reset-password` does NOT revoke existing Sanctum tokens on successful password reset. An attacker who phished the reset PIN can change the password; the legitimate user's old tokens stay valid for up to 480 min (`config('sanctum.expiration')`). No Idempotency-Key middleware either (replay-on-retry not blocked at protocol layer, only by 5/min throttle).
- **P1 [OBS-OUTBOX-01]** — `PersistOrderPaymentStatusChangedToOutbox::handle` swallows `DispatchDomainEventsJob` exceptions with `Log::warning` only (lines 79-90). Row stays in `domain_events` so `OutboxRetryFailedCommand` eventually picks it up — but if the queue worker is dead AND the inline retry path errors, the only signal is a log line. Operational visibility gap.
- **P2 [BRANCH-ORPHAN-01]** — `BranchService::destroy` is soft-delete only; active orders on the deactivated branch are not advanced/closed and become invisible to non-Admin staff (BranchScope filter applies). Admin (`branch_id=0`) still sees them, but no cron sweeps them. Operational, not security.
- **P2 [CANCEL-EVT-SWALLOW-01]** — `OrderStatusChanged::dispatch` and `OrderCanceled::dispatch` on cancel paths (FrontendOrderService:737-754 + OrderService:1773-1786) wrap dispatch in `try/catch (\Exception) → Log::warning`. If the event dispatcher itself errors (rare but possible mid-deploy), KDS/OSS never sees the cancel and tickets stay rendered. Listener side-effects (stock release) are also lost.

NF525 chain integrity: not violable through any chain attempted. `fiscal_sequence_no` is never reset on cancel, audit_logs triggers active, z_reports triggers active, FiscalSequenceService double-defense (cache lock + lockForUpdate) intact.

---

## 2. Scenario Matrix

### S1 — Refund cross-cascade (mobile QR refund → loyalty refund → OrderPaymentStatusChanged broadcast → OSS → KDS)

**Verdict**: DEFEATED.
**Defense chain**:
- `OrderService.php:2091-2095` dispatches `OrderPaymentStatusChanged` inside the transaction.
- `PersistOrderPaymentStatusChangedToOutbox.php:40-64` writes a `DomainEvent` row via `firstOrCreate` (correlation-id-scoped idempotency key, line 32-38).
- `PersistOrderPaymentStatusChangedToOutbox.php:73` defers `DispatchDomainEventsJob::dispatch` to `DB::afterCommit` — guarantees the row exists in DB before broadcast.
- Network blip at step N: domain_events row persists, `OutboxRetryFailedCommand` cron retries.
**Severity**: n/a (defeated). Minor visibility caveat → see OBS-OUTBOX-01 below.

### S2 — Concurrent multi-station POS bump same order → PREPARED

**Verdict**: DEFEATED.
**Defense chain**:
- `OrderService.php:1548-1556` wraps the state mutation in `DB::transaction` with `Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail()`.
- Idempotent early-return: `if ((int) $locked->status === (int) $newStatus) { return; }` (line 1553) — second writer sees the new status and exits.
- `OrderStateMachine::recordTransition` is therefore called exactly once per real transition.
**Severity**: n/a (defeated).

### S3 — Kiosk paid + immediate cancel (NF525 chain + composition_snapshot + refund cascade)

**Verdict**: DEFEATED.
**Defense chain**:
- `fiscal_sequence_no` allocated at-creation via `FiscalSequenceService::next` (FrontendOrderService.php:1134) and NEVER reset elsewhere — verified by absence of any `fiscal_sequence_no = null` write across `app/Services` + `app/Services/Order`. Cancel marks `status=CANCELED` but leaves the row in the chain → Z aggregation still sees it (correct NF525 — cancels are visible counter-entries).
- `composition_snapshot` is JSON-frozen at creation (FrontendOrderService.php:441) and never re-derived.
- Refund cascade: `PaymentService::cashBack` (line 700) + `LoyaltyService::refundPoints` (line 707) + `OrderStatusChanged::dispatch` (737) + `OrderCanceled::dispatch` (751). All gated by `cancelableThreshold = PREPARING` for kiosk (line 694).
- audit_logs + z_reports triggers (migrations 2026_04_22_000002 + 2026_05_09_160000) prevent any chain row deletion at DB level.
**Severity**: n/a (defeated).

### S4 — POS Loyalty Redeem + immediate cancel — point reversal companion

**Verdict**: DEFEATED.
**Defense chain**:
- `PosRedemptionService::applyToOrder` (line 195-202) updates `order.loyalty_customer_code` so subsequent refund can locate the customer.
- Self-cancel path (`OrderService.php:1753`) calls `LoyaltyService::refundPoints($locked, 'pos')` UNCONDITIONALLY (NOT gated on `$order->transaction`).
- Admin-cancel path (`OrderService.php:1856`) is also unconditional — verified explicitly: line 1849-1854 cashBack IS gated on transaction, but line 1856 refundPoints is OUTSIDE the gate. So cash POS orders (no transaction row) with loyalty redeem still refund points correctly.
- `LoyaltyService::refundPoints` (LoyaltyService.php:21-79) sums `LoyaltyTransaction::where('order_id', ...)->where('type', 'redeem')`, re-credits, writes a reversal `manual_add` row.
- `LoyaltyTransaction` carries NO `BranchScope` — global lookup by order_id (which is globally unique).
**Severity**: n/a (defeated). The customer keeps neither cash (refund cascades) nor loses points (refundPoints fires).

### S5 — Branch deactivation cascade with active orders

**Verdict**: PARTIALLY DEFEATED. Security side covered, operational side leaks.
**Defense chain (security)**:
- `BranchController::destroy` (lines 94-99) captures old status, soft-deletes, dispatches `BranchStatusChanged($branchId, $oldStatus, INACTIVE)`.
- `RevokeTokensOnBranchDeactivated::handle` (lines 25-66): deletes Sanctum tokens for all users with `branch_id = X` and `tokenable_type = User` (kiosk machine tokens preserved by tokenable_type filter).
**Defect (operational)**:
- `BranchService::destroy` (lines 86-98) is pure `$branch->delete()` (soft-delete via `SoftDeletes` trait on `Branch.php:7,12`).
- Active orders (`status NOT IN [DELIVERED, CANCELED, REJECTED, RETURNED]`) on this branch remain in `orders` table.
- `BranchScope` filters them out for non-Admin staff — they vanish from POS/KDS/OSS UI.
- Admin (branch_id=0) sees them but no cron sweeps unsealed active orders on soft-deleted branches.
- Net: customer paid → kiosk gave a receipt → KDS shows nothing (token revoked) → ticket lost.
**Severity**: **P2** [BRANCH-ORPHAN-01]. Heal: pre-destroy guard "if any active order, refuse 422" OR background job sweeping active orders to CANCELED with refund cascade.

### S6 — Admin password reset → re-login cascade (token revocation + idempotency)

**Verdict**: DEFECT.
**Code path**: `ForgotPasswordController.php:163-175`
```
DB::transaction(function () use ($request, $user) {
    DB::table('password_resets')->where('email', ...)->delete();
    $user->update(['password' => Hash::make(...)]);
    $this->token = $user->createToken('auth_token', ['*'], now()->addMinutes((int) config('sanctum.expiration', 480)))->plainTextToken;
});
```
**Defects**:
1. No `$user->tokens()->delete()` before mint. Old tokens stay valid 480 min.
2. No `tokenCan('*')` ability scoping — fresh token is full-access.
3. Route (`routes/api.php:168`) has `throttle:5,1` but NO `idempotency` middleware. Replay-on-retry not blocked at protocol layer.
4. `password_resets` row is deleted (line 164) so reset_token is single-use — partial mitigation only.
**Severity**: **P1** [SEC-RST-01].
**Heal recommendation**: Add `$user->tokens()->delete()` inside the transaction before `createToken`. Mirror `RevokeTokensOnBranchDeactivated`. Consider adding `idempotency` middleware for symmetry with POS routes.

### S7 — Mobile QR scan with revoked customer account (RGPD art.17)

**Verdict**: DEFEATED with marginal P3 forensic note.
**Defense chain**:
- `User` model uses `SoftDeletes` trait (User.php:31). Deleted users excluded from default Eloquent queries.
- `LoyaltyController::scan` (line 632): `User::where('loyalty_code', $code)->first()` returns null for soft-deleted user.
- `isCustomerActive` check (line 634) also filters by `status === ACTIVE`.
- Returns HTTP 200 with `customer_not_found` error_code (line 639) — never 4xx.
- `LoyaltyQrSigner::verifyAndConsume` (LoyaltyQrSigner.php:140-157) consumes nonce on UNIQUE-violation pattern (INSERT-then-catch) regardless of customer existence.
**Minor**: nonce consumed irrevocably even when customer turns out to be revoked. Replay forensic value preserved but customer cannot retry with same token (must regenerate via fresh `/loyalty/sign`).
**Severity**: **P3** [LCS-RGPD-01]. Heal optional: pre-validate customer existence BEFORE consuming nonce. Not worth the TOCTOU complexity for V1.

### S8 — Date boundary edge cases (Paris midnight + DST)

**Verdict**: DEFEATED.
**Defense chain (verified TZ-aware)**:
- `KdsSyncService.php:78-80`: `Carbon::today($appTz)->setTimezone('UTC')` etc — Paris-local day → UTC bounds for TIMESTAMP comparison. Sentinel referenced (commit 148dbebce).
- `DashboardService.php:115-124`: same pattern, Paris start/end day → UTC.
- `ResetStaleDailyQuotaCommand.php:57`: `Carbon::today(config('app.timezone'))->toDateString()` — lexical Y-m-d compare avoids cross-driver TZ traps. Sentinel: `ResetStaleDailyQuotaTzCorrectSentinelTest`.
- `ZReportService.php:623-624`: canonicalises `closed_at` in UTC ISO-8601 for cross-TZ-deployment safety.
- DST spring-forward: Paris-local 02:00→03:00 jump — Carbon handles via PHP DateTimeZone; boundary queries are inclusive on start, exclusive on end (no double-count, no missed-hour).
**Severity**: n/a (defeated).

### S9 — Fiscal sequence allocation race under chaos

**Verdict**: DEFEATED.
**Defense chain (triple lock)**:
- L1: `Cache::lock('fiscal_seq_b{branch}', 5)` with `->block(3)` (FiscalSequenceService.php:65-74). Returns RuntimeException if 3s exceeded — caller's outer transaction rolls back, the order stays PENDING + `fiscal_alloc_error_at` flagged.
- L2: `Order::withoutGlobalScopes()->where('branch_id')->lockForUpdate()->max('fiscal_sequence_no')` (line 88-91) — DB row-level lock even if cache outage / split-brain.
- L3: DB unique key `orders_branch_fiscal_seq_unique` — final gate; concurrent inserts collide here as last resort.
- Orphan retry: `fiscal_alloc_error_at` marker (FrontendOrderService.php:1174) + retry cron picks them up.
**Severity**: n/a (defeated). No silent gaps possible.

### S10 — Cross-branch data leak via shared global

**Verdict**: DEFEATED.
**Defense chain**:
- `BranchScope` (BranchScope.php) is fully stateless — re-reads `Auth::user()->branch_id` on every `apply()`. No class-level `static $context` or memo cache.
- `User` model exempted (line 21-23) to prevent Sanctum recursion — fine because Sanctum operates BEFORE Auth is set.
- Spatie role cache: WH-1 caught the cross-branch leak (role cache memoised per-process); current branch resolution at audit time uses `auth()->user()->hasRole(...)` which reads from per-instance role relation, not global static.
- No app-level static state (`grep -E 'static \$\w+' app/` returned nothing modifying class-level scope).
**Severity**: n/a (defeated). Octane / FrankenPHP migration would re-introduce risk (process not torn down between requests) — defer to V2 hardening pre-Octane.

---

## 3. Creative additions (own scenarios)

### S11 — Outbox listener exception swallow on PersistOrderPaymentStatusChangedToOutbox

**Verdict**: PARTIAL DEFECT.
**Cite**: `PersistOrderPaymentStatusChangedToOutbox.php:79-90` wraps `DispatchDomainEventsJob::dispatch` in `try/catch (\Throwable) → Log::warning`.
**Attack**:
- `DomainEvent` row exists (line 40), survives.
- Inline broadcast errors → swallow.
- Cron `OutboxRetryFailedCommand` retries — defense works.
- BUT if queue worker is down AND cron is also down (deploy window, ops incident), only signal is a log line. No alarming, no ops paging.
**Severity**: **P1** [OBS-OUTBOX-01]. Heal: add `Sentry::captureException` OR a `Log::channel('alarms')` separate channel + metric counter. Same anti-pattern recurs in PersistOrderStatusChangedToOutbox, PersistOrderCreatedToOutbox.

### S12 — Listener exception swallowing across cancel paths

**Verdict**: PARTIAL DEFECT.
**Cites**:
- `OrderService.php:1775-1776`, `1784-1786`: `OrderStatusChanged::dispatch` + `OrderCanceled::dispatch` → catch + warning.
- `FrontendOrderService.php:742-754`: same pattern.
**Attack**: if event dispatcher itself errors (rare — typically deploy window with stale config cache), KDS never sees cancel + stock release listener never fires (orphan stock decrement). The user-facing flow returns 200, the order is CANCELED in DB, but downstream is stuck.
**Severity**: **P2** [CANCEL-EVT-SWALLOW-01]. Heal: replace `Log::warning` with `Log::error` + bump correlation, plus a sentinel test that asserts dispatch is reachable mid-cancel.

### S13 — Concurrent loyalty redemption on already-loyalty-redeemed order (race vs UNIQUE constraint)

**Verdict**: DEFEATED.
**Defense**: `PosRedemptionService.php:166-186` wraps `LoyaltyTransaction::create` in `try/catch (QueryException)` and inspects `errorInfo[0] === '23000'` (UNIQUE constraint on `(user_id, order_id, type)` per migration 2026_03_26_075919). Returns clean 409 `ALREADY_REDEEMED`. Two cashiers redeeming same order in parallel — exactly one wins, the other gets clean error. customer.loyalty_points decrement is also in `DB::transaction` with `lockForUpdate` (line 102-104).
**Severity**: n/a (defeated).

---

## 4. Severity totals

| Severity | Count | IDs |
|---|---|---|
| P0 | 0 | — |
| P1 | 2 | SEC-RST-01, OBS-OUTBOX-01 |
| P2 | 2 | BRANCH-ORPHAN-01, CANCEL-EVT-SWALLOW-01 |
| P3 | 1 | LCS-RGPD-01 |

NF525 chain integrity: **PRESERVED**. Frozen-zone touch needed: **NONE for V1 ship**. Heals all deferrable to V1.0.1+ backlog.

---

## 5. Heal recommendations (NOT applied — read-only)

| ID | Priority | Effort | Heal |
|---|---|---|---|
| SEC-RST-01 | P1 | XS (1 line) | Add `$user->tokens()->delete()` inside `resetPassword` DB::transaction at ForgotPasswordController.php:163, BEFORE `createToken`. |
| OBS-OUTBOX-01 | P1 | S (3-5 lines × 3 listeners) | Replace `Log::warning` swallow with a dedicated alarms channel + metric counter (`Cache::increment('outbox.dispatch_inline_fail')`). Add a sentinel feature test. |
| BRANCH-ORPHAN-01 | P2 | M (1 method + 1 cron) | BranchService::destroy refuses 422 if active orders exist; new `SweepOrphanedActiveOrdersCommand` cron transitions them to CANCELED with refund cascade. |
| CANCEL-EVT-SWALLOW-01 | P2 | XS | `Log::warning` → `Log::error` + sentinel test ensures event dispatch reachable. |
| LCS-RGPD-01 | P3 | S | Pre-check `User` exists+active inside `LoyaltyQrSigner::verifyAndConsume` BEFORE nonce INSERT — accept TOCTOU but improve UX. |

---

## 6. What 8+5+7+13 prior masters missed

- **Wave F sync masters** verified the OUTBOX persistence path but didn't model the alarm path on listener swallow (S11 P1).
- **Wave H heals** closed 5 ultra-review bugs but never re-audited the password-reset code path for token revocation parity with branch-deactivation R10 listener.
- **Wave G unified failure isolation policy** (WG-2) applied to fiscal/payment paths but the cancel-event `Log::warning` pattern (S12) was outside its scope.
- **13-zone audits** internalised each zone but the cross-zone JOIN: Branch destroy → active orders → KDS/OSS → soft-delete (S5) was unscoped because no single zone owns "branch lifecycle + order lifecycle JOIN".

---

## 7. Conclusion

Cross-system attack chains do not surface a V1 ship blocker. The 2 P1 findings are operational/security-hardening, not correctness/fiscal. The 2 P2 are operational gaps. NF525, BranchScope, fiscal seq, audit chain, refund cascade, loyalty redemption + cancel cycle, broadcast outbox durability, TZ handling — all defended with explicit file:line citations above.

**Recommendation**: ship V1; queue SEC-RST-01 + OBS-OUTBOX-01 for V1.0.1 within the existing 4 deferred backlog items + 2 here = 6 V1.0.X items. None block ship.

**End of WI-5 STATUS.md — 2026-05-19.**
