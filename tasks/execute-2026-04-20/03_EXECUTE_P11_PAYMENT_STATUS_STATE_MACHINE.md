# EXECUTE — P11_PAYMENT_STATUS_STATE_MACHINE — 2026-04-20

## Status
**STATUS:** `PENDING_HUMAN_GATE`
**GATE_ARTIFACT:** `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` §6 (Cycle C4)
**VAGUE:** V1 (Critique — idempotence paiement + NF525 + pricing SSOT)
**BLOCKING:** Gate signé requis. `human-gates.mdc:23,24` (frozen zones OrderService + PaymentService, NF525 invariant).
**Dépendance amont:** **dépend du cycle 05** (`P11_IDEMPOTENCY_KEY_MIDDLEWARE` — V2) si activé avant. Sinon, idempotence portée in-service.

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.1 ligne 31
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-09-01
- `reports/review/VERIFY_09_PAYMENTS_IDEMPOTENCY_2026-04-20.md`

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `GPT-5.4` (AGENTS.md:15 — lifecycle + frozen + schéma potentiel)
- **SUBAGENT:** `foodking-complex-implementer`
- **RUNNER_MODE:** `single-session`

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Domain/Payment/PaymentStateMachine.php` (nouveau — SSOT transitions `PaymentStatus`)
- `app/Domain/Payment/IllegalPaymentTransitionException.php` (nouveau)
- `app/Services/OrderService.php` — `changePaymentStatus()` L1592-1646 (frozen LOCK_A+B, PARTIAL RELEASE)
- `app/Services/PaymentService.php` (frozen LOCK_B, PARTIAL RELEASE)
- `app/Http/Requests/PaymentStatusRequest.php` — `Rule::in` stricte sur enum `PaymentStatus`

### SCOPE_FILES
- `app/Domain/Payment/PaymentStateMachine.php` (nouveau)
- `app/Domain/Payment/IllegalPaymentTransitionException.php` (nouveau)
- `app/Enums/PaymentStatus.php` (lecture ; extension OK si requis pour transitions)
- `app/Services/OrderService.php` (méthode changePaymentStatus uniquement)
- `app/Services/PaymentService.php` (touches minimales)
- `app/Http/Requests/PaymentStatusRequest.php`
- `tests/Unit/Domain/Payment/PaymentStateMachineTest.php` (nouveau)
- `tests/Feature/Payment/PaymentStatusStateMachineTest.php` (nouveau)

### SUBSYSTEMS_OFF_LIMITS
- `app/Services/FrontendOrderService.php` (symétrie surveillée — `SYMMETRY_NOTE` si non touché)
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/LoyaltyService.php`
- `app/Domain/Order/OrderStateMachine.php` (indépendant)
- Routes, controllers hors request
- Migrations DB (non prévues ; si ajout enum value → déclenche sous-gate `human-gates.mdc:19`)

## Invariants at Risk
- **Pricing SSOT** — `changePaymentStatus` ne recalcule JAMAIS de montants (lecture seule `order_amount`/`paid_amount`)
- **OrderStatus ↔ PaymentStatus interaction** — transition `DELIVERED + PAID` reste valide ; `RETURNED + REFUNDED` tracé
- **NF525** — `AuditLogService::write('order.payment_status_changed')` append-only, idempotent sur même `(order_id, from, to, tender_type)`
- **dispatch-after-commit** — event `OrderPaymentStatusChanged` dispatché hors transaction

## Dependencies
- Gate signé (bloquant)
- Cycle 01 (RETURNED_IDEMPOTENCY) **recommandé avant** (cohérence pattern idempotency between OrderStatus et PaymentStatus)
- Cycle 02 (FISCAL_Z_OPEN_HARDENING) **strictement avant** (la sealed-Z guard doit gater `changePaymentStatus`)

## Plan bref

1. Lire `OrderStateMachine` comme template. Lire `PaymentStatus` enum, `PaymentStatusRequest`, `OrderService::changePaymentStatus`, `PaymentService`.
2. Créer `PaymentStateMachine::allows(from, to, actor)` avec matrice :
   - `UNPAID (0) → PAID (1)` ; `PAID → REFUNDED (2)` ; `PAID → PARTIAL_REFUNDED (3)` ; terminal REFUNDED.
   - Admin override `Admin` role peut sortir de terminal.
3. `PaymentStateMachine::apply(order, next, actor, reason?)` transactionnel + `lockForUpdate` + audit.
4. `OrderService::changePaymentStatus` : remplacer affectation directe par `PaymentStateMachine::apply(...)`.
5. `PaymentStatusRequest` : `Rule::in(PaymentStatus::all())` + rule custom `ValidPaymentTransition` ou in-service.
6. Idempotency in-service : si `(order_id, idempotency_key_from_header)` déjà vu avec même payload → replay 200 OK avec audit `replayed: true` MAIS aucun nouveau side-effect.
7. `SYMMETRY_NOTE` si `FrontendOrderService` pas touché (soft gate post-audit).
8. Tests : 5 tests Unit state-machine + 4 tests Feature idempotence/transition.
9. `reports/execution/RUN_P11_PAYMENT_STATUS_STATE_MACHINE_2026-04-20.md` avec Final report.

## Acceptance Tests
- [ ] `tests/Unit/Domain/Payment/PaymentStateMachineTest` 5/5
- [ ] `tests/Feature/Payment/PaymentStatusStateMachineTest` 4/4 (idempotence, illegal transition, admin override, symmetry)
- [ ] Régression `tests/Feature/PaymentService*` et `tests/Feature/Fiscal/*` zéro
- [ ] `git diff` strictement dans SCOPE_FILES

## Exit Criteria
- [ ] `PaymentStateMachine` = SSOT unique des transitions PaymentStatus
- [ ] 2 appels consécutifs identiques `changePaymentStatus` → 1 seule ligne `audit_logs` + 1 seul event
- [ ] `IllegalPaymentTransitionException` HTTP 422 avec contexte clair
- [ ] `SYMMETRY_NOTE` documenté si FrontendOrderService intentionnellement intouché

## Scope Pressure Protocol
Si ajout enum value `PaymentStatus::*` → **STOP** + ESCALATION (déclenche `human-gates.mdc:24` invariant violation). Décision humaine requise.

## Remediation
- Attempts 1-2 non critique → auto-remediation Claude
- Attempt 3 même bug → HUMAN_GATE
- Touche FrontendOrderService sans SYMMETRY_NOTE explicite → soft gate

## Deliverables
- `PaymentStateMachine.php` + `IllegalPaymentTransitionException.php` (nouveaux)
- Diff `OrderService::changePaymentStatus`, `PaymentService`, `PaymentStatusRequest` (minimal)
- 9 tests automatisés (5 Unit + 4 Feature)
- `reports/execution/RUN_P11_PAYMENT_STATUS_STATE_MACHINE_2026-04-20.md`

## Communication
Subagent renvoie : fichiers créés, diff par fichier (≤ 20 lignes chacun), commandes phpunit + sortie, présence `SYMMETRY_NOTE` (oui/non + raison).
