# Agent 1 — POS Caisse Specialist — Round 1 Audit (read-only)

**Date** : 2026-05-18
**Branch** : `v1-0-1-hardening-2026-05-17` (HEAD `1235e3e1a` working-tree)
**Scope** : System 1 POS Caisse — sub-systems 1.1 → 1.4
**Methodology** : Phase A of `ultra-audit-profond` (5 specialist lenses, read-only)
**Frozen-zones touched** : zero (read-only). Findings flagged `[FROZEN-LOCK-REQUIRED]` where applicable.

---

## §1 — Anchor verification

```
$ find app/Http/Controllers -path "*Pos*" -type f
app/Http/Controllers/Admin/PosCategoryController.php
app/Http/Controllers/Admin/AdminPosV4Controller.php
app/Http/Controllers/Admin/PosController.php
app/Http/Controllers/Admin/PosOrderController.php
app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php
app/Http/Controllers/Admin/Pos/CustomerNfcLookupController.php
app/Http/Controllers/Admin/Pos/CashDrawerController.php
app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php
app/Http/Controllers/Admin/Pos/FloorplanController.php
app/Http/Controllers/Admin/Pos/ParkedOrderController.php

$ ls -la public/js/pos-*.js
-rw-r--r--  6958043  pos-app.js
-rw-r--r--  1351676  pos-shell.js
-rw-r--r--   296912  pos-wizard.js   (FROZEN)

$ ls app/Services/Payments/  app/Services/Fiscal/
Payments/   SplitPaymentService.php
Fiscal/     AuditLogService.php  FiscalChainValidator.php  FiscalSealingService.php
            FiscalSequenceService.php  XReportService.php  ZReportCashEnrichmentService.php
            ZReportService.php

$ ls tests/Feature/Pos/
DiningTableReleaseAfterPosOrderTest.php   PosCashTrailTest.php
FloorplanControllerTest.php               PosMenuRuntimeAccessTest.php
FritesWizardComposerTest.php              PosOrderRequestNoClientTotalsTest.php
PosPurgeParkedScheduleTest.php            PosSimulationHardware4ScenariosTest.php
PosWalkInCustomerApiTest.php              QuoteBindingTest.php
SplitPaymentEndToEndTest.php              TerminalIdWireInTest.php

$ git log --oneline -10 -- app/Http/Controllers/Admin/PosController.php
2477a2d05 fix(pos): P0-#1 — commit POS_SIMULATION_HARDWARE triad + production boot guard
c9509b3ad feat(hardening): Sprint 4 — RBAC POS quote/walk-in close POS-A3
7e62f7bbc feat(wave-z-5b): cash forensic + POS auth
2e3635d64 feat(cash-trail): Sprint 1B — POS direct + split tranches CASH
9730b18e7 up
b873d4728 up
209bbc515 testt

$ git log --oneline -10 -- app/Services/PaymentService.php
2477a2d05 fix(pos): P0-#1 — commit POS_SIMULATION_HARDWARE triad
55edb83ba fix(v1-cloud-prep): Wave 5F
9024a1050 i18n(pos-cash) add cash_session_*
2b5c69412 audit(F-003.4) PaymentService hooks
bebcf7054 [BYPASS] Mode bypass payment+impression
b873d4728 up
a7036f6ec feat(pos/9.4.bl.2) AuditLogService on sensitive actions
209bbc515 testt

$ grep -rn "fiscal_sequence_no|composition_snapshot|simulation_hardware" app/Services/ | head -10
PaymentService.php:117/188/189/223/280/434       (fiscal_sequence + simulation_hardware)
OrderService.php:455/810/922/1018/1266/1758/...  (composition_snapshot + alloc)
Order/SealedOrderGuard.php:23/42/90/92           (sealed-order discipline)

$ php artisan tinker --execute="echo 'count='.AuditLog::count().' | last_hash='.AuditLog::orderByDesc('id')->value('current_hash');"
count=26 | last_hash=ca4ac1fdc208dae1733b79bc368c9439445059a703424657bba31325be7ca828
```

NF525 chain: **26 rows | last_hash starts `ca4ac1fdc208dae1`** — matches BRAIN attestation, no drift.

---

## §2 — Sub 1.1 POS Wizard findings

Frozen files: `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`. Scope = non-frozen backend (PosController, OrderService quote, walk-in resolver, NormalizePosRuntimePayload).

| Sev | Finding | File:line | Reproduction | Fix sketch |
|---|---|---|---|---|
| P0 | **Wizard-data drift sentinel missing** — root cause of 2026-05-18 incident "Composition #N n'appartient pas au profil" (BRAIN §LAST DONE) is **wizard profile DB ↔ Vanilla JS step mismatch**. No automated guard currently fails CI when a new Item exists without aligned composer profile. | `app/Http/Controllers/Admin/PosController.php:54-75` (no pre-store profile validation) ; data seeders `database/seeders/AlignProfile85*` + `AlignFritesWizardProfilesSeeder` | Create Item → `wizard_template != 'simple'` → POS submit without matching profile step → 422 generic. | NEW test `tests/Feature/Pos/WizardProfileParityTest.php` — for every Item with non-simple `wizard_template`, assert composer profile has all required steps (viande, crudite, sauce per template). Catches the 2026-05-18 incident class before prod. |
| P1 | **`normalizePosRuntimePayload` silently re-writes `delivery_charge`** when caller provides `delivery_distance_km`, overwriting any explicit `delivery_charge` from client. | `app/Http/Controllers/Admin/PosController.php:230-234` | POST with both fields → caller's `delivery_charge` silently dropped. | Guard: only overwrite when `delivery_charge` absent OR add audit log entry "delivery_charge_recomputed_from_km". |
| P1 | **`PosController::quote` and `::store` use surface-aware permission bypass for `api/frontend/*`** but the URL pattern check is duplicated (line 172 + line 195 + line 219) and not centralized. A new route group that mounts the same controller would silently inherit the bypass without explicit allowlist. | `app/Http/Controllers/Admin/PosController.php:172, 195, 219` | A future controller-share route `api/internal/quote` → permission gate would not apply if route accidentally renamed `api/frontend/*`. | Extract `isKioskSurface(Request)` private helper + add `route()->getName()` allowlist; sentinel `PosControllerSurfaceGateTest`. |
| P2 | **`reorderItems` decodes `composition_snapshot` for re-import** but never verifies that referenced `variation_id` / `extra_id` still exist + ACTIVE → stale references can be re-pushed to cart silently. | `app/Http/Controllers/Admin/PosOrderController.php:222-282` | Variation deleted → past order re-order pushes ghost variation id with stale price → backend pricing SSOT will catch via PricingService, but UX is misleading. | Filter variations/extras through `ItemVariation::active()->whereIn('id', $ids)` before returning. |
| P2 | **Sensitive routes have no rate-limit** — `walk-in-customer` lookup is throttle-free; a compromised staff token could enumerate customer counts. | `routes/api.php:722-724` + `app/Http/Controllers/Admin/PosController.php:149-162` | Authenticated POS user spams `/walk-in-customer` to time-correlate other ops. | Add `throttle:pos-walkin,60,1` middleware (60/min). |

---

## §3 — Sub 1.2 POS Payment findings

| Sev | Finding | File:line | Reproduction | Fix sketch |
|---|---|---|---|---|
| P0 | **`Stripe::payment` does NOT pass order_id metadata** to the Stripe charge, so the webhook handler at `Stripe::handleWebhook` falls back to `null` and **silently skips writing `CapturePaymentNotification`** when `metadata.order_id` is absent. Effect: live charges placed via `payment()` (non-webhook path) work fine via the legacy `success()` flow, but any out-of-band webhook capture (retries, async charges) loses the order linkage. | `app/Http/PaymentGateways/Gateways/Stripe.php:57-62` (create call without metadata) → `app/Http/PaymentGateways/Gateways/Stripe.php:266-289` (webhook skip path) | Trigger a Stripe charge → simulate webhook arriving before `success()` callback → `CapturePaymentNotification` never created → order stays PENDING. | Add `'metadata' => ['order_id' => (int) $order->id]` to the `charges->create` payload at line 57-62. Sentinel: `StripeWebhookOrderMetadataTest` asserts metadata round-trip. Aligns with V1.0.2 backlog "Stripe webhook idempotency parité SenangPay". |
| P0 | **`PaymentService::payment` (line 28-48)** has zero authorization gate — any caller can supply a `transactionNo` and mark an arbitrary `$order` as PAID. The intended caller is the Stripe `success()` flow (line 112) but the method is `public` and has no Auth/Gate check. | `app/Services/PaymentService.php:28-48` | A queue job / future controller invokes `app(PaymentService::class)->payment($otherOrder, 'stripe', 'forged-tx')` → order marked PAID without any actual gateway settlement. | Add `Gate::authorize('payment.settle', $order)` OR refactor to private + add wrapper that asserts caller class. Sentinel: `PaymentServicePaymentRequiresGatewayContextTest`. |
| P1 | **`SplitPaymentService::TOLERANCE_OVERPAY = 1.00€`** is generous and could let a malicious operator pocket up to €0.99 per order by entering tendered cash slightly over total. | `app/Services/Payments/SplitPaymentService.php:36` | `total=10.00`, operator enters CASH tranche `amount=10.99 tendered=10.99 change=0` → accepted, but real cash drawer received exactly €10.00 → €0.99 short. | Lower tolerance to €0.05 (rounding) + add audit log when `totalCents - serverTotalCents > 0` so manager can review overpay pattern. |
| P1 | **`recordCashOrderMovement` strict→soft downgrade under `simulation_hardware=true`** at `PaymentService.php:280-282` — when simulation is ON, the controller skips the open-session check (line 95-97) AND `recordCashOrderMovement` swallows the `strict=true` parameter. **Effect**: a CASH sale with no open session in simulation creates an Order + Transaction row but writes **no `cash_movement` row** (line 302-317 returns silently). **Contract conflict check**: `feedback_pos_simulation_hardware_pattern.md` documented contract is "**bypass HARDWARE (drawer/TPE) only**". Skipping the cash_movement write is borderline — the cash_movement is the audit trail, not the hardware. | `app/Services/PaymentService.php:280-282, 302-317`; contract: `memory/feedback_pos_simulation_hardware_pattern.md` | Set `POS_SIMULATION_HARDWARE=true`, POST CASH order, query `cash_movements WHERE order_id=N` → zero rows. NF525 reporting under simulation will under-report cash. | Either (a) document explicitly that simulation skips cash_movement (update `config/pos.php` docblock + the feedback memory file) OR (b) write cash_movement to a sentinel "simulation" session and exclude from Z-reports. Option (a) is acceptable because production toggle = false. **Decision needed in this audit cycle**. |
| P1 | **`Stripe::handleFromStoredEvent` is a no-op** for `charge.succeeded` replays — it only calls `markProcessed` without re-running the business logic (see comment line 360-366). DLQ retries don't re-create `CapturePaymentNotification` if the original processing failed. | `app/Http/PaymentGateways/Gateways/Stripe.php:340-368` | First webhook receipt throws inside the DB::transaction → row marked failed → DLQ retry → row marked processed but no notification ever created → order stays PENDING. | Refactor `handleWebhook` into thin parser + private `processStripeEvent` (already documented as V1.0.2 TODO at line 333-338). Promote to V1.0.1.x. |
| P1 | **`cancelCounterPayment` dispatches `OrderCanceled` + `OrderStatusChanged` but NOT `RefundCreated`** (the latter is wired in `cashBack` line 134 but not here). Stock/availability listeners attached to `RefundCreated` will not release stock on a counter-payment cancellation. | `app/Services/PaymentService.php:389-449` (cancel path) vs. `:134` (cashBack dispatches RefundCreated) | Order goes to CANCELED via counter-payment cancel → stock decremented at creation stays "sold" until manual reconciliation. | Add `RefundCreated::dispatch($order);` after line 445. Sentinel: `CounterPaymentCancelReleasesStockTest`. |
| P2 | **`PosController::store` swallows ALL `Exception` to 422** at line 72-74 — this can hide HTTP 5xx from underlying services (e.g. `FiscalSequenceService::next` `RuntimeException` on lock contention). | `app/Http/Controllers/Admin/PosController.php:71-74` | Force `Cache::lock` to fail in test → response becomes 422 "could not acquire lock" instead of 503. | Re-raise `RuntimeException` distinctly with 503, keep 422 for `\Exception`. |
| P2 | **`Stripe::handleWebhook` accepts charges via `metadata.order_id` cast `(int)` without validating order exists or branch isolation** — webhook signed by Stripe is trusted, but a forged metadata in a legit signed event could insert a `CapturePaymentNotification` for an unrelated order_id (foreign-branch). | `app/Http/PaymentGateways/Gateways/Stripe.php:273-289` | Manual Stripe Dashboard refund with crafted metadata → notification row created for any order_id. | Wrap insert with `Order::withoutGlobalScopes()->find($orderId)` existence check + branch consistency check vs. paymentGateway branch_id. |

---

## §4 — Sub 1.3 NF525 Cash + Z-Reports — ATTESTATION

**Frozen zone — ATTEST ONLY. No code changes proposed; tests OK to author.**

### Chain attestation (anchor verified)
- `audit_logs.count` = **26**
- `audit_logs.last_hash` = `ca4ac1fdc208dae1733b79bc368c9439445059a703424657bba31325be7ca828`
- **Identical to BRAIN baseline** (no drift since Wave 5I `1235e3e1a`).

### Code attestation (read-only review)
- `FiscalSequenceService::next` (`app/Services/Fiscal/FiscalSequenceService.php:57-104`) — triple defense intact: Cache::lock(5s) → DB::transaction → `Order::withoutGlobalScopes()->lockForUpdate()->max('fiscal_sequence_no')+1`. SQLite no-op on FOR UPDATE documented (line 86-88).
- `AuditLogService::write` (`app/Services/Fiscal/AuditLogService.php:70-132`) — chain-lock per branch + UNIQUE(branch_id, prev_hash) DB constraint + retry-once on collision (line 184-190). Production secret guard (line 303-327) blocks dev sentinels + short secrets in `APP_ENV=production`.
- `AuditLogService::computeHash` (`app/Services/Fiscal/AuditLogService.php:237-243`) — HMAC-SHA256 of `prev_hash || canonicalised(action,payload)` with per-branch secret. Canonicalisation sorts keys recursively (line 358-374) for cross-PHP determinism.
- `composition_snapshot` written at `app/Services/OrderService.php:455, 810, 1266` — JSON encode at creation, never re-written.
- `fiscal_sequence_no` allocation at `app/Services/OrderService.php:922` (creation path) and `app/Services/PaymentService.php:188-189` (counter-payment confirm, lazy alloc). Both gated by `$locked->fiscal_sequence_no === null`.

### Cash drawer + simulation
- `PosController::assertCashDrawerSessionOpenIfCashInvolved` (`app/Http/Controllers/Admin/PosController.php:90-143`) — fail-fast 422 for CASH single-tender or any CASH tranche when no OPEN session. `simulation_hardware=true` skips at line 95-97. Production guard exists in `AppServiceProvider` per BRAIN (Wave 5I `1235e3e1a`).
- `CashDrawerController::open` (`app/Http/Controllers/Admin/Pos/CashDrawerController.php:25-77`) — every drawer pop writes `TYPE_DRAWER_OPEN` movement (Sprint 5B Z10-NEW-001). Forensic gap is logged warning when no OPEN session (line 60-64).
- `SplitPaymentService::persistTranches` (`app/Services/Payments/SplitPaymentService.php:177-294`) — per-tranche `OrderPayment` insert + per-tranche `cash_movement IN` when mode=CASH (line 277-287, `strict: true`). `simulation_hardware` skips the cash_movement step at line 206-218 — see §3 P1 above.

### Findings (Sub 1.3 — frozen zone scope = test-only)

| Sev | Finding | File:line | Reproduction | Test sketch (NO code change) |
|---|---|---|---|---|
| P0 | **`Stripe::handleWebhook` records non-`charge.succeeded` events as "processed" without payload validation** — log-only forensic flow at line 292-296 trusts the stored payload, but the `event.type` is taken verbatim into `webhook_events.event_type` (line 244). A malicious signed event with extreme `event.type` length could overflow column. | `app/Http/PaymentGateways/Gateways/Stripe.php:243-256, 292-296` | Forge Stripe-signed event with 1MB event_type → row insert fails silently OR truncates → log spam. | Sentinel `StripeWebhookEventTypeLengthGuardTest` asserts `event_type` truncated to column max BEFORE insert. (Test only; column constraint is DB layer.) |
| P1 | **Z-report close path not exercised by V1.0.1 test suite** — no test under `tests/Feature/Fiscal/` exercises `ZReportService::close` daily flip + chain continuation post-close. BRAIN attests "manual Z close dry-run OK" but there is no CI guard. | `app/Services/Fiscal/ZReportService.php` (30 KB, FROZEN) | Production day → operator clicks close-Z → if a regression slips, chain breaks → 6-year retention compromised. | NEW `tests/Feature/Fiscal/ZReportCloseChainContinuityTest.php` — open Z, create 3 orders, close Z, assert (a) z_reports row chain hash matches audit_logs HMAC, (b) next audit_log row prev_hash = closed Z report current_hash, (c) DB triggers prevent DELETE on z_reports. |
| P1 | **`audit_logs` DB trigger absent in SQLite test environment** — `BEFORE DELETE SIGNAL SQLSTATE '45000'` is MySQL-only (CLAUDE.md §8 "MySQL prod only"). No test asserts production DDL has the trigger. | migrations `audit_logs` + `z_reports` (FROZEN) | New environment provisioned via Ansible → DDL skips trigger → DELETE allowed → audit tamper-evident broken silently. | NEW `tests/Feature/Fiscal/AuditLogsTriggerPresentTest.php` — when `DB::getDriverName() === 'mysql'`, query `information_schema.TRIGGERS` for trigger existence; skip on SQLite. Plus Ansible playbook check. |
| P2 | **`FiscalSequenceService::LOCK_ACQUIRE_SECONDS = 3`** is tight — at peak rush (200 orders/h on a single branch ≈ 18s per order), lock acquisition should be fine, but on Redis hiccup, `RuntimeException` propagates to user with 500. | `app/Services/Fiscal/FiscalSequenceService.php:43, 69-74` | Redis 1s hiccup during rush → 500 on user → kiosk wizard fails → customer abandons. | Sentinel `FiscalSequenceServiceLockTimeoutTest` — simulate `Cache::lock->block(3)` returning false → assert 503 with `Retry-After` header, not 500. (Code change deferred to LOCK plan — frozen zone.) |

---

## §5 — Sub 1.4 Parked Orders + Refunds + Floorplan findings

| Sev | Finding | File:line | Reproduction | Fix sketch |
|---|---|---|---|---|
| P0 | **`ParkedOrderController::resolveOperatorContext` does NOT verify branch_id > 0** — when an authenticated user has `branch_id=0` (Admin role), the operator context is `[$authId, 0]`. The downstream `PosParkedOrderService::listForOperator` would receive branch=0 and could leak across all branches. (Verifying the service is out of scope but flag confirmed.) | `app/Http/Controllers/Admin/Pos/ParkedOrderController.php:72-81` | Admin user calls `GET /parked-orders` → service receives `branch_id=0` → if service does not handle `0` → returns parked orders from all branches. | Add `abort_unless($branchId > 0, 403, 'Admin must select a target branch');` at line 80. Alternative: route gating on Spatie role excluding Admin from POS register endpoints. |
| P1 | **`PosOrderController::refundWithCounterEntry` only blocks cross-branch refunds for non-Admin** at line 58-61 — an Admin (branch_id=0) can refund ANY order regardless of branch. NF525 requires audit, but no branch consistency check for Admin role itself. | `app/Http/Controllers/Admin/PosOrderController.php:55-62` | Admin operator at branch HQ refunds an order from branch B by mistake → counter-entry refund created in branch HQ's Z window → cross-branch fiscal anomaly. | Require `branch_id` query param for Admin role + assert it matches `$order->branch_id`. |
| P1 | **`FloorplanController` is `permission:pos` only** — no flag check for `pos.dine_in_enabled=false` (per BRAIN `feedback_v1_dine_in_disabled_2026-05-06.md`). The endpoints `state/assign/release/transfer` answer in V1 even though dine-in is officially OFF. | `app/Http/Controllers/Admin/Pos/FloorplanController.php:11-67` ; flag: `config/pos.php` (not yet declaring `dine_in_enabled`) | Operator hits `/floorplan/state` in V1 → returns table data → confusing UX. | Add guard `abort_unless(config('pos.dine_in_enabled', false), 404)` in constructor middleware. |
| P1 | **`ParkedOrderController::destroy` permanent-deletes parked order** without audit trail — operator discard erases evidence; no `AuditLogService::write` record. | `app/Http/Controllers/Admin/Pos/ParkedOrderController.php:61-70` ; underlying `PosParkedOrderService::discard` (not opened) | Operator parks "drunk customer cancels" → discards → no trace. | Add `app(AuditLogService::class)->write(['action'=>'pos.parked_order_discarded', 'resource_id'=>$id])` post-discard. |
| P2 | **`ParkedOrderController::store` validates `idempotency_token` max 64** but **the idempotency middleware is NOT applied to parked-orders routes** (only cash-drawer routes have it, per `routes/api.php:813, 817, 820, 824`). Duplicate POSTs could create duplicate parks. | `routes/api.php:801-806` ; `app/Http/Controllers/Admin/Pos/ParkedOrderController.php:33` | Network retry → 2 parked orders for the same operator session. | Add `->middleware('idempotency')` to parked-orders.store route OR enforce service-level dedupe on `idempotency_token`. |
| P2 | **`DiningTableReleaseAfterPosOrderTest` exists, but FloorplanController code has no E2E coverage for cross-table transfer race** — `floorplan.transfer` at line 55-66 could race two operators moving the same source table simultaneously. | `app/Http/Controllers/Admin/Pos/FloorplanController.php:55-66` ; existing test: `tests/Feature/Pos/FloorplanControllerTest.php` | Two POS terminals call `POST /floorplan/transfer` with same `source_table_id` at the same instant. | Sentinel `FloorplanTransferRaceTest` using `Cache::lock` simulation. |

---

## §6 — Visual capture specs (Playwright spec sketches)

| Surface URL | Spec path (proposed) | What to look for |
|---|---|---|
| `/admin/pos` | `tests/e2e/agent-1-pos/01-pos-wizard-shell.spec.js` | No raw `Label.X`, no `pos.foo`, console clean, all 4 wizard tiles visible (cash/card/split/ticket), CSRF token loaded |
| `/admin/pos` → simulate add Coca-Cola (item id 1) | `tests/e2e/agent-1-pos/02-pos-cash-overpay.spec.js` | CASH overpay flow: type tendered amount > total → change displayed, ticket modal visible, order created in DB |
| `/admin/pos` → split equal payment | `tests/e2e/agent-1-pos/03-pos-split-equal.spec.js` | 2 CASH tranches UI, terminal_id selector visible if CARD, sum line green, total locked |
| `/admin/pos` → split mixed (1 CASH + 1 CARD) | `tests/e2e/agent-1-pos/04-pos-split-mixed.spec.js` | Card tranche requires terminal_id; no phantom CARD persistence on cancel |
| `/admin/pos/cash-drawer/sessions/current` | `tests/e2e/agent-1-pos/05-cash-drawer-session.spec.js` | Open session flow, count notes, reconcile screen, deny CASH sale if no session in production simulation_hardware=false |
| `/admin/pos` → park + recall + pay | `tests/e2e/agent-1-pos/06-parked-recall.spec.js` | Park order, list shows it, recall restores cart, complete payment |
| `/admin/pos-orders/{id}` refund with counter-entry | `tests/e2e/agent-1-pos/07-refund-counter-entry.spec.js` | Refund modal, mirror order created with new fiscal_sequence_no, parent immutable |

All specs should `Read` the screenshot after capture and assert no raw label, no broken layout, no console error, no `aria-` missing on action buttons.

---

## §7 — Acceptance gate (existing tests that MUST PASS)

System 1 POS is **DONE** when ALL of the following pass:

| Test path | Status (current) | Notes |
|---|---|---|
| `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` | expected 5/5 | 4 scenarios + 1 anti-regression (uses HasPosQuoteBinding trait, `simulation_hardware=true`) |
| `tests/Feature/Pos/SplitPaymentEndToEndTest.php` | expected 6/6 | Per BRAIN baseline 2026-05-18 |
| `tests/Feature/Sentinels/SplitPaymentSentinelTest.php` | expected 3/3 | Per BRAIN baseline |
| `tests/Feature/Pos/PosCashTrailTest.php` | expected 6/6 | Sprint 1B NF525 cash trail |
| `tests/Feature/Pos/QuoteBindingTest.php` | expected PASS | Quote token signature binding |
| `tests/Feature/Pos/PosOrderRequestNoClientTotalsTest.php` | expected PASS | Client cannot inject totals |
| `tests/Feature/Pos/PosMenuRuntimeAccessTest.php` | expected PASS | Menu runtime perm gate |
| `tests/Feature/Pos/FritesWizardComposerTest.php` | expected 4/4 | Frites alignment 2026-05-18 |
| `tests/Feature/Pos/TerminalIdWireInTest.php` | expected PASS | Sprint H2 P1-Z7-01 |
| `tests/Feature/Pos/PosWalkInCustomerApiTest.php` | expected PASS | Walk-in resolver |
| `tests/Feature/Pos/DiningTableReleaseAfterPosOrderTest.php` | expected PASS | Table release post-order |
| `tests/Feature/Pos/FloorplanControllerTest.php` | expected PASS | (warn: see §5 P1 dine_in flag finding) |
| `tests/Feature/Pos/PosPurgeParkedScheduleTest.php` | expected PASS | Scheduled purge |
| **NEW (proposed Round 2)** | | |
| `tests/Feature/Pos/WizardProfileParityTest.php` | NEW | §2 P0 — every Item with non-simple template has aligned profile |
| `tests/Feature/Pos/CounterPaymentCancelReleasesStockTest.php` | NEW | §3 P1 — cancel dispatches RefundCreated |
| `tests/Feature/Stripe/StripeWebhookOrderMetadataTest.php` | NEW | §3 P0 — Stripe charge metadata round-trip |
| `tests/Feature/Fiscal/ZReportCloseChainContinuityTest.php` | NEW | §4 P1 — Z close chain continuity |
| `tests/Feature/Fiscal/AuditLogsTriggerPresentTest.php` | NEW | §4 P1 — MySQL trigger presence |

E2E gate: 7 Playwright specs from §6 all GREEN + screenshots analyzed (no raw labels, no broken layout) + adversarial RED visual cross-check.

---

## §8 — Cross-system flags (for Agent 10 RED)

These findings impact other systems — Agent 10 (cross-cutting RED) MUST verify upstream/downstream:

1. **KDS**: `PaymentService::cancelCounterPayment` dispatches `OrderStatusChanged($order, $oldStatus, CANCELED)` at `app/Services/PaymentService.php:445`. KDS Outbox listener `PersistOrderStatusChangedToOutbox` must be idempotent on already-canceled orders. Verify per `app/Listeners/PersistOrderStatusChangedToOutbox.php` (BRAIN Wave 5C wasRecentlyCreated guard). **Cross-check**: cancel-then-cancel does not double-dispatch.

2. **Stock**: counter-payment cancel does NOT dispatch `RefundCreated` (§3 P1) — `AvailabilityService` listener fires on `RefundCreated`, so stock counters will NOT release. Verify with Agent 5 (Stock).

3. **OSS Order Status Screen**: `OrderStatusChanged` cascades to OSS ticker per BRAIN `OrderStatusScreenOrderService::list` (Wave 5C deterministic order heal). Verify cancel-to-OSS reflects in <2s without ghost row.

4. **Livreur (Agent 6)**: `PosOrderController::selectDeliveryBoy` (line 177-184) lacks branch consistency check — Agent 6 should verify cross-branch delivery-boy assignment is blocked.

5. **Sync/Outbox**: `Stripe::handleWebhook` `markProcessed` (line 291) writes to `webhook_events` — verify Agent 5 SRE that Outbox pruning (Wave 5G) doesn't drop unprocessed rows.

6. **Mobile/Web standalone**: standalone, NO wireup per owner instruction. But if a future cycle wires mobile checkout to backend Stripe, the §3 P0 metadata gap will hit mobile too.

---

## Final summary

- **Anchor verification**: complete (NF525 chain matches BRAIN, all files cited via Read tool).
- **Findings count**: 5 P0 (1 wizard parity test + 2 Stripe + 1 PaymentService authz + 1 ParkedOrderController branch leak), 11 P1, 6 P2.
- **Frozen-zone-respecting**: 0 code change proposed for `pos-wizard.js`, `admin-pos-v4.blade.php`, or `app/Services/Fiscal/*`. Frozen findings = test-only attestation/sentinel.
- **simulation_hardware contract conflict** (§3 P1) — needs owner decision on cash_movement behavior under simulation.
- **NEW tests required**: 5 (1 wizard parity, 1 counter-cancel stock, 1 Stripe metadata, 1 Z-close chain, 1 trigger presence).
- **Visual specs**: 7 (defined §6).
- **Acceptance gate**: 13 existing + 5 NEW = 18 tests must pass for System 1 DONE.
- **Report length**: under 2000-word budget.
