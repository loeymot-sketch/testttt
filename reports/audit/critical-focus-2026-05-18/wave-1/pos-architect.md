# GStack Architect — POS Caisse — Wave 1 (resumed)

> **HEAD** `f24b49c42` on `v1-0-1-hardening-2026-05-17`. **Read-only, file:line strict.** Structural / invariant-locus focus — intentionally orthogonal to `pos-adversarial.md` POS-RED-01..07. LOCAL Le Cayenne, NF525, no cloud.
> **Brief drift**: brief lists `app/Services/Payments/PaymentService.php` — actual is `app/Services/PaymentService.php`. `SplitPaymentService` IS under `app/Services/Payments/`. Citations below use real paths.

---

## 1. POS surface inventory (file:line)

### Controllers

| File | Entry-point(s) | Role |
|---|---|---|
| `app/Http/Controllers/Admin/PosController.php:54-75` | `store(PosOrderRequest)` | POS order create; CASH precondition + service delegate |
| `app/Http/Controllers/Admin/PosController.php:90-143` | `assertCashDrawerSessionOpenIfCashInvolved` | Tier-1 controller guard before order creation |
| `app/Http/Controllers/Admin/PosController.php:149-162` | `walkInCustomer` | Walk-in user resolver (permission:pos at L151) |
| `app/Http/Controllers/Admin/PosController.php:164-215` | `quote` | POS pricing quote; dual-surface (POS + kiosk) — see surface guard L172 |
| `app/Http/Controllers/Admin/PosOrderController.php:24-38` | `__construct` | Permission middleware family: `pos-orders` (mutate) / `pos-orders|pos` (read) |
| `app/Http/Controllers/Admin/PosOrderController.php:47-91` | `refundWithCounterEntry` | NF525 P11-FZH mirror order |
| `app/Http/Controllers/Admin/PosOrderController.php:104-128` | `show(int|string $order)` | IDOR timing-leak hardening (Wave 5I A.1) — explicit ModelNotFound → 403 unify |
| `app/Http/Controllers/Admin/PosOrderController.php:130-143` | `destroy` | HttpException pass-through (POS-9.1.2 status preservation) |
| `app/Http/Controllers/Admin/PosOrderController.php:155-175` | `changeStatus` / `changePaymentStatus` | State machine delegate |
| `app/Http/Controllers/Admin/PosOrderController.php:177-190` | `selectDeliveryBoy` | GOAL P0-LIV-01 HttpException preserved |
| `app/Http/Controllers/Admin/PosOrderController.php:197-226` | `reorderItems` | Past-cart re-import (permission:pos-orders at constructor L34) |
| `app/Http/Controllers/Admin/Pos/CashDrawerController.php:25-77` | `open(Request)` | Hardware drawer pop via `EscPosPrinterService::openDrawer` (printer-level) |
| `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:38-69` | `open` | Logical session open (cashier-level) |
| `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:75-96` | `close` | Logical session close + manager-gate (config opt-in) |
| `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:109-145` | `reconcile` | Variance gate F-4 |
| `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:151-167` | `current` | OPEN session probe |
| `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:172-195` | `movements` | Session movement history |
| `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:234-255` | `assertSessionVisibleToUser` | Wave 2 ownership tightening (heal commit `5df225ffa`) |
| `app/Http/Controllers/Admin/Pos/ParkedOrderController.php:18-69` | `index/store/show/destroy` | Park / recall workflow |
| `app/Http/Controllers/Admin/Pos/ParkedOrderController.php:72-101` | `resolveOperatorContext` | Branch-scoped guard (P0-POS-04 GOAL round-2) |
| `app/Http/Controllers/Admin/Pos/FloorplanController.php:13-67` | DiningTable assign/release/transfer | dine-in (V1 disabled per feedback flag) |
| `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php:35-75` | `increment(Request, int)` | Receipt counter + NF525 duplicata audit (W9.B) |
| `app/Http/Controllers/Admin/Pos/CustomerNfcLookupController.php:19-50` | `lookup` | NFC → customer (branch + role filter L30-34) |
| `app/Http/Controllers/Admin/PaymentTerminalController.php:31-94` | TPE CRUD | Permission `settings` on mutate (L28); soft-archive on destroy |
| `app/Http/Controllers/Admin/Fiscal/ZReportController.php:22-89` | Z report open/close/show/pdf | Permission `pos-manage-fiscal` via `authorizeFiscal()` L91-96 |
| `app/Http/Controllers/Admin/Fiscal/XReportController.php:22-38` | X snapshot | Same permission family |

### Services

| File | Key method(s) | Role |
|---|---|---|
| `app/Services/OrderService.php:563-1042` | `posOrderStore(PosOrderRequest)` | Idempotency cache lock L577-596 ; DB::transaction L601-1042 ; fiscal_sequence_no alloc L922-923 ; split persistence L1010-1015 ; cash_movement strict write L1032-1039 |
| `app/Services/PaymentService.php:28-66` | `payment` | Gateway-only context guard L46 (`assertGatewayContext` L585-616) |
| `app/Services/PaymentService.php:90-156` | `cashBack` | Cash-back transaction + drawer movement L142 + RefundCreated L152 |
| `app/Services/PaymentService.php:158-265` | `confirmCounterPayment` | Kiosk counter-collect; lockForUpdate L185-188 ; fiscal alloc on demand L206-208 |
| `app/Services/PaymentService.php:289-362` | `recordCashOrderMovement` | Strict/soft cash movement writer ; simulation downgrade L298-300 |
| `app/Services/Payments/SplitPaymentService.php:51-165` | `validateBreakdown` | Modes whitelist L66-72 ; CASH `tendered` L96-109 ; CARD `terminal_id` defense-in-depth L117-137 ; ±1€ overpay L143-164 |
| `app/Services/Payments/SplitPaymentService.php:177-294` | `persistTranches` | Pre-tx CASH session resolve L194-218 (with `simulation_hardware` skip L206-207) ; row inserts L245-255 ; per-tranche cash movement strict L277-287 |
| `app/Services/Cash/CashDrawerService.php:52-118` | `openSession` | Triple-defense: cache lock L78 + lockForUpdate L84 + UNIQUE partial index (migration 2026_05_10_020000) |
| `app/Services/Cash/CashDrawerService.php:126-205` | `closeSession` | Manager-gate L151-160 ; idempotent L177-179 ; `closed_by_user_id` L188 |
| `app/Services/Cash/CashDrawerService.php:225-354` | `reconcileSession` | Variance F-4 gate L266-312 ; audit binding L337-347 |
| `app/Services/Cash/CashDrawerService.php:365-470` | `recordMovement` | Race-resistant: SELECT FOR UPDATE in tx L422-445 |
| `app/Services/Cash/CashDrawerService.php:475-482` | `findOpenSessionForUser` | Owner-scoped session lookup (used by 3 callers) |
| `app/Services/Order/RefundWithCounterEntryService.php:52-233` | `execute(Order, string)` | NF525 mirror: fiscal alloc L90 ; mirror create L95-117 ; items mirror L120-143 ; payments mirror L163-192 ; audit + RefundCreated L194-229 |
| `app/Services/Pos/WalkInCustomerResolver.php:14-42` | `resolve` | Canonical walk-in user (firstOrCreate by email L16-37) |

### Config / Boot

- `config/pos.php:37` — `simulation_hardware` flag (`POS_SIMULATION_HARDWARE` env)
- `config/pos.php:58-61` — `featured_category_ids` (POS landing strip)
- `app/Providers/AppServiceProvider.php:85-91` — Production boot guard (RuntimeException if `simulation_hardware=true` in prod)
- `app/Providers/AppServiceProvider.php:97-141` — Adjacent prod guards (payment.bypass, printing.bypass, broadcast.default, queue.default, cache.default)

### Frontend (audit-only)

- `resources/js/components/admin/pos/PosComponent.vue` (192,217 bytes)
- `resources/js/components/admin/pos/PaymentComponent.vue` (66,549 bytes) — terminal_id auto-attach L654-665, split payload build L825-848
- FROZEN: `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` (cited, never edited)

---

## 2. Critical invariants (locus + enforcement mechanism)

| Invariant | Primary locus | Enforcement | Defense-in-depth |
|---|---|---|---|
| **I1 — CASH requires OPEN session** | `PosController.php:90-143` | Controller pre-create guard ; throws `CashDrawerSessionNotOpenException` 422 | `OrderService::posOrderStore` L1032-1039 strict=true ; `SplitPaymentService::persistTranches` L207-218 strict ; `PaymentService::recordCashOrderMovement` L301-335 |
| **I2 — CARD requires `terminal_id`** | `SplitPaymentService.php:117-137` | Validation: `>0` + branch-scoped + ACTIVE check via `PaymentTerminal::withoutGlobalScopes` | `PosOrderRequest` withValidator (cited from `PaymentComponent.vue:112` + adversarial scan) ; sentinel `PosSplitPaymentPhantomCardSentinelTest:148,175,201` |
| **I3 — `fiscal_sequence_no` monotonic per branch** | `OrderService::posOrderStore` L922-923 ; `PaymentService::confirmCounterPayment` L206-208 ; `RefundWithCounterEntryService::execute` L90 | `FiscalSequenceService::next($branchId)` — frozen ; runs Cache::lock + lockForUpdate INSIDE its own tx ; nesting creates SAVEPOINT (rollback-safe) | Z report close re-reads max seq per branch ; no caller bypasses the service |
| **I4 — Refund mirror inside CURRENT Z window** | `RefundWithCounterEntryService.php:54-83` | `InvalidArgumentException` if parent has no fiscal seq L54-58 ; `SealedOrderGuard::assertSealed` L70-71 ; duplicate-RETURNED guard L73-77 | Parent `composition_snapshot` never touched (mirror creates fresh rows L121-143) ; mirror payments NEGATED with `terminal_id` carried over L175-191 |
| **I5 — Production guard active** | `AppServiceProvider.php:78-91` | `if (app()->environment('production') && config('pos.simulation_hardware'))` → `RuntimeException` at boot | Adjacent guards on payment.bypass / printing.bypass / cache driver L97-141 — refuses to boot rather than silently corrupt fiscal trail |

---

## 3. Weak spots (structural, post-Wave 2 residue)

- **W-A1 — `simulation_hardware` flag read in 3 loci**: `PosController.php:95-97`, `SplitPaymentService.php:206-207`, `PaymentService.php:298-300`. Drift surface if a future payment path forgets one read. Recommend single `HardwareSimulationGate` adapter (R-3).
- **W-A2 — `findOpenSessionForUser` returns without lock** (`CashDrawerService.php:475-482`). Called by `PosController.php:136-139`, `SplitPaymentService.php:211-218`, `PaymentService.php:317-318`. Race closed downstream at `recordMovement` FOR UPDATE L422-445 (fail-closed), but the controller path can hold stale "session exists" view. Not exploitable, structurally fragile.
- **W-A3 — UNIQUE partial index scoped per (branch_id, user)** (service comment L65-71). If V1.0.2 introduces multi-branch cashiers, invariant changes. V1 single-branch = safe.
- **W-A4 — Walk-in customer `branch_id=0`** (`WalkInCustomerResolver.php:36`); the order carries operator's branch_id. Adjacent guards key on `order.branch_id` so not exploitable, but deserves docblock at `OrderService::posOrderStore` L626-638.
- **W-A5 — `PosReceiptPrintController` extends `Controller` not `AdminController`** (L29) ; ctor L31-33 has no `$this->middleware()`. Gate must live on the route. Wave 3 sweep should cross-check `routes/api.php`.
- **W-A6 — `CashDrawerSessionController::open` reads `$user->branch_id` directly** (L47), no payload override. `branch_id=0` Admin 422s at L50-54. Correct V1 behavior; flag if V1.0.2 admin-roving lands.

---

## 4. Existing test coverage map (test → invariant)

| Test file | Methods | Maps to invariant |
|---|---|---|
| `tests/Feature/Pos/CashDrawerSessionOwnershipTest.php:77,101,125` | 3 methods: same-branch non-owner blocked / owner OK / Branch Manager OK | Wave 2 heal (POS-RED-04) — supplements I1 |
| `tests/Feature/Pos/PosCashTrailTest.php:138,166,195,240,270,314` | 6 paths covering POS-CASH + session, POS-CASH no session 422, Split-CASH+CARD, Split 2-CASH no session, kiosk counter-collect (regression × 2) | I1 (full matrix) |
| `tests/Feature/Pos/SplitPaymentEndToEndTest.php:139,165,182,200,217,248` | 6 methods: persist 2 rows / legacy single-tender / sum < total 422 / no tendered 422 / resource shape / flag-off fallback | I2 + split flag gating |
| `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php:122,143,174,206,252,291` | 6 scenarios S1..S4 + KDS surface + simulation-off regression | I5 + I1 simulation skip |
| `tests/Feature/Pos/TerminalIdWireInTest.php:81,122,162,198,262` | 5 methods: persist `terminal_id` / CARD without 422 / CASH nullable / refund mirror carry-over / Z TPE breakdown | I2 + I4 (mirror carries terminal_id) + Z aggregation |
| `tests/Feature/Pos/FritesWizardComposerTest.php:196,211,230,248` | 4 frites composition matrix | Composition adjacent (out of strict Zone 2 scope but cited in plan) |
| `tests/Feature/Sentinels/SplitPaymentSentinelTest.php:135,153,181` | 3 sentinels: sum<total 422 / branch_id denormalised / silent ignore when flag off | I2 + branch isolation |
| `tests/Feature/Sentinels/PosSplitPaymentPhantomCardSentinelTest.php:148,175,201` | 3 sentinels: CARD without terminal_id / cross-branch terminal / valid persistence | I2 (phantom-CARD canonical) |

**Trait coverage helper**: `tests/Feature/Pos/Traits/SeedsOpenCashDrawerSession.php` (only file in Traits/) — used by PosCashTrail + SplitPaymentEndToEnd to seed an OPEN session deterministically.

---

## 5. Test coverage GAPS

- **G-1 — No test asserts `PaymentService::recordCashOrderMovement` simulation downgrade L298-300** (strict→soft when `pos.simulation_hardware=true`). PosSimulationHardware4Scenarios covers controller + split paths but not the standalone `recordCashOrderMovement` legacy entry-point.
- **G-2 — No test on `CashDrawerSessionController::reconcile` cross-cashier scenario after Wave 2 heal** — only `closeSession` covered by `CashDrawerSessionOwnershipTest`. `reconcile` and `movements` inherit the gate (both call `assertSessionVisibleToUser` L111, L174) but no test directly probes that the non-owner cashier hits 403 there.
- **G-3 — No test on `AppServiceProvider` production boot guard** (`AppServiceProvider.php:85-91`). The plan's chronological scenario 09:01 ("Production guard verification") has no sentinel. Recommended: `tests/Feature/Boot/PosSimulationProductionGuardTest` — set `APP_ENV=production` + `POS_SIMULATION_HARDWARE=true` → expect `RuntimeException` during `Application::boot`.
- **G-4 — No test on `RefundWithCounterEntryService::execute` when parent has split-payment OrderPayments rows beyond the happy path of single CASH/CARD tranche** — i.e. 3+ tranches incl. TICKET_RESTAURANT mode. The mirror path L163-192 iterates without filter so should be safe by construction, but no positive sentinel exists.
- **G-5 — No test on `PosReceiptPrintController::increment` race condition** between two POS stations triggering the same `order_id` simultaneously (atomic UPDATE at L43-48 is correct, but unverified under concurrency).
- **G-6 — No test on `ParkedOrderController::resolveOperatorContext` branch-zero Admin abort** (L93-98 GOAL P0-POS-04 heal). Comment cites the fix but no sentinel exercises a `branch_id=0` Admin getting 403.

---

## 6. Wave 2 heal verification (commit `5df225ffa`)

**Diff inspected**: 4 files / 282 insertions ; controller heal ~10 LOC + EmployeeRequest ~10 LOC + 2 new tests.

### Heal #1 — `CashDrawerSessionController::assertSessionVisibleToUser` L234-255
- **Pre-fix**: branch-only `abort_if((int) $session->branch_id !== (int) $user->branch_id, 403);`.
- **Post-fix L247-254**: explicit cross-branch message, then `$isOwner = session.opened_by_user_id === user.id` OR `$isManager = can('cash.reconcile.variance.override') || hasRole('Admin'|'Branch Manager')`.
- **Inheritance scan**: `close()` L81, `reconcile()` L111, `movements()` L174 — all call the helper → **covered**. `current()` L151-167 uses `findOpenSessionForUser(branchId, userId)` L161 (implicit owner scope). `open()` L38-69 creates against `$user->id` L57 (covered by construction).
- **Test**: `CashDrawerSessionOwnershipTest.php` 3 methods on `close` only. Gap G-2 stands.
- **Verdict**: code heal complete ; test surface incomplete (G-2).

### Heal #2 — `EmployeeRequest::authorize` L18-27
- `return true;` → `can('employees_create') || can('employees_edit')`. Sentinel `tests/Feature/Admin/EmployeeRequestAuthorizeTest.php` (113 LOC). Out of strict Zone 2 scope but acknowledged. **Complete.**

### Adjacent (informational)
`8dc6ec331` outbox DLQ audit (Zone 6). `048c48439` `fiscal:verify-chain` (Zone 1). `181abdef4` KDS whereBetween sentinel (Zone 3). No Wave 2 heal touched `PosController`, `OrderService::posOrderStore`, `SplitPaymentService`, `PaymentService`, `RefundWithCounterEntryService` — pre-Wave 2 baseline preserved.

---

## 7. Recommendations

- **R-1 (test-only, P1)** — Close gap G-3 with `PosSimulationProductionGuardTest` (boot guard sentinel). Mirrors plan §2 09:01 scenario. ~30 LOC.
- **R-2 (test-only, P2)** — Close G-2 with `CashDrawerSessionOwnershipTest::reconcile_non_owner_blocked` + `movements_non_owner_blocked`. ~40 LOC.
- **R-3 (refactor, P2, INLINE-EDIT-EXCEPTION-eligible)** — Surface a tiny `HardwareSimulationGate` value-object holding the `pos.simulation_hardware` read so the 3 loci (W-A1) collapse to one. ~25 LOC + 1 sentinel.
- **R-4 (sentinel, P2)** — Close G-4 with a 3-tranche split refund test asserting mirror payments count == parent.
- **R-5 (sentinel, P3)** — Add concurrency probe for `PosReceiptPrintController::increment` (G-5) using `DB::transaction` + parallel runners — likely deferred to V1.0.2.
- **R-6 (documentation, P3)** — Add docblock at `OrderService::posOrderStore` L626-638 noting walk-in customer carries `branch_id=0` while order carries operator's branch_id, citing `WalkInCustomerResolver.php:36` (W-A4).

### Out of scope (not architect's call)
- POS-RED-01 latent mass-assignment / POS-RED-02 Z-close race / POS-RED-03 cash_movements actor column / POS-RED-04..07 — all covered in `pos-adversarial.md` ; cross-validation tracked there.
- Frozen-zone touches (`pos-wizard.js`, `FiscalSequenceService`, `PricingService`) — LOCK plan required, never inline.

---

GStack Architect — POS — Wave 1 (resumed)
