# A09 — Cash Drawer Session + Movements

**HEAD** : `a220b9bd8`
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Scope** : `CashDrawerService`, `CashDrawerSessionController`, `CashDrawerController` (hardware), `CashDrawerSession` + `CashMovement` models, migrations, recordCashOrderMovement / recordCashBackMovement hooks in `PaymentService`, `ZReportCashEnrichmentService`.

---

## 1. Verification of past-audit findings

### P0-09 (concurrent open session) — **CLOSED / hardened**
`CashDrawerService::openSession` at `app/Services/Cash/CashDrawerService.php:35-93` now has triple-layer defense:
1. `Cache::lock("cash_drawer_open_b{branch}_u{user}", 5)->block(3, ...)` (line 61) — app-tier serialisation.
2. `DB::transaction()` + `lockForUpdate()` on existing-session probe (line 67) — DB-tier row lock.
3. UNIQUE partial index `(branch_id, opened_by_user_id) WHERE status='open'` via migration `2026_05_10_020000_add_unique_partial_cash_drawer_open.php:43-71` — storage-tier guarantee (SQLite native partial, PgSQL native partial, MySQL via STORED generated column `open_user_lock` + UNIQUE(branch_id, vcol)). LockTimeoutException is caught and surfaced as 409 (line 83-92), with WARNING log. The `CashDrawerConcurrentSessionTest` (4 tests) covers race, direct-INSERT bypass, reopen-after-close, distinct cashiers. **Verdict: P0-09 properly remediated.**

### P1-06 (cash collected without open session = invisible Z) — **OPEN, accepted-by-design but RISKY**
`PaymentService::recordCashOrderMovement:243-281` is best-effort: when no OPEN session, logs INFO and returns. Order is still persisted PAID with `payment_mode=cash`. `ZReportCashEnrichmentService::aggregateForWindow` (lines 50-83) only aggregates over `cash_drawer_sessions` (movements joined by session_id) — so unattached cash sales are **invisible to cash variance**. Total order revenue stays correct (totals are aggregated from orders, not movements), but `cash_movements_count` and `cash_variance` exclude them. NF525 risk : caisse réelle peut diverger de Z sans trace explicite. **Recommendation : either (a) require open session before cash POS payment (block 422), or (b) emit `cash_movements` with `cash_drawer_session_id=NULL` for orphan sales and surface them in a "unbound cash" line on the Z.**

### P0-04 (cash_movements cascadeOnDelete) — **CONFIRMED, P1 cross-A07**
Migration `2026_05_08_140100_create_cash_movements_table.php:47-50` declares `->cascadeOnDelete()` on `cash_drawer_session_id`. Same anti-pattern as A07's audit_logs concern. NF525 audit-trail invariant: a deleted session should NEVER silently delete its cash movements. Mitigation: TRUNCATE/DELETE on `cash_drawer_sessions` is not blocked by any DB trigger here (the trigger from F-001 only guards `audit_logs` + `z_reports`).

---

## 2. Findings table

| ID | Sev | File:Line | Defect | Evidence | Suggested Fix |
|----|-----|-----------|--------|----------|----------------|
| **A09-P0-1** | P0 | `database/migrations/2026_05_08_140100_create_cash_movements_table.php:47-50` | `cash_drawer_session_id` uses `cascadeOnDelete()`. Deleting (or accidentally truncating) a session silently wipes its movements — fiscal audit trail lost. No BEFORE DELETE trigger on `cash_drawer_sessions` either. | NF525 invariant : tout mouvement cash doit être conservé 6 ans. CASCADE viole l'invariant. | Change to `restrictOnDelete()` + add a session soft-delete column if needed. Optionally add a MySQL trigger BEFORE DELETE ON cash_drawer_sessions analogous to `audit_logs` (cf. F-001 trigger pattern). |
| **A09-P0-2** | P0 | `app/Services/PaymentService.php:243-281` + `:287-324` | Cash POS payment + cashback hooks are **best-effort** : no OPEN session ⇒ silently log INFO and continue ; recordMovement failure ⇒ catch+log warning. The order is persisted PAID without any `cash_movements` row, so Z variance silently diverges from physical cash counted. | l.258 `if (! $session) { Log::info(...); return; }` ; l.275-280 catch swallows all errors. `ZReportCashEnrichmentService:46-83` only aggregates over sessions ; orphan cash sales are invisible to variance. | (Option A) require open session ⇒ throw 422 with i18n message at `PosOrderController::store` cash branch. (Option B) emit `cash_movements` row with `cash_drawer_session_id=NULL` flagged `orphan=true` ; show on Z under "unbound cash". |
| **A09-P0-3** | P0 | `app/Services/Cash/CashDrawerService.php:101-133` `closeSession` | `closing_amount` is **caller-declared**, not validated against `Σ(signed movements) + opening`. A cashier can declare `closing_amount=500` and walk away with the surplus — variance is recorded but **nothing blocks the close** even on huge variance (e.g. 500 €). No `variance_threshold` check. | Lines 126-129 set `closing_amount` to the caller-provided value with no comparison gate. `reconcileSession:155-165` simply persists variance. | Add a `force=true` query param + manager-permission gate when `|variance| > config('pos.cash.max_variance_eur', 5)`. Log a high-priority audit event on big variance + require optional `variance_reason` field (the column exists at `cash_drawer_sessions.variance_reason` but is never written). |
| **A09-P1-1** | P1 | `app/Models/CashDrawerSession.php:46-57` | `opening_amount` / `closing_amount` cast as `decimal:2` — Eloquent decimal cast returns a **string**, so `(float) $session->opening_amount` widening in `reconcileSession:173` works but is float-fuzzy on certain values (e.g. 999999.99). Use `BCMath` or integer cents for fiscal-grade arithmetic. | `app/Services/Cash/CashDrawerService.php:173-174` : `(float) opening + (float) movementsSum` rounded to 2 — acceptable for ≤ 10⁵ EUR, but France NF525 audits commonly require BCMath. | Migrate to integer cents (`opening_amount_cents INT`) or wrap arithmetic in BCMath `bcadd($a, $b, 2)`. Same concern in `ZReportCashEnrichmentService:66-68` sum-of-floats. |
| **A09-P1-2** | P1 | `app/Http/Controllers/Admin/Pos/CashDrawerController.php:25` | Hardware drawer-open route `POST /api/admin/pos/cash-drawer/open` accepts no order context, no idempotency key, **no audit log of who opened the drawer when** (and why). The route only fires `EscPosPrinterService::openDrawer`. A rogue cashier can pop the drawer at will between sales. | l.19-33 entire method dispatches to printer service with no `CashMovement::TYPE_DRAWER_OPEN` insertion. The constant `TYPE_DRAWER_OPEN` exists (`CashMovement.php:27`) but is never emitted anywhere in production code (grep confirmed). | After successful `openDrawer`, if a session is OPEN, emit a `cash_movements` row type=`drawer_open`, amount=0, direction=`in`, notes="hardware pop by user X reason Y". Add `permission:pos.drawer.open` if dual-permission desired. |
| **A09-P1-3** | P1 | `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:127-143` `current` endpoint | Returns the session for `(branch_id, user_id)` only. If two cashiers share a tablet (shift change without close), the second logs in, sees `data:null`, opens a new session, and the first cashier's session **stays OPEN orphan**. No "stale session" detection / warning. | l.137 `findOpenSessionForUser` is scoped per user. There is no endpoint to list ALL open sessions on a branch (orphan recovery). | Add `current_for_branch` endpoint (manager only) listing every OPEN session on the branch with `opened_at` age. Optionally auto-emit a warning if `opened_at > 12h`. |
| **A09-P1-4** | P1 | `app/Services/Cash/CashDrawerService.php:198-270` `recordMovement` strict=false default + caller pattern | All four production hooks (`recordCashOrderMovement` x2, future cashback) call `recordMovement` with `strict=false` (default), so any failure inside is swallowed. There is **no metric / Prometheus counter / Bugsnag alert** on `[F-003] recordCashOrderMovement failed (non-blocking)`. In high-traffic, a recurring failure silently destroys Z reconciliation. | `PaymentService.php:275-279` catch + Log::warning only. No `\Sentry::captureException` or counter. | Wire each `[F-003] recordCash*Movement failed` warning to a high-priority log channel + alerting. Optionally implement a small "outbox" : a `pending_cash_movement` table the cron can retry. |
| **A09-P1-5** | P1 | `app/Services/Cash/CashDrawerService.php:35-93` openSession + `CashDrawerSessionController.php:48-54` | Admin (branch_id=0) cannot open a session — returns 422. **But there is no UI affordance to switch context to a branch first.** Effective consequence : Admins cannot collect cash on any branch unless they have a branch_id assigned. Acceptable, but the 422 message is non-i18n EN-only ("Cannot open a cash drawer session without a branch context"). | `CashDrawerSessionController.php:51-54`. Hardcoded EN string. | Wrap in `__('cash.session.no_branch_context')` + add translation keys. |
| **A09-P2-1** | P2 | `app/Models/CashDrawerSession.php:59-63` BranchScope on model | `CashDrawerSession::query()` always applies BranchScope. `ZReportCashEnrichmentService` correctly uses `withoutGlobalScope`. But the model boots scope on **every** query, and the service `reconcileSession:147-164` does `whereKey($sessionId)->lockForUpdate()->firstOrFail()` — if an admin (branch_id=0) runs reconciliation cross-branch, the scope allows pass-through (admin sees all). However, when staff B (branch_b) holds an active session and the cron tries to reconcile within a system context (no auth), the scope filter on branch_id may fail. Cross-check needed. | `app/Models/Scopes/BranchScope.php` (not read here — TBD if it gates auth-less context properly). | Verify BranchScope behaviour when `Auth::user()` is null (cron context). Tests `CashDrawerServiceTest` always `actingAs` so this code-path is uncovered. |
| **A09-P2-2** | P2 | `app/Services/Cash/CashDrawerService.php:242-258` `recordMovement` reload session | `recordMovement` re-fetches the session WITHOUT `lockForUpdate()` and outside any transaction. A race exists : cashier A closes the session at T1 ; cashier B's order PAID hook reads session at T0 (status=open) and inserts a movement at T2 → movement attached to an already-closed session. The DB schema has no CHECK constraint preventing it. | l.242 `CashDrawerSession::query()->find($sessionId)` no `lockForUpdate()`. Status check at l.252 is non-atomic. | Wrap recordMovement in `DB::transaction(fn () => …->lockForUpdate()…)` and re-check status inside the lock. Alternatively, enforce `cash_movements.status_at_create = session.status` via trigger. |
| **A09-P2-3** | P2 | `app/Services/Cash/CashDrawerService.php:168-175` reconcile uses `Collection::sum` | `CashMovement::query()->where('cash_drawer_session_id', $session->id)->get()->sum(fn ($m) => $m->signedAmount())` loads ALL movements into memory then sums in PHP. For a busy branch with 1000+ sales/day, this is N+1-ish on the model boot scope + memory pressure. | l.168-171. | Use a SQL aggregate : `DB::table('cash_movements')->where('cash_drawer_session_id', $id)->select(DB::raw("SUM(CASE WHEN direction='in' THEN amount ELSE -amount END) as sum"))->value('sum')`. |
| **A09-P2-4** | P2 | `app/Services/Cash/CashDrawerService.php:101-133` closeSession + missing closed_by | `closed_by_user_id` is not captured. The model only stores `opened_by_user_id`. If session is closed by a manager / different cashier (shift end), identity is lost. Forensic gap for fiscal trace. | Schema migration `2026_05_08_140000:32-53` has no closed_by column. closeSession l.126-129 doesn't capture caller identity. | Add `closed_by_user_id` column + capture `Auth::id()` in `CashDrawerService::closeSession`. Same idea for `reconciled_by_user_id`. |
| **A09-P3-1** | P3 | `app/Models/CashMovement.php:36-44` fillable | `branch_id` is fillable on `CashMovement`. The service correctly sets it from the session (`recordMovement:263`), but if a future caller forgets, a mass-assignment can spoof `branch_id`. Defense-in-depth recommendation. | l.38 `'branch_id'` in `$fillable`. | Remove `branch_id` from `$fillable` and force the service to compute it. |
| **A09-P3-2** | P3 | `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:148-171` movements endpoint | No pagination. A 6-month-old session can have thousands of movements. | l.152-165 `->get()->map(...)`. | Add `->paginate(perPage: 100)` or simple cursor with `?after_id=`. |

---

## 3. Adversarial scenarios

### Scenario A — Concurrent POS login same cashier same branch
**Setup** : Cashier C on tablet T1 hits `Open Session 100€` ; simultaneously on tablet T2 same login, same branch, hits `Open Session 200€` within 50ms.
**Pre-fix expected** : 2 OPEN sessions, broken I1.
**Post-fix actual** : Layer 1 (cache lock) serialises ; Layer 2 (lockForUpdate) blocks select ; Layer 3 (UNIQUE partial) rejects insert. The second request gets `409 Conflict`. Verified by `CashDrawerConcurrentSessionTest::test_two_simultaneous_open_calls_yield_single_session_and_one_409`.
**Result** : PASS (P0-09 remediated).

### Scenario B — Cash payment without open session
**Setup** : Cashier opens POS WITHOUT clicking "Open Session". Customer pays cash 25 €. Order is `paid`.
**Result** : `recordCashOrderMovement:258` logs INFO and returns. Order is PAID. No `cash_movements` row. End of day, Z aggregates: `cash_variance = 0`, `cash_movements_count = 0`. Cashier's drawer has +25 € physical, Z says 0 movement. **NF525 audit-trail INVISIBLE**. Findings: A09-P0-2.

### Scenario C — Session close with huge variance and walk-away
**Setup** : Opening 100 €, sales recorded 50 €, expected close 150 €. Cashier counts 150 € but declares `closing_amount = 50` (pocketing 100 €).
**Result** : `closeSession` accepts. `reconcileSession` computes `expected=150, variance=-100`. Session marked RECONCILED. **No gate, no manager approval, no `variance_reason` required.** Z report shows -100 € variance silently. Findings: A09-P0-3.

### Scenario D — Hardware drawer-pop unaudited
**Setup** : Cashier hits "Open Drawer" hardware button (route `POST /api/admin/pos/cash-drawer/open`) between sales (e.g. to swap a 10 € for a 20 €). No order context.
**Result** : `EscPosPrinterService::openDrawer` fires. **No `cash_movements` row, no audit log.** A cashier can pop the drawer 50× per shift with zero trail. Findings: A09-P1-2.

### Scenario E — Race between close and order-paid hook
**Setup** : T0=cashier clicks Close; T0+5ms=order PAID dispatch fires recordCashOrderMovement; both threads commence.
**Result** : `recordMovement:242` reads session without lockForUpdate. At T0+10ms session becomes CLOSED. recordMovement reads "open" at T0+5ms (stale) and inserts movement at T0+12ms attached to a now-closed session. Reconcile will count it, but the session shows movement AFTER closed_at. Forensic anomaly. Findings: A09-P2-2.

### Scenario F — cascadeOnDelete trap
**Setup** : An admin (with table-level access) accidentally `DELETE FROM cash_drawer_sessions WHERE id=42`. The DB has no BEFORE DELETE trigger here.
**Result** : Session row deleted ; CASCADE wipes all attached cash_movements. Z reports for that period now under-report. NF525 6-year retention violated. Findings: A09-P0-1.

---

## 4. Verdict

- P0-09 (concurrent open) is rigorously hardened — exemplary triple-defense pattern.
- 3 new P0 surfaced : **cascadeOnDelete on cash_movements** (A09-P0-1), **silent cash-without-session** (A09-P0-2), **no variance gate on close** (A09-P0-3).
- 5 P1 cover hardware-drawer audit gap, fiscal-grade arithmetic, identity tracking on close, alerting on best-effort failures, orphan-session recovery.
- 2 P2 cover record-movement race and aggregation efficiency.
- 2 P3 cover defense-in-depth + pagination.
- The session lifecycle (open/close/reconcile) is well-tested at the unit level ; **the integration with PaymentService is the weak link** — fault-tolerance (best-effort) compromises auditability (NF525).

**Recommendation** : keep the triple-lock pattern, fix P0-1 (FK action), implement P0-2 (orphan cash visibility), and add a manager-approval threshold for P0-3 variance.
