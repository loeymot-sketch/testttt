# 🔍 SPOT-CHECK P0 VERIFICATION — Owner Option C iter15

**Date** : 2026-05-09
**Branche** : `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` HEAD `860484f50`
**Méthode** : 4 sub-agents spot-check parallèles (Fiscal + Auth + Cash-Payment + Test-Integrity) verify les claims du deeper audit `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md` via grep + Read.

---

## §0 — Synthèse globale

| Catégorie | P0 claims | CONFIRMED | MITIGATED | RETRACTED | Severity |
|---|---|---|---|---|---|
| Fiscal & data integrity | 3 | **3** | 0 | 0 | HIGH × 3 |
| Multi-tenant & auth | 3 | **2** | **1** (P0-06) | 0 | CRITICAL × 1 + MEDIUM × 1 |
| Cash, payment, hardware | 4 | **4** | 0 | 0 | HIGH × 2 + MEDIUM × 2 |
| Test integrity | 2 | **2** | 0 | 0 | HIGH × 2 |
| **TOTAL** | **12** | **11** | **1** | **0** | **8 HIGH/CRITICAL + 3 MEDIUM** |

**Verdict** : Le deeper audit était **bien évidence-based**. 11/12 P0 confirmés post-spot-check. Seul P0-06 downgraded (auth gate existe).

V1 GO-LIVE est **BLOQUÉ** par 11 P0 réels. Estimation remediation **3-5j-agent** valide.

---

## §1 — P0 Fiscal & data integrity (3/3 CONFIRMED HIGH)

### P0-01/02 ✅ CONFIRMED HIGH — ZReportService::aggregate exclude post-Z soft-deleted orders
- **Evidence** : `app/Services/Fiscal/ZReportService.php:323` utilise `withoutGlobalScope(BranchScope::class)` UNIQUEMENT — SoftDeletingScope préservé
- **Code intentionnel** : commentaire lignes 307-310 explique le choix de single-scope removal
- **Issue narrower** : soft-deleted post-allocation orders (avec `fiscal_sequence_no` assigned) doivent être inclus via `withTrashed()` pour NF525 fiscal continuity
- **Fix** : ajouter `->withTrashed()` ou `->withoutGlobalScope(SoftDeletingScope::class)` à l'aggregate query

### P0-03 ✅ CONFIRMED HIGH — z_reports DELETE trigger 0 test coverage
- **Evidence** : Migration `2026_05_09_160000:42-46` driver-conditional MySQL/MariaDB only
- **phpunit.xml:39** : `DB_CONNECTION=sqlite` default → tests skip trigger creation
- **`grep -rln "MysqlOnly" tests/`** : 0 results — pas de trait MysqlOnly
- **`tests/Feature/Fiscal/ZReportCloseTest.php:130`** comment "trigger does NOT cover z_reports in this vague — application-layer detection is the backstop"
- **Fix** : ajouter `MysqlOnly` trait + Sentinel CI variant MySQL OR app-layer DELETE guard cohérent SQLite/MySQL

### P0-04 ✅ CONFIRMED HIGH — cascadeOnDelete cash_movements + order_payments
- **Evidence** : `2026_05_08_140100:47-50` cash_movements FK `cash_drawer_session_id` cascadeOnDelete
- **`2026_05_06_180000:32`** order_payments FK `order_id` cascadeOnDelete
- **Aucun DELETE trigger** sur cash_movements / order_payments / cash_drawer_sessions
- **Risk** : delete parent (order ou cash_drawer_session) → audit trail wiped silently
- **Fix** : `restrictOnDelete()` + DELETE trigger SIGNAL '45000' sur les 3 tables (parité audit_logs + z_reports)

---

## §2 — P0 Multi-tenant & auth (2 CONFIRMED + 1 MITIGATED)

### P0-06 🟡 MITIGATED → MEDIUM — PosOrderController::show withoutGlobalScope
- **Evidence** : `app/Http/Controllers/Admin/PosOrderController.php:108` confirmé `Order::withoutGlobalScope(BranchScope::class)->findOrFail($order)`
- **Mitigation** : `auth()->user()?->can('pos')` check ligne 96 + middleware `auth:sanctum` parent group
- **Risk reel** : user avec ability 'pos' mais branch limité → théoriquement peut fetch cross-branch
- **Verdict** : Pas un cross-tenant leak automatique, mais audit trail manquant pour cross-branch admin reads
- **Severity adjusted** : HIGH → MEDIUM (downgrade)
- **Fix** : ajouter logging admin cross-branch reads OU appeler `authorizeBranchScope()` dans show()

### P0-07 🔴 CONFIRMED CRITICAL — RefreshTokenController ['*'] privilege escalation
- **Evidence** : `app/Http/Controllers/Auth/RefreshTokenController.php:23-26` issues `['*']` ability unconditionally
- **Route** : `routes/api.php:147` requires `installed + apiKey` only — PAS `auth:sanctum`
- **TokenStoreRequest::authorize()** ligne 17-19 returns `true`
- **Attack vector** : kiosk token leaked → `/refresh-token` → new token avec `['*']` → admin-equivalent
- **Severity confirmed** : **CRITICAL — privilege escalation production**
- **Fix** : copier abilities du token actuel, jamais wildcard `['*']`

### P0-08 ✅ CONFIRMED MEDIUM — Missing route-level abilities:kiosk:order
- **Evidence** : `routes/api.php:1082-1089` `frontend/order` create + `payment-confirm` group sous `auth:sanctum` SEUL
- **Comparison** : autres routes (lignes 1187, 1233) ont `'auth:sanctum', 'abilities:kiosk:order', 'throttle:30,1'`
- **PaymentConfirmRequest::authorize()** lignes 12-26 : `tokenCan('kiosk:order')` ligne 20 → fallback OK pour payment
- **OrderRequest::authorize()** ligne 28-30 : `return true;` — **NO ability check sur order create**
- **Risk** : non-kiosk authenticated user pourrait POST `/frontend/order` → FormRequest authorize() ne block pas
- **Fix** : add middleware `'abilities:kiosk:order'` sur le group route + tightening OrderRequest::authorize()

---

## §3 — P0 Cash, payment, hardware (4/4 CONFIRMED)

### P0-09 ✅ CONFIRMED HIGH — CashDrawerService::openSession concurrent dual sessions
- **Evidence** : `app/Services/Cash/CashDrawerService.php:33-57` check-then-create classic TOCTOU
- **Lines 39-47** : query existing session OUTSIDE transaction
- **Lines 49-57** : create inside separate transaction
- **Migration 2026_05_08_140000:30-60** : composite index `(branch_id, status)` SEUL — pas de UNIQUE partial sur status='open'
- **Race** : 2 POS logins simultanés → both read "not exists" → both create OPEN session
- **Fix** : (a) Cache::lock + lockForUpdate inside transaction, OR (b) UNIQUE partial index `(branch_id, opened_by_user_id) WHERE status='open'` (MySQL 8.0.13+)

### P0-10 ✅ CONFIRMED MEDIUM — Refund counter-entry doesn't mirror order_payments
- **Evidence** : `app/Services/Order/RefundWithCounterEntryService.php:86-141` execute() crée mirror Order avec items negated
- **Missing** : pas d'OrderPayment counter-entries créés pour mirror
- **Z reconciliation broken** : split-payment refund (cash + card) → mirror has 0 payments, parent has 2
- **Reader can't match** refund split back to payment split
- **Fix** : insert mirror OrderPayment rows par tranche split avec `mode` original + `amount` negated

### P0-11 ✅ CONFIRMED HIGH — WebhookEvent orphan + SenangPay 500
- **WebhookEvent orphan** : `grep WebhookEvent::` → 0 writes en `app/`. Model `firstOrCreate` template comment ligne 18 mais aucun handler call
- **SenangPay class missing** : `app/Http/PaymentGateways/Routes/senangpay.php:18` dispatch to `[Senangpay::class, 'webhook']` mais `app/Http/PaymentGateways/Gateways/Senangpay.php` **n'existe pas**
- **`/payment/senangpay-webhook/`** → ClassNotFoundException → **HTTP 500**
- **BRAIN §7 row 5** "webhook_events unifié ✅" est **FACTUELLEMENT FAUX**
- **Fix** : (a) restaurer Senangpay Gateway class + wire WebhookEvent OR (b) retirer route si dead

### P0-12 ✅ CONFIRMED MEDIUM — OrderStateMachine::apply race condition
- **Evidence** : `app/Domain/Order/OrderStateMachine.php:179-219`
- **Line 185** : `$from = (int) $order->status` lit depuis Model in-memory AVANT transaction
- **Line 203-208** : transaction + write inside, mais lock manque
- **Race** : 2 concurrent calls `apply($order, DELIVERED, ...)` → both read same old status → both pass allows() check → both write DELIVERED
- **OrderService::changeStatus** (iter13 fix) utilise `lockForUpdate()->firstOrFail()` correctement, mais `apply()` ne le fait pas
- **Fix** : ajouter `lockForUpdate` upstream dans apply() équivalent à OrderService::changeStatus

---

## §4 — P0 Test integrity (2/2 CONFIRMED HIGH)

### P0-13 ✅ CONFIRMED HIGH — E2E specs fake (smoke test, pas end-to-end)
- **`02-pos-cash.spec.js:50-72`** "full POS cash order cycle" :
  - **1** conditional `.click()` ligne 64 (wrapped `.catch(() => false)` may never execute)
  - **0** `.fill()` calls
  - **0** payment/checkout flow steps
  - Assertions : `body.length > 100` + `not.toMatch(/Whoops|Fatal/)`
  - **0** DB assertions (no `Order::`, `db.`)
- **`05-pos-card.spec.js`** :
  - **0** clicks, **0** fills
  - "card payment flow" → ZÉRO payment steps
- **`03-kiosk-wizard.spec.js`** :
  - **1** conditional click ligne 82 wrapped `.catch(() => {})`
  - **0** wizard interaction réelle
- **`04-kds-status.spec.js`** :
  - **0** clicks, **0** fills, only URL+text length checks
- **Verdict** : "16/16 E2E green" est **FAUX** — smoke tests masquerading as E2E
- **Fix** : réécrire 4 specs adversarial-grade — real Playwright `page.click`, wizard flow steps, payment steps, DB state assertions

### P0-14 ✅ CONFIRMED HIGH — posKioskVariationParity sentinel fake
- **File** : `tests/js/posKioskVariationParity.spec.js`
- **Imports lignes 1-11** : entirely from `__fixtures__/variationParityFixtures` (fixture functions only)
- **Fixture behavior** :
  - `kioskTryIncrement()` (line 73-76) **delegates to `posTryIncrement()`** — **same logic** both paths
  - `parityDisplayTotal()` calls `computePosCartLineDisplayTotal()` (real helper) on **fixture data**
- **Critical findings** :
  - **0** API calls (no `fetch`, `axios`, real `PricingService`)
  - **0** backend integration
  - All assertions = **fixture-to-fixture comparison** (line 33: `parityDisplayTotal(item, posV)).toBe(parityDisplayTotal(item, kioskV))` — both args from same fixtures)
- **Verdict** : **TAUTOLOGY** — validates fixtures match fixtures. Real POS↔Kiosk pricing drift NOT detected.
- **Fix** : invoquer real `PricingService::compute` (PHP via API endpoint OR JS port via worker), comparer outputs against fixture expectations + against each other surface

---

## §5 — Nouveau plan remediation P0 ajusté post-spot-check

| # | P0 | Severity | Effort | Sub-agent role |
|---|---|---|---|---|
| 1 | P0-01/02 ZReportService withTrashed | HIGH | 0.25j | Architect+Tester |
| 2 | P0-03 z_reports trigger MysqlOnly variant | HIGH | 0.5j | Tester+SRE |
| 3 | P0-04 cascadeOnDelete → restrictOnDelete + triggers cash + payment | HIGH | 0.5j | DBA+Tester |
| 4 | ~~P0-06~~ → P1 logging admin cross-branch | MEDIUM | V1.0.1 | Security |
| 5 | **P0-07 RefreshTokenController abilities preserve (CRITICAL)** | CRITICAL | 0.25j | Security+Tester |
| 6 | P0-08 add abilities:kiosk:order middleware route | MEDIUM | 0.25j | Security |
| 7 | P0-09 CashDrawerService Cache::lock + UNIQUE partial | HIGH | 0.5j | DBA+Tester |
| 8 | P0-10 RefundWithCounterEntry mirror split-payment rows | MEDIUM | 0.5j | Architect+Tester |
| 9 | P0-11 SenangPay Gateway restore OR remove route + WebhookEvent wire | HIGH | 1j | Architect+Security |
| 10 | P0-12 OrderStateMachine::apply lockForUpdate | MEDIUM | 0.25j | Architect+Tester |
| 11 | P0-13 réécrire 4 e2e specs adversarial real Playwright | HIGH | 1.5j | Tester |
| 12 | P0-14 posKioskVariationParity invoke real PricingService | HIGH | 0.5j | Tester+Architect |

**Total Phase 0 remediation** : **~6j-agent** (bumped from 3-5j-agent original estimate post-spot-check).

---

## §6 — Owner gates remediation

### Gate G0-A — SoftDeletes scope decision (P0-01/02)
**Question** : `withTrashed()` dans aggregate Z, OU retirer `SoftDeletes` de Order/OrderItem (archive-then-deny pattern) ?
- Option A : Add `withTrashed()` à aggregate (faible effort, preserve archive workflow)
- Option B : Retirer SoftDeletes complet (gros refactor mais plus simple invariants NF525)

### Gate G0-B — SenangPay class missing (P0-11)
**Question** : Restaurer Senangpay Gateway class OR retirer route + WebhookEvent orphan ?
- Option A : Restaurer (1j, requires SenangPay creds + impl complete)
- Option B : Retirer route /senangpay-webhook/ + delete WebhookEvent model + revert iter11 (0.5j cleaner)
- Option C : Stub Gateway class qui retourne 501 Not Implemented (0.25j, deferred V1.x)

### Gate G0-C — Frozen-zone breach (P0-15 downgraded P1)
**Question** : Owner explicitement gate les diffs frozen-zone existants OR revert ?
- KioskWizard +1665 lignes, KioskApp +892, pos-wizard.js +237 lignes logic
- Update BRAIN §2 wording avec réalité OR revert non-gated

---

## §7 — Conclusion

Spot-check **valide à 92%** le deeper audit (11/12 P0 confirmed). 1 P0 mitigated (P0-06 → MEDIUM).

**V1 GO-LIVE bloqué** par 11 P0 réels. **Phase 0 remediation ~6j-agent** prioritaire avant tout autre travail.

**Mon ULTRAPLAN doc reste utile** pour Phase C (V1.0.1) + Phase D (V1.x), mais Phase A doit être augmentée par la remediation §5 ci-dessus.

— *Spot-check complet via 4 sub-agents YC GStack en parallèle. Evidence-based verification per CLAUDE.md §11. Discipline LOOP §5 respectée — audit only, pas de code modifié.*
