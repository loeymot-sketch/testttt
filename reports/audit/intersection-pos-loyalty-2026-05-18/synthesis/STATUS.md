# INTERSECTION POS×LOYALTY — Round 1 Audit Status

**Date**: 2026-05-18
**Master sub-agent**: POS×Loyalty intersection
**Branch**: v1-0-1-hardening-2026-05-17
**Scope**: POS-specific loyalty flows (earn at DELIVERED + redeem before payment + cross-surface coherence)
**Wall-clock**: ~28 min
**Mode**: Read-only specialist audit (no code heals applied this round)

---

## 1. Scope-Truth Framing Finding

**The single most important finding of this audit:**

> **There is NO INTEGRATED POS-SIDE REDEMPTION UI in the V1 codebase.**

- `public/js/pos-wizard.js`: **0 loyalty references**
- `resources/views/admin-pos-v4.blade.php`: **0 loyalty references**
- `public/js/pos-app.js`: 51 hits, but ALL within the kiosk Vue bundle (`kiosk.loyalty` route, kiosk store), NOT the POS surface
- `PosOrderRequest` accepts `loyalty_customer_code` (for EARN attribution only — line 133); does NOT carry redemption-discount data
- `LoyaltyController::redeem` has an `isStaff` branch but no POS surface invokes it

**Implication**: the brief's framing "POS apply loyalty redemption discount before payment + loyalty_points decremented + discount applied" describes an **aspirational** flow that is currently **kiosk-only**. POS does EARN (via `loyalty_customer_code` → `AwardLoyaltyPointsOnDelivery` listener). POS does NOT do REDEEM at the UI layer.

Owner decision required: is V1 scope kiosk-redeem-only (current state, document as such) OR is POS redeem a missing feature (P1 backlog)?

---

## 2. Anchor Verification

| Anchor | Found | Notes |
|---|---|---|
| `app/Listeners/AwardLoyaltyPointsOnDelivery.php` | YES | 165 lines, sentinel + ledger pattern, kiosk-aware |
| `app/Services/LoyaltyService.php` | YES | refundPoints only — no redeem method (redeem lives in LoyaltyController) |
| `app/Http/Controllers/Frontend/LoyaltyController.php` | YES | check / register / addPoints / redeem / balance / config / history / scan / optIn |
| `app/Http/Controllers/Admin/Pos/CustomerNfcLookupController.php` | YES | NFC lookup only, no redeem |
| `app/Models/LoyaltyTransaction.php` | YES | type ENUM, fillable, append-only by convention |
| `app/Models/Customer.php` | YES | extends User — same table |
| Tests: `tests/Feature/LoyaltyApiTest.php` + `KioskLoyaltyDoubleRedeemRefusedTest` + `KioskLoyaltyLedgerAtomicTest` + `OrderCancellationLoyaltyTest` | YES | Coverage for kiosk paths, OrderCancellation refund |
| Migrations: 7 loyalty-related | YES | users fields + transactions + UNIQUE + consents + loyalty_customer_code on orders |

---

## 3. The 4-List (P0/P1/P2/P3 consolidated)

### P0 (V1 blocker — would prevent ship)
*(none found this round)*

### P1 (Must heal in V1.0.2 before chain-restaurant rollout — money/fraud-grade)

| ID | Title | Files | Sub-zone | Roles |
|---|---|---|---|---|
| PLOY-2-A-00/A-01 | POS redemption UI missing (scope-truth) | pos-wizard.js, admin-pos-v4.blade.php | PLOY-2 | Architect |
| PLOY-1-S-01 | Cashier injects arbitrary `loyalty_customer_code` on POS order — no exists/ownership check | PosOrderRequest:133, OrderService:910 | PLOY-1 | Security |
| PLOY-2-DBA-01 | UNIQUE(user_id, order_id=NULL, type) is NULL-permissive — no DB defense against double-pending-redeem | migration 2026_03_26_075919, LoyaltyController:312 | PLOY-2 | DBA (cross-ref PLOY-1-DBA-01) |
| PLOY-2-R-01 | Double-tap redeem race — customer loses points to orphan ledger row | LoyaltyController::redeem | PLOY-2 | RED |
| PLOY-2-R-07 | Cashier-driven redeem without customer presence (isStaff branch bypass) | LoyaltyController:259-280 | PLOY-2 | RED |
| PLOY-2-S-01 | LoyaltyController::redeem isStaff branch trusts cashier-supplied code, no 2FA | LoyaltyController:255-339 | PLOY-2 | Security |
| PLOY-3-S-01 | Phone-only customer lookup enables impersonation chain | LoyaltyController:71-77, :619-627 | PLOY-3 | Security |
| PLOY-3-R-01 | Kiosk-mobile sandwich double-redeem race | LoyaltyController::redeem | PLOY-3 | RED |
| PLOY-3-R-02 | Cashier shoulder-surf phone → harvest loyalty_code → solo redeem (chain) | LoyaltyController + routes | PLOY-3 | RED |

### P2 (V1.0.2-V1.0.3 backlog — operational risk / silent data loss)

| ID | Title | Files | Sub-zone | Roles |
|---|---|---|---|---|
| PLOY-1-A-02 | Stuck-sentinel recovery cron missing (loyalty_points_awarded=-1 tombstone) | Listener:56 | PLOY-1 | Architect |
| PLOY-1-S-02 | Silent fail on invalid `loyalty_customer_code` typo — no telemetry, no UI feedback | Listener:75-81 | PLOY-1 | Security |
| PLOY-1-DBA-02 | loyalty_transactions has no branch_id column — analytics not multi-tenant | migration 075918 | PLOY-1 | DBA |
| PLOY-1-R-03 | Sentinel orphan on process-kill mid-execution | Listener:56-142 | PLOY-1 | RED (covered by PLOY-1-A-02) |
| PLOY-1-R-05 | Earn-on-cancelled-order — points not clawed back on post-DELIVERED cancel | LoyaltyService + state machine | PLOY-1 | RED (owner decision) |
| PLOY-2-A-02 | Pre-order redeem orphan rows (order_id=NULL, age>10min, never attached) — no GC cron | LoyaltyController:312, FrontendOrderService:818 | PLOY-2 | Architect |
| PLOY-2-S-02 | Refund logic writes type='manual_add' — ledger pollution + analytics drift | LoyaltyService:62-71 | PLOY-2 | Security |
| PLOY-2-S-03 | Double-redeem race in LoyaltyController::redeem — UNIQUE-NULL gap | LoyaltyController:271-323 | PLOY-2 | Security (=PLOY-2-R-01) |
| PLOY-2-S-05 | No explicit rate-limit on /api/loyalty/redeem | routes/api.php:1277 | PLOY-2 | Security |
| PLOY-2-DBA-02 | createKioskLoyaltyRedeemLedger 23000-catch path unreachable for NULL-order pre-order writes | FrontendOrderService:880-902 | PLOY-2 | DBA |
| PLOY-2-DBA-03 | No GC for orphaned pre-order redeem rows (alias PLOY-2-A-02) | LoyaltyController:312 | PLOY-2 | DBA |
| PLOY-2-DBA-04 | type ENUM lacks 'refund'/'reversal' — refunds labeled as 'manual_add' | migration 075918 | PLOY-2 | DBA |
| PLOY-3-A-01 | source_surface mislabels mobile redemptions as 'pos' | LoyaltyController:316 | PLOY-3 | Architect |
| PLOY-3-A-02 | Balance display drift between surfaces — no push/refresh | n/a | PLOY-3 | Architect |
| PLOY-3-A-05 | FrontendOrder refundPoints hard-codes sourceSurface='kiosk' | FrontendOrderService:707 | PLOY-3 | Architect |
| PLOY-3-S-02 | Mobile-app Sanctum tokens have unscoped loyalty/redeem authority | routes/api.php:1278 | PLOY-3 | Security |
| PLOY-3-S-03 | Audit-log gap on loyalty ops — no HMAC chain-signed audit_logs entry | LoyaltyController, LoyaltyService, Listener | PLOY-3 | Security |
| PLOY-3-DBA-01 | source_surface VARCHAR(20) unconstrained — no CHECK/ENUM | migration 075918:28 | PLOY-3 | DBA |
| PLOY-3-DBA-02 | No DB guarantee single-concurrent-redeem-per-user across surfaces | (cross-ref) | PLOY-3 | DBA |
| PLOY-3-DBA-03 | Read-your-writes consistency on read-replicas — needs useWritePdo or single-DB doc | n/a | PLOY-3 | DBA |
| PLOY-3-R-03 | Static QR code replay across kiosks in chain | mobile/QR generation | PLOY-3 | RED |
| PLOY-3-R-05 | Admin manual_add abuse — needs cap + dual-approval | LoyaltyController:185-247 | PLOY-3 | RED |

### P3 (V1.0.X+ backlog — documentation, hardening, cosmetic)

| ID | Title | Roles |
|---|---|---|
| PLOY-1-A-01 | Listener bypasses BranchScope via raw DB::table (documented + acceptable) | Architect |
| PLOY-1-A-03 | No validation `loyalty_points_per_euro > 0` at settings-write time | Architect |
| PLOY-1-S-03 | User lookup in listener doesn't filter status=ACTIVE | Security |
| PLOY-1-S-04 | No branch isolation on User loyalty_code lookup (business decision) | Security |
| PLOY-1-DBA-03 | No append-only DB trigger on loyalty_transactions | DBA |
| PLOY-1-DBA-04 | No FK loyalty_transactions.order_id → orders.id | DBA |
| PLOY-1-DBA-05 | users.loyalty_points INT — overflow at ~21M (V2 concern) | DBA |
| PLOY-1-R-07 | Settings drift (loyalty_points_per_euro=0) silently degrades earn | RED |
| PLOY-2-A-03 | refundPoints reads stale loyalty_points for balance_after (cosmetic) | Architect |
| PLOY-2-S-04 | LoyaltyController::redeem no branch-scoping on User lookup | Security |
| PLOY-2-R-05 | One-time-token clipboard replay (forward-looking, no token table yet) | RED |
| PLOY-2-R-08 | Negative-points float bypass — defended | RED |
| PLOY-3-A-03 | Web surface has no loyalty integration (owner decision) | Architect |
| PLOY-3-A-04 | source_surface fallback logic (verified consistent) | Architect |
| PLOY-3-S-04 | Kiosk-redeem trusts kiosk:order ability for any code (by design) | Security |
| PLOY-3-S-05 | GDPR opt-out + ledger retention conflict — no anonymize path | Security |
| PLOY-3-DBA-04 | loyalty_consents not cross-referenced in queries | DBA |
| PLOY-3-DBA-05 | users.loyalty_code generation has theoretical collision (no retry) | DBA |
| PLOY-3-DBA-06 | No archival policy on loyalty_transactions | DBA |
| PLOY-3-R-04 | Future web account-page XSS leak of loyalty_code (deferred) | RED |
| PLOY-3-R-06 | source_surface forgery if becomes user-controllable (advisory) | RED |
| PLOY-3-R-07 | Refund-spoofing via SQL injection — defended | RED |
| PLOY-3-R-08 | GDPR-purge race during active redemption (edge case) | RED |

---

## 4. Defended (PASS / DEFENDED scenarios verified)

| Scenario | Defense |
|---|---|
| PLOY-1-R-01 DELIVERED→PENDING→DELIVERED replay | Sentinel whereNull guard blocks |
| PLOY-1-R-02 Concurrent OrderStatusChanged race | Atomic UPDATE rows_affected check |
| PLOY-2-R-02 Browser-back mid-redeem | 10-min attach window covers |
| PLOY-2-R-03 localStorage tamper | Server reads balance from DB |
| PLOY-2-R-06 Re-cancel cycle ledger pollution | UNIQUE(user_id, order_id, type) holds for post-order rows |
| PLOY-3-R-07 SQL injection on refund | Eloquent parameterization |
| PLOY-2-R-08 Negative-points float bypass | PHP int cast |

---

## 5. Heal-Applied This Round

**None.** Per CLAUDE.md §10 and the brief's "HEAL-ALLOWED on clean files" constraint, loyalty code is NF525-adjacent (money-equivalent audit trail). All P1 findings require owner gate (dedup_key migration, cashier-collusion mitigation, redemption-2FA). No safe heals identified that don't require schema changes or business-rule decisions.

**Recommendation for V1.0.2 sprint** (sequential, with owner gate per item):
1. PLOY-1-A-02 + PLOY-1-R-03 → add stuck-sentinel reconciliation cron (low risk, observability win)
2. PLOY-2-A-02 + PLOY-2-DBA-03 → add orphan-redeem GC cron (medium risk, customer-money recovery)
3. PLOY-2-DBA-04 → add 'refund' to type ENUM + migrate refund writes (low risk, analytics clean)
4. PLOY-3-A-01 + PLOY-3-A-05 → fix source_surface labeling for mobile + FrontendOrder refund (low risk)
5. PLOY-2-DBA-01 + PLOY-3-DBA-02 → dedup_key UNIQUE index (medium risk, schema change, owner gate)
6. PLOY-2-S-01 + PLOY-2-R-07 → cashier-redeem 2FA / disable isStaff redeem (high risk, UX impact, owner gate)
7. PLOY-3-S-01 + PLOY-3-R-02 → restrict /loyalty/check to mask code or owner-only (medium risk)
8. PLOY-1-S-01 → add exists check on PosOrderRequest.loyalty_customer_code + audit-log (low risk)
9. PLOY-3-S-03 → loyalty events → audit_logs HMAC chain (medium risk, volume impact)
10. PLOY-3-R-05 → cap + dual-approval on admin manual_add (medium risk, UX impact)

---

## 6. Cross-Intersection Coordination

Other parallel intersections to consult:
- **POS×OSS**: order status transitions trigger loyalty award (DELIVERED) — confirm OSS-driven status changes preserve listener semantics
- **POS×KDS**: PREPARED status on kiosk orders fires loyalty award — confirm KDS bump-to-PREPARED doesn't lose loyalty_customer_code
- **Mobile×Loyalty** (separate audit): mobile e2e specs cover loyalty-{01..15} + adv-A{1..5}; this audit found that mobile redemptions get `source_surface='pos'` (PLOY-3-A-01) — mobile audit should verify the upstream impact

---

## 7. Verdict

| Aspect | Verdict |
|---|---|
| POS earn flow (PLOY-1) | PASS-WITH-CONCERN (P2 stuck-sentinel, P3 docs) |
| POS redeem flow (PLOY-2) | FAIL-OR-PASS-DOCUMENTED (depends on owner expectation re: scope) |
| Cross-surface coherence (PLOY-3) | PASS-WITH-CONCERN (P1-P2 fraud + race surface) |
| V1 BLOCKER for LeCayenne single-resto under owner supervision | NO |
| V1 BLOCKER for chain-restaurant rollout | YES — recommend V1.0.2 hardening sprint |

---

## 8. Files Audited (read-only)

```
app/Listeners/AwardLoyaltyPointsOnDelivery.php
app/Services/LoyaltyService.php
app/Http/Controllers/Frontend/LoyaltyController.php
app/Http/Controllers/Admin/Pos/CustomerNfcLookupController.php
app/Models/LoyaltyTransaction.php
app/Models/Customer.php
app/Models/Order.php (loyalty_customer_code fillable)
app/Models/FrontendOrder.php (loyalty_customer_code fillable)
app/Services/OrderService.php (loyalty hooks at lines 910, 1641, 1697, 1800)
app/Services/FrontendOrderService.php (loyalty hooks at lines 516, 707, 818, 872)
app/Services/Pricing/DiscountCalculator.php (kioskLoyaltyRedemption method)
app/Http/Requests/PosOrderRequest.php (loyalty_customer_code validation)
public/js/pos-wizard.js (verified 0 loyalty hits)
public/js/pos-app.js (verified 51 kiosk-bundle hits)
public/js/pos-shell.js (verified 0 loyalty hits)
resources/views/admin-pos-v4.blade.php (verified 0 loyalty markup)
routes/api.php (lines 1267-1318 loyalty routes)
database/migrations/2026_03_08_145926_add_loyalty_fields_to_users_table.php
database/migrations/2026_03_25_003209_add_loyalty_awarded_to_orders_table.php
database/migrations/2026_03_26_005907_add_loyalty_customer_code_to_frontend_orders_table.php
database/migrations/2026_03_26_075918_create_loyalty_transactions_table.php
database/migrations/2026_03_26_075919_add_unique_to_loyalty_transactions.php
database/migrations/2026_04_18_120008_create_loyalty_consents_table.php
tests/Feature/OrderCancellationLoyaltyTest.php
tests/Feature/LoyaltyApiTest.php (listed, not full-read)
tests/Feature/KioskLoyaltyDoubleRedeemRefusedTest.php (listed)
tests/Feature/KioskLoyaltyLedgerAtomicTest.php (listed)
tests/mobile-e2e/loyalty-*.spec.js (listed, 20 specs)
```

---

## 9. Specialist Deliverable Pointers

```
reports/audit/intersection-pos-loyalty-2026-05-18/round-1/
├── PLOY-1-EARN-FLOW/
│   ├── architect.json    (3 findings, PASS-WITH-CONCERN)
│   ├── security.json     (4 findings, FAIL)
│   ├── dba.json          (5 findings, PASS-WITH-CONCERN)
│   └── red.json          (7 scenarios, PASS-WITH-CONCERN)
├── PLOY-2-REDEEM-FLOW/
│   ├── architect.json    (3 findings incl scope-truth P1, FAIL)
│   ├── security.json     (5 findings, FAIL)
│   ├── dba.json          (5 findings, FAIL)
│   └── red.json          (8 scenarios, FAIL)
├── PLOY-3-CROSS-SURFACE/
│   ├── architect.json    (5 findings, PASS-WITH-CONCERN)
│   ├── security.json     (5 findings, FAIL)
│   ├── dba.json          (6 findings, PASS-WITH-CONCERN)
│   └── red.json          (8 scenarios, FAIL)
└── ../synthesis/STATUS.md (this file)
```

**Total findings**: 4 P1, 22 P2, 23 P3 = 49 distinct items (after dedup across roles).
