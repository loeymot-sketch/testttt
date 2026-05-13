# ULTRA-PLAN — POS Massive Parallel Audit + Review + E2E — 2026-05-11

> **Mission** : audit + review + design E2E coverage massivement parallèle de la **Caisse POS** FoodKing.
> **Mode** : 20 sub-agents read-only adversariaux en parallèle, chacun avec rôle scope-strict.
> **Trigger** : owner instruction 2026-05-11 — "lancer tout en parallèle, pas A→Z, perfection sur rapidité".
> **Discipline** : CLAUDE.md §5 LOOP + §13 evidence + memory `feedback_adversarial_audit_pattern.md`.

---

## 0. Context & Baseline

- **HEAD** : `a220b9bd8` (post KDS sprint-2 + mobile cluster-7).
- **Branche** : `feature/mobile-app-le-cayenne-2026-05-10`.
- **Audit antérieur référence** : `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md` (13 P0 confirmés, NO-GO V1) + `99_CORRIGENDUM.md` (3 P0 reframed/retracted/downgraded).
- **Frozen-zones POS** (read-only audit, jamais d'edit) :
  - `app/Services/Orders/OrderService.php`
  - `app/Services/Payments/PaymentService.php`
  - `app/Services/Pricing/PricingService.php`
  - `app/Services/FrontendOrderService.php`
  - `app/Domain/Order/OrderStateMachine.php`
  - `resources/js/components/admin/pos/PaymentComponent.vue`
  - `resources/js/components/admin/pos/ItemComponent.vue`
  - `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`
- **NF525 invariants** : §8 CLAUDE.md (composition_snapshot, fiscal_sequence_no monotonic, audit chain HMAC, 6y retention).

---

## 1. Méthodologie — pattern adversarial parallèle

Hérité de `feedback_adversarial_audit_pattern.md` (validé sur audit POS 2026-05-09 — 4 P0 cross-validés multi-agents) :

- **20 sub-agents `general-purpose`** read-only (besoin de Write pour persister rapport).
- **Framing adversarial strict** : "BRAIN.md prétend X production-ready ✅ — prouve le contraire avec file:line citations vérifiées **maintenant**, pas mémorisées".
- **Output format strict** par agent : Markdown structuré `[P0|P1|P2|P3] file.ext:LN — finding — evidence — recommendation`.
- **Persist disk** : chaque agent écrit dans `reports/review/pos-parallel-2026-05-11/A{XX}_<role>.md` (skeleton + verdict + findings).
- **Scope strict** : prompt liste fichiers EXACTS in-scope, skip silencieux hors-scope.
- **Cross-validation** : findings identifiés par 2+ agents indépendants = `[CROSS-VALIDATED]` flag = signal très fort.
- **E2E design** : chaque agent doit aussi proposer 3-5 scénarios E2E spécifiques à sa fonctionnalité (Playwright pseudo-code), pas un simple find/replace de specs existantes.
- **Pas de fix** : audit + propositions, pas de patch (CLAUDE.md §5 étape 7 self-correct n'applique qu'aux exécutants, pas aux auditeurs).
- **Skeleton verdict pre-spawn** (`99_VERDICT_POS_PARALLEL.md` shell) = défense contre perte de synthèse si un agent crash.

---

## 2. Les 20 rôles agents — Couverture par fonctionnalité POS

### Tier 1 — Auth, Architecture, Pricing (5 agents)

| # | Rôle | Scope précis (file:line / dir) | Acceptance criteria |
|---|------|--------------------------------|---------------------|
| **A01** | **POS Auth & Sanctum** | `app/Http/Controllers/Auth/*`, `RefreshTokenController.php`, `routes/api.php` auth routes, `app/Http/Middleware/CheckApiKey.php` | Verify P0-07 (`['*']` ability), P0-08 (route abilities), token TTL, signed routes, password policy |
| **A02** | **POS Architecture & Layering** | `app/Http/Controllers/Admin/PosController.php`, `Admin/Pos/*`, `app/Services/PosParkedOrderService.php`, `app/Services/Pos/WalkInCustomerResolver.php`, dependency directions | Layering compliance, no controller→model bypass, frozen-zone respect |
| **A03** | **POS Pricing SSOT** | `app/Services/Pricing/PricingService.php` (FROZEN read-only), `tests/Feature/PosKioskPricingParityTest.php`, `tests/js/posKioskVariationParity.spec.js` (P0-14 self-compare), `tests/Feature/PosPricingSsotProofTest.php` | Verify cents arithmetic, composition_snapshot frozen, parité POS↔Kiosk, P0-14 fake sentinel |
| **A04** | **POS Order Creation Flow** | `PosController::store`, `PosOrderRequest`, `app/Services/Orders/OrderService.php` (FROZEN), `app/Http/Middleware/IdempotencyKeyMiddleware.php`, P0-05 RETRACTED re-investigate | Idempotency wiring, FormRequest authz, race conditions, validation completeness |
| **A05** | **POS Order State Machine** | `app/Domain/Order/OrderStateMachine.php` (FROZEN), `PaymentStateMachine.php`, `IllegalTransitionException.php`, `tests/Feature/OrderState*` | Verify P0-12 (`apply:185` lockForUpdate race), transition matrix completeness, idempotent transitions |

### Tier 2 — Fiscal NF525 (3 agents)

| # | Rôle | Scope | Acceptance criteria |
|---|------|-------|---------------------|
| **A06** | **Fiscal Sequence Allocation** | `app/Services/Fiscal/FiscalSequenceService.php`, allocation order create/close, `fiscal_alloc_error_at`, RetryFiscalAllocCommand, `tests/Feature/FiscalSequence*` | Monotonic + gap-free + concurrent safe + retry orphan |
| **A07** | **Fiscal Hash Chain + Audit** | `app/Services/Fiscal/AuditLogService.php`, `FiscalChainValidator.php`, `FiscalSealingService.php`, MySQL DELETE triggers, `audit_logs` migration | Chain HMAC + immutability + first-row anchor (P1-04) + UPDATE block (P1-03) |
| **A08** | **Z Report + X Report + Aggregate** | `app/Services/Fiscal/ZReportService.php` (line 323 aggregate), `XReportService.php`, `ZReportCashEnrichmentService.php`, P0-01/02 (SoftDeletes aggregate scope), `tests/Feature/ZReportClose*` | withTrashed in aggregate, archive 6y, P1-02 GATE-FZH-ALLOC throw not warn |

### Tier 3 — Cash & Payment (4 agents)

| # | Rôle | Scope | Acceptance criteria |
|---|------|-------|---------------------|
| **A09** | **Cash Drawer Session + Movements** | `app/Http/Controllers/Admin/Pos/CashDrawerController.php`, `CashDrawerSessionController.php`, `app/Services/CashDrawerService.php`, P0-09 (no lock), P1-06 (no-session cash), migration `cash_drawer_sessions` UNIQUE | Cache::lock + UNIQUE partial constraint + concurrent test |
| **A10** | **Cash Payment Flow** | `PosController::store` cash branch, `PaymentService.php` (FROZEN), Z reconciliation cash totals, change calculation | Cash collect → session → audit log → Z aggregation |
| **A11** | **Card / TPE / Payment Confirm** | `FrontendOrderController::paymentConfirm`, `routes/api.php:1082-1089` route abilities (P0-08), `tests/Feature/PaymentConfirm*`, idempotency `payment-confirm` group | Amount TPE verification (F-002), idempotency, ability `kiosk:order` route-level |
| **A12** | **Refund + Counter-Entry** | `RefundWithCounterEntryService.php` (P0-10 mirror gap), separate ticket numbering NF525, negative payment row, `tests/Feature/Refund*` | Split refund mirror, Z reconciliation, NF525 separate ticket |

### Tier 4 — Multi-tenant, Auth gates, Security (3 agents)

| # | Rôle | Scope | Acceptance criteria |
|---|------|-------|---------------------|
| **A13** | **Branch Isolation (BranchScope)** | `app/Models/Scopes/BranchScope.php`, all POS-surface models (Order, OrderItem, OrderPayment, CashDrawerSession, CashMovement, OrderStatusTransition, PosParkedOrder, OrderQuote, OrderCoupon), P0-06 INVESTIGATE re-grep `withoutGlobalScope` | 11 scoped + 4 missing P1-01 + 0 cross-branch leak |
| **A14** | **RBAC + FormRequest Authz** | Spatie permissions, `permission:settings` routes, FormRequest::authorize() coverage 88 endpoints, POS permissions matrix | Authz consistency, no controller-level can() escape |
| **A15** | **Webhook Events + SenangPay** | `app/Models/WebhookEvent.php`, `/senangpay-webhook/`, `app/Services/Payments/Gateways/SenangPay*` (P0-11 missing class → 500), unifié table | Class exists or route dropped, idempotency parity Stripe |

### Tier 5 — POS Frontend & Wizard (2 agents)

| # | Rôle | Scope | Acceptance criteria |
|---|------|-------|---------------------|
| **A16** | **POS Vanilla Wizard (FROZEN)** | `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`, diff vs main count | Diff vs main + frozen-zone audit + P0-15 (was P1) BRAIN drift |
| **A17** | **POS Admin Vue Surface** | `public/js/pos-app.js`, `pos-shell.js`, `app/Http/Controllers/Admin/AdminPosV4Controller.php` (or equivalent), `resources/js/components/admin/pos/*`, raw labels detection, i18n FR | No raw labels, FR-locale, V1 dine-in disabled flag respected |

### Tier 6 — Operations & Sync (3 agents)

| # | Rôle | Scope | Acceptance criteria |
|---|------|-------|---------------------|
| **A18** | **POS Discount + Coupon + Loyalty** | `tests/Feature/PosDiscount*`, `PosManualDiscountAuditTest.php`, `PosDiscountForgeryTest.php`, `PosDiscountPermissionTest.php`, coupon validation, loyalty wallet | Permission gate, forgery prevention, audit trail |
| **A19** | **POS Parked Order + Walk-in + Print** | `PosParkedOrderService.php`, `Pos/CustomerNfcLookupController.php`, `Pos/WalkInCustomerResolver.php`, `Pos/PosReceiptPrintController.php`, ESC/POS, P1-01 BranchScope PosParkedOrder | Park/hold/recall + variation availability on recall + print retry |
| **A20** | **POS↔KDS/OSS Sync + Tests Coverage** | `OrderCreated`/`OrderStatusChanged`/`ItemAvailabilityChanged` listeners, Outbox dispatch, KDS/OSS event consumption, polling fallback 5s, all POS E2E specs quality (P0-13 fake E2E across 4 specs) | Sync at-least-once + dedup + 0 fake E2E + real assertions |

---

## 3. Common rubric — Output template per agent

Each agent MUST write to `reports/review/pos-parallel-2026-05-11/A{XX}_<role>.md` with this structure :

```markdown
# A{XX} — {Role title}

> **Agent role**: {short description}
> **Scope**: {files / dirs / line ranges}
> **HEAD**: a220b9bd8
> **Method**: read-only, file:line citations verified at run time (not memorized)

## §1 Findings

### P0 — Critical (NF525 break, branch leak, payment integrity, fake green)
- **P0-{role}-XX** — `file.ext:LN` — {finding} — {evidence quote/grep} — {recommendation}

### P1 — High (security risk, hardening gap, observability gap)
- **P1-{role}-XX** — ...

### P2 — Medium (best-practice violation, dette, cosmétique structurel)
- **P2-{role}-XX** — ...

### P3 — Low (cosmetic, doc, polish)

## §2 Cross-validation watch list
- Lines / claims that might appear in another agent's report (potential CROSS-VALIDATED flag at synthesis).

## §3 Proposed E2E coverage (Playwright pseudo-code)
- Scenario 1: {title}
  - Setup: ...
  - Actions: ...
  - Assertions: ...
- Scenario 2: ...
(3-5 scénarios specific à la fonctionnalité)

## §4 Verdict for this scope
- {GO | HEAL | BLOCK | ESCALATE}
- 1-line rationale.

## §5 BRAIN.md drift note
- Does BRAIN §7 claim cover this scope? If yes, is it accurate? Drift severity.
```

---

## 4. Synthesis discipline

Après que les 20 agents aient terminé, je :

1. **Lit chaque rapport** `A{XX}_<role>.md` depuis disque (pas depuis context — discipline §13 evidence).
2. **Dédupplique findings** (un même file:line cité par 2+ agents → CROSS-VALIDATED bump severity).
3. **Compare aux 13 P0 connus** de l'audit 2026-05-09 (P0-01..14 + P0-15 downgraded) :
   - **Still present** → re-confirmation explicite.
   - **Closed** → mark as resolved with evidence.
   - **New** → register as fresh P0/P1/P2 with cross-validation count.
4. **Écrit `99_VERDICT_POS_PARALLEL.md`** : tableau consolidé P0/P1/P2 + cross-validation + BRAIN drift + estimated remediation + verdict GO/HEAL/BLOCK/ESCALATE.
5. **Push Graphiti episode** : `Ultra parallel POS audit 2026-05-11 — verdict {X} — Y P0 / Z P1`.
6. **Update PROJECT_BRAIN.md §2 §3 §8** : HEAD, last done, drift alerts.

---

## 5. Acceptance criteria for the whole mission

- ✅ 20 reports `A{XX}_<role>.md` durables sur disque.
- ✅ `99_VERDICT_POS_PARALLEL.md` synthèse consolidée.
- ✅ 0 file:line citation hallucinée (toutes vérifiables par grep/Read).
- ✅ Findings cross-validés flaggés explicitement.
- ✅ Owner reçoit verdict structuré P0/P1/P2 + remediation roadmap + E2E coverage proposal.
- ✅ Graphiti episode pushé.
- ✅ PROJECT_BRAIN.md mis à jour.

---

*Plan rédigé 2026-05-11 par Claude Code orchestrateur. Spawn 20 agents en parallèle dans le tool call suivant.*
