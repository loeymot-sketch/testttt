# POS Parallel Audit — VERDICT — 2026-05-11

> **HEAD** : `a220b9bd8` — branche `feature/mobile-app-le-cayenne-2026-05-10`.
> **Méthode** : 20 sub-agents adversariaux read-only en parallèle, scope-strict, persist disk.
> **Plan** : `plans/ULTRA_PLAN_POS_PARALLEL_2026-05-11.md`.
> **Audit antérieur référence** : `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md` + `99_CORRIGENDUM.md`.

---

## VERDICT GLOBAL : ⛔ **NO-GO V1 maintenu** — état mixte

> **13/20 rapports livrés** (A01-A11, A13, A15). **7/20 rate-limited** (A12, A14, A16, A17, A18, A19, A20) avant écriture disque — reset 11:20am, relance après reset pour compléter la couverture.
> Sur les 13 livrés : **plusieurs P0 historiques CLOSED**, **plusieurs NOUVEAUX P0 surfacés**, BRAIN drift documenté.

### Score consolidé (13 agents livrés)

| Status | Count | Notes |
|--------|-------|-------|
| **P0 historiques CLOSED** post iter15/cluster | **7** | P0-01/02, P0-05, P0-07, P0-08, P0-09, P0-11, P0-12, P0-14 |
| **P0 historiques OPEN** confirmés fresh | **4** | P0-03 (partial), P0-04, P0-06, P0-13 (partial) |
| **P0 NOUVEAUX surfacés** par cet audit | **8** | A05×2, A09×3, A10×3 |
| **P1 total** | ~30+ | distribués sur 13 agents |
| **P2 total** | ~25+ | hardening, cosmetic structural |
| **Coverage gap** | 7 agents | A12 Refund / A14 RBAC / A16 Vanilla wizard / A17 Vue surface / A18 Discount / A19 Parked-Print / A20 Sync-Tests |

---

## 1. P0 OUVERTS — Cross-validated (action immédiate)

### P0 fiscal & data integrity (3)

| # | File:LN | Finding | Cross-val | Status historique |
|---|---------|---------|-----------|-------------------|
| **P0-A07-1 / P0-A09-1** | `database/migrations/2026_05_06_180000_create_order_payments_table.php:32` + `2026_05_08_140100_create_cash_movements_table.php:47-50` | `cascadeOnDelete()` sur 2 tables fiscal-bearing — DELETE parent silently wipes audit trail. NF525 6-year retention break. Pas de trigger BEFORE DELETE sur ces tables. | **A07 + A09** (cross-validated) | Confirme **P0-04** 2026-05-09 |
| **P0-A07-2** | `phpunit.xml` SQLite-only runner + missing CI MySQL matrix | `z_reports` DELETE trigger MySQL-only, test `ZReportDeleteTriggerMysqlOnlyTest` existe depuis 2026-05-10 mais CI matrix proof TODO (skip silent sur SQLite). | A07 single | Partially closes **P0-03** 2026-05-09 |
| **P0-A09-3** | `app/Services/Cash/CashDrawerService.php:101-133` `closeSession` | `closing_amount` caller-declared, no validation against `Σ(signed_movements) + opening`. Cashier peut déclarer 50€ et empocher 100€ — variance enregistrée mais aucune gate manager. | A09 single | NEW (pas dans 2026-05-09) |

### P0 multi-tenant & auth (1)

| # | File:LN | Finding | Cross-val | Status historique |
|---|---------|---------|-----------|-------------------|
| **P0-A13-1** | `app/Http/Controllers/Admin/PosOrderController.php:108` | `Order::withoutGlobalScope(BranchScope::class)->findOrFail()` — n'importe quel opérateur `permission:pos\|pos-orders` peut lire les ordres d'autres branches via `GET /api/v1/admin/pos-order/show/{id}`. `OrderDetailsResource` expose composition_snapshot + fiscal + customer phone. | A13 single (corrigendum 2026-05-09 spot-check était wrong — searched wrong dir) | **CONFIRMS P0-06** 2026-05-09 (INVESTIGATE → CONFIRMED) |

### P0 cash & payment (4 NEW)

| # | File:LN | Finding | Cross-val | Status historique |
|---|---------|---------|-----------|-------------------|
| **P0-A09-2** | `app/Services/PaymentService.php:243-281` + `:287-324` | Cash POS payment + cashback hooks **best-effort** : si pas de OPEN session, log INFO et continue. Order persisté PAID sans `cash_movements` row → Z variance silently diverges from physical cash. | A09 single (escalates P1-06) | NEW escalation |
| **P0-A10-1** | `app/Services/OrderService.php:1954-1962` `collectKioskCash` | Hard-code `received = (float) $order->total` — cashier ne saisit JAMAIS le montant réel encaissé pour kiosk-cash. NF525 reconciliation impossible. F-003 Option-A invariant violé. | A10 single | NEW |
| **P0-A10-2** | `app/Services/PaymentService.php:130-237` `confirmCounterPayment` | Ne persiste jamais `change_amount` — colonne existe mais aucun code n'y écrit. Z-report ne peut rapporter total returned change. | A10 single | NEW |
| **P0-A10-3** | `app/Services/OrderService.php:888-895` `posOrderStore` cash branch | Aucun row `order_payments` créé en mode V1 single-tender (default `split_payment.enabled=false`). Table `order_payments` vide pour les ventes cash directes → Z aggregation per-tender retourne 0 rows. | A10 single | NEW |

### P0 order state machine (2 NEW)

| # | File:LN | Finding | Cross-val | Status historique |
|---|---------|---------|-----------|-------------------|
| **P0-A05-1** | `app/Services/OrderService.php:1608-1722` `changeStatus` non-auth path | Mutate `$order->status` + `save()` sans `lockForUpdate`. Deux requêtes concurrentes peuvent double-cancel, double-cashBack, double-refundPoints, double-AuditLog. | A05 single | NEW (apply() fix iter15 ne couvre pas legacy callers) |
| **P0-A05-2** | `app/Services/OrderService.php:1817-1909` `changePaymentStatus` non-auth path | Read `payment_status` AVANT transaction, no locked re-read. UNPAID→PAID concurrent → 2 ActionLog + 2 fiscal AuditLog. PAID terminal contract violated. | A05 single | NEW (apply() fix iter15 ne couvre pas legacy callers) |

### P0 test integrity (1 partial)

| # | File:LN | Finding | Cross-val | Status historique |
|---|---------|---------|-----------|-------------------|
| **P0-A10-13 / P0-A11-13** | `tests/e2e/02-pos-cash.spec.js:118-127` + `05-pos-card.spec.js:99-107` | Réécrits "P0-13 adversarial-grade iter15" MAIS still `test.fixme(true, ...)` escape hatch + OR-coupled `hasTicket \|\| hasEmptyCart` final assertion (empty cart starting state passes too). Real Playwright coverage = 0 sur CI default state. | A10 + A11 | **Partial close P0-13** 2026-05-09 |

---

## 2. P0 HISTORIQUES CLOSED (à retirer du backlog)

| Past ID | Closing evidence | Audited by |
|---------|------------------|------------|
| **P0-01/02** ZReport aggregate SoftDeletes scope | `ZReportService.php:337-341` uses `withTrashed()` ; test pin `ZReportAggregateFilterTest.php:101-141` | A08 |
| **P0-05** RETRACTED (config/idempotency fabricated) | `config/idempotency.php` IS real (past audit was wrong); middleware wired `routes/api.php:728` + Kernel.php:98 + `.env:92` enabled. P0-05 was a **double-retraction** — original P0 claim was hallucinated, but retraction itself missed that the file does exist. | A04 |
| **P0-07** `RefreshTokenController` `['*']` ability | Regression test `RefreshTokenAbilityPreserveTest.php` pin token-ability preservation | A01 |
| **P0-08** missing route abilities `payment-confirm` | Downgraded to P1 defense-in-depth (FormRequest gate fires before lock, sentinel `PaymentConfirmAbilitySentinelTest.php:45-54` confirms 403 on `['*']`-token non-kiosk) | A01 + A11 |
| **P0-09** CashDrawer concurrent open | Triple-defense Cache::lock + lockForUpdate + UNIQUE partial index across SQLite/PgSQL/MySQL ; 4 regression tests pin | A09 |
| **P0-11** SenangPay 500 (route → class missing) | `Senangpay.php:31-46` returns 501 stub ; `SenangPayStubResponseTest.php:38` pins. (NB: WebhookEvent model still orphan — reclassed P1-A15-01) | A15 |
| **P0-12** `OrderStateMachine::apply` race | Line :208-:253 lock-correct since iter15 ; 4 regression tests pin. BUT legacy callers in OrderService still race (cf. P0-A05-1/2 new findings) | A05 |
| **P0-14** Sentinel parity self-comparing fixtures | `tests/js/posKioskVariationParity.spec.js:36-38` imports REAL helpers, asserts cross-path equality across 7 scenarios incl. paid viande extras | A03 |

---

## 3. P1 consolidés (top 20)

| # | File:LN | Finding | Source |
|---|---------|---------|--------|
| **P1-A01-1** | `app/Http/Controllers/Auth/ForgotPasswordController.php:158-170` | Password reset auto-mints `['*']` token — privilege escalation if reset_token leaks | A01 |
| **P1-A02-1..5** | 5 layering violations | `PosReceiptPrintController` missing `permission:pos` middleware, `PosController::quote` no authz, `reorderItems` 75-line embedded snapshot deserializer, `PosCategoryController::index` 110-line god method, `reorderItems` missing branch guard | A02 |
| **P1-A03-1** | `public/js/pos-wizard.js` (FROZEN) | Does NOT emit `role=menu_*` on menu addons → `PricingService::menuRoleAdjustedAddonPrice` returns full 3.00 € → POS-path menu formulas silently overcharge 1.20-1.80 € per order (mirror E-001 fixed only kiosk side). **Owner gate + LOCK required.** | A03 |
| **P1-A04-1..4** | Multiple | No `IDEMPOTENCY_MIDDLEWARE_ENABLED` in `.env.example` ; PosOrderRequest::authorize() returns true unconditionally ; tests use synthetic __test/ routes not real /api/admin/pos ; no name-route binding for required_routes matcher | A04 |
| **P1-A05-4** | `app/Services/FrontendOrderService.php:1066-1186` | `recordTransition` called OUTSIDE transaction on `finalizePaidKioskOrder` — if worker dies between commit and audit, status changes WITHOUT audit row. Asymmetric vs `apply()` | A05 |
| **P1-A05-5** | `app/Services/OrderService.php:1485-1502` | `deliveryBoyOrderChangeStatus` flips UNPAID→PAID skipping `PaymentStateMachine::assertCanTransition` + no fiscal AuditLog. NF525 audit gap on cash-on-delivery | A05 |
| **P1-A05-6** | `app/Domain/Order/PaymentStateMachine.php:9-19` | Matrix gaps : no PENDING_COUNTER→UNPAID rollback, no UNPAID→FAILED, REFUNDED terminal (partial refund + recharge impossible) | A05 |
| **P1-A06-1** | `app/Services/PaymentService.php:178-180` + `app/Services/OrderService.php:922-923` | POS close + POS new-order call `FiscalSequenceService::next()` BARE — no try/catch, no flag — Cache::lock failure rolls back transaction without recovery marker. Asymmetric vs kiosk path which has `fiscal_alloc_error_at` retry | A06 |
| **P1-A07-4** | `app/Services/Fiscal/FiscalChainValidator.php:149` | 500-row tail validator EXEMPTS first row of window from chain-break check → forge possible by inserting row at boundary | A07 |
| **P1-A08-1..3** | Multiple | Orphan warn missing `withTrashed`, GATE-FZH-ALLOC pre-Z-close still warn-only (not throw), `z_reports` UPDATE intentionally permitted via `saveQuietly()` could flip `total_ttc` | A08 |
| **P1-A09-1..5** | 5 cash drawer hardening | `opening_amount` decimal:2 not BCMath, hardware drawer-pop unaudited (no `CashMovement::TYPE_DRAWER_OPEN` emission ever), no `closed_by_user_id`, no alerting on best-effort recordMovement failures, orphan-session recovery missing | A09 |
| **P1-A10-4** | `routes/api.php:789-799` `collect-kiosk-cash` | No `idempotency` middleware (throttle:pos-order-update only). Internal lock + early-return masks it but architecturally inconsistent vs POS direct store route | A10 |
| **P1-A11-A** | `routes/api.php:1113-1121` | Route group declares only `auth:sanctum` (no `abilities:kiosk:order`) — FormRequest-based gate is documented design but creates tooling-drift / sentinel risk | A11 |
| **P1-A11-B** | `app/Http/Requests/Frontend/PaymentConfirmRequest.php:14-25` | TransientToken / session-auth bypass — `TransientToken::can()` always returns true → session-authenticated user bypasses kiosk:order ability check. Mirror missing of `OrderRequest:247-250` rejection pattern | A11 |
| **P1-A11-C** | `app/Http/Controllers/Frontend/OrderController.php:266-282` | `\Throwable` swallow on `finalizePaidKioskOrder` masks fiscal failures (200 + log only, depend on `foodking:fiscal:retry-alloc` cron being wired) | A11 |
| **P1-A13-1..4** | 4 models | `OrderStatusTransition` / `PosParkedOrder` / `OrderQuote` / `OrderCoupon` lack `addGlobalScope(BranchScope)` — 3 mitigated by service-layer filters (fragile), 1 (`OrderCoupon`) lacks `branch_id` column | A13 |
| **P1-A15-1** | `app/Models/WebhookEvent.php:18-46` | Production-orphan : 0 callers in `app/` write to `webhook_events` table. Only test files. BRAIN §7 row 5 "unifié ✅" misleading | A15 |
| **P1-A15-2** | No Stripe webhook handler | `Stripe.php` only redirect-flow (no `webhook()` method, no HMAC verify) — relies on legacy `capture_payment_notifications` table. Blocker for Stripe activation | A15 |

---

## 4. P2 / P3 — voir rapports individuels A01..A15

Récapitulatif : ~25 P2 + ~12 P3 (hardening, cosmétique structurel, doc gaps).

---

## 5. Comparison vs audit 2026-05-09 (13 known P0)

| # 2026-05-09 | Title | Status today (2026-05-11) | Evidence (this audit) |
|--------------|-------|---------------------------|----------------------|
| P0-01/02 | ZReport aggregate SoftDeletes scope | ✅ **CLOSED** | A08 — `withTrashed()` wired |
| P0-03 | z_reports DELETE trigger 0 test cov | ⚠️ **PARTIAL** | A07 — test exists 2026-05-10 mais CI MySQL matrix TODO |
| P0-04 | cascadeOnDelete cash_movements + order_payments | ❌ **STILL OPEN** | A07 + A09 cross-validated |
| P0-05 | RETRACTED (idempotency config) | ✅ **CLOSED** (re-confirmed) | A04 — config + middleware wired |
| P0-06 | PosOrderController withoutGlobalScope INVESTIGATE | ❌ **CONFIRMED FRESH** | A13 — line :108 verbatim |
| P0-07 | RefreshTokenController ['*'] | ✅ **CLOSED** | A01 — regression test pin |
| P0-08 | missing route abilities payment-confirm | ✅ **DOWNGRADED P1** | A01 + A11 — FormRequest gate fires |
| P0-09 | CashDrawerService no lock | ✅ **CLOSED** | A09 — triple-defense pinned |
| P0-10 | Refund counter-entry mirror gap | ⏸️ **PENDING** (A12 rate-limited) | A12 non-livré — verifier reset |
| P0-11 | WebhookEvent dead / SenangPay 500 | ✅ **PARTIAL CLOSED** | A15 — 501 stub OK, model orphan reclassed P1 |
| P0-12 | OrderStateMachine apply race | ✅ **CLOSED** for apply(), BUT 2 NEW P0 on legacy callers | A05 |
| P0-13 | 4 fake E2E POS specs | ⚠️ **PARTIAL** | A10 + A11 — rewritten but escape hatch `test.fixme(true)` remains |
| P0-14 | sentinel parity self-compare | ✅ **CLOSED** | A03 — real helpers asserted |

---

## 6. BRAIN.md drift table — post audit 2026-05-11

| BRAIN claim | Reality 2026-05-11 | Severity |
|-------------|---------------------|----------|
| §7 row 1 "Architecture event-driven ✅" | WebhookEvent production-orphan (P1-A15-1) | MEDIUM |
| §7 row 2 "BranchScope 11 models ✅" | 4 POS-surface still missing (P1-A13-1..4), PosOrderController:108 leak (P0-A13-1) | **HIGH** |
| §7 row 4 "Fiscal hash chain + DELETE triggers ✅" | Chain validator first-row anchor bug (P1-A07-4), CI MySQL trigger proof TODO (P0-A07-2) | MEDIUM |
| §7 row 5 "Idempotency dual-layer + webhook unifié ✅" | Idempotency ✅ (A04 confirms), webhook unification dead code (P1-A15-1) | MEDIUM |
| §7 row 6 "Order state machine + lockForUpdate ✅" | apply() ✅ but legacy callers still race (P0-A05-1/2) | **HIGH** |
| §7 row 7 "Sanctum kiosk:order strict ✅" | ✅ for now (P0-07/08 closed), but TransientToken bypass latent (P1-A11-B) | LOW |
| §7 row 10 "Cash audit F-003 chain-signed ✅" | cascadeOnDelete (P0-A09-1), silent cash-no-session (P0-A09-2), no variance gate (P0-A09-3), kiosk-cash received hard-coded (P0-A10-1), change_amount not persisted (P0-A10-2), order_payments empty in V1 (P0-A10-3) | **CRITICAL** |
| §7 row 16 "Fiscal orphan retry GATE-FZH-ALLOC ✅" | GATE still warn-only (P1-A08-2), POS path bare `next()` (P1-A06-1) | MEDIUM |

**Domains genuinely production-ready post-audit** : ~6-7 / 16 (further decline from 7-8 in 2026-05-09 estimate, accounting for newly-surfaced P0 cash issues).

---

## 7. Remediation roadmap

### Hard pre-merge V1 (8 NEW + 4 OPEN historiques = 12 P0)

**Fiscal & data integrity (3)** :
- [ ] P0-A07-1/A09-1 — Migrate `cash_movements.cash_drawer_session_id` + `order_payments.order_id` `cascadeOnDelete` → `restrictOnDelete`. Migration + test.
- [ ] P0-A07-2 — CI MySQL matrix variant for `z_reports` DELETE trigger.
- [ ] P0-A09-3 — Manager-permission gate + `variance_reason` requirement on `closeSession` when `|variance| > config('pos.cash.max_variance_eur', 5)`.

**Multi-tenant (1)** :
- [ ] P0-A13-1 — Drop `withoutGlobalScope(BranchScope::class)` on `PosOrderController::show:108`. Add Feature regression test.

**Cash & payment (4)** :
- [ ] P0-A09-2 — Either (a) block cash POS payment without OPEN session (422) OR (b) emit `cash_movements` row with `cash_drawer_session_id=NULL` flagged orphan + surface on Z.
- [ ] P0-A10-1 — `collectKioskCash` must accept `received` param from cashier, not hard-code `$order->total`.
- [ ] P0-A10-2 — Persist `change_amount` on `confirmCounterPayment` (compute `tendered - total`).
- [ ] P0-A10-3 — `posOrderStore` cash branch must INSERT `order_payments` row in single-tender mode (independent of `split_payment.enabled` flag).

**Order state machine (2)** :
- [ ] P0-A05-1 — Migrate `OrderService::changeStatus` non-auth branch to `OrderStateMachine::apply()` OR add `lockForUpdate` wrapping.
- [ ] P0-A05-2 — Same fix for `changePaymentStatus` non-auth branch.

**Test integrity (1)** :
- [ ] P0-A10-13/A11-13 — Rewrite `02-pos-cash.spec.js` + `05-pos-card.spec.js` removing `test.fixme(true)` escape hatch ; replace OR-coupled assertions with explicit ticket-printed assertions ; seed cart in `beforeEach`.

### Pre-merge V1 verification (4 P0 historiques)

- [ ] P0-13 confirm full rewrite + escape hatch removal across the 4 specs (`02-pos-cash`, `05-pos-card`, plus the 2 not re-verified yet — pending A20).

### V1.0.1 hardening (~30+ P1 — see detailed agent reports)

Top priorities :
- POS wizard menu_role addon overcharge (P1-A03-1) — **owner gate + LOCK on frozen pos-wizard.js**
- ForgotPassword `['*']` token (P1-A01-1)
- TransientToken session-auth bypass (P1-A11-B)
- PosReceiptPrintController missing permission (P1-A02-1)
- 4 BranchScope additions on POS-surface models (P1-A13-1..4)
- Cash drawer hardening : BCMath, hardware-pop audit, closed_by, alerting (P1-A09-1..5)
- Audit row outside transaction `finalizePaidKioskOrder` (P1-A05-4)
- FiscalChainValidator first-row anchor (P1-A07-4)

### V1.x backlog

- Stripe webhook handler (P1-A15-2)
- WebhookEvent unified-ledger implementation (P1-A15-1)
- PaymentStateMachine matrix gaps (P1-A05-6)

---

## 8. ⚠️ Coverage gap — 7 agents rate-limited (35% scope incomplete)

| Agent | Role | Status | Re-spawn after reset 11:20am |
|-------|------|--------|------------------------------|
| **A12** | Refund + Counter-Entry | Rate-limited before write | YES — verify P0-10 mirror gap fresh |
| **A14** | RBAC + FormRequest Authz | Rate-limited before write | YES — verify FormRequest authz 88 endpoints |
| **A16** | POS Vanilla Wizard FROZEN | Rate-limited before write | YES — frozen-zone diff numbers + P0-15 BRAIN drift |
| **A17** | POS Admin Vue Surface | Rate-limited before write | YES — raw labels, V1 dine-in flag, i18n FR |
| **A18** | POS Discount + Coupon + Loyalty | Rate-limited before write | YES — manual discount audit + forgery |
| **A19** | POS Parked + Walk-in + Print | Rate-limited before write | YES — PosParkedOrder BranchScope + ESC/POS + NFC |
| **A20** | POS↔KDS/OSS Sync + Tests Coverage | Rate-limited before write | YES — 4 fake E2E specs full re-verification |

**Note critique** : la liste **P0 OUVERTS = 12** ne couvre QUE les 13 agents livrés. Les 7 agents non-livrés peuvent surfacer 3-8 P0 supplémentaires (refund mirror split, fake E2E rest, parked order leak, etc.). **Verdict NO-GO V1 robuste mais incomplete.**

---

## 9. Sign-off

- **Audit run by** : Claude Code orchestrator, 20 parallel sub-agents launched 2026-05-11 ~10:51, 13 livrés ~10:55-10:57, 7 rate-limited.
- **Total findings (13 agents)** : ~12 P0 ouverts (8 nouveaux + 4 historiques OPEN), ~30+ P1, ~25+ P2, ~12 P3 + 7 gaps non-couverts.
- **Cross-validation** : 2 P0 cross-validated multi-agents (P0-A07-1/A09-1 cascadeOnDelete = A07 + A09).
- **Verdict** : ⛔ **NO-GO V1** maintenu. Remediation estimée : ~5-7j-agent P0 + ~3-4j P1 = sprint V1.0.1 élargi 8-11j-agent **conditional sur close A12/A14/A16/A17/A18/A19/A20 après reset**.
- **Recommandation immédiate** : owner gate sur les 12 P0 listés + lecture des 13 rapports détaillés `A01..A15.md`.
- **Discipline** : CLAUDE.md §5 LOOP + §13 evidence + memory `feedback_adversarial_audit_pattern.md`. Rate limit accepté comme évidence d'effort (parallélisme max, no shortcut).

---

*Verdict synthétisé 2026-05-11 par Claude Code orchestrateur. 13 rapports détaillés disponibles dans le même dossier. Relance des 7 agents post-reset 11:20am pour compléter le verdict.*
