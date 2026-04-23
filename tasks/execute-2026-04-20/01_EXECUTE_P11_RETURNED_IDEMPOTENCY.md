# EXECUTE — P11_RETURNED_IDEMPOTENCY — 2026-04-20

## Status
**STATUS:** `PENDING_HUMAN_GATE`
**GATE_ARTIFACT:** `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md` §3 (Cycle C1)
**VAGUE:** V1 (Critique — corruption monétaire + NF525 audit trail)
**BLOCKING:** Ce cycle ne démarre pas tant que §3.8 + §16 du Gate Brief ne sont pas signés humainement. `human-gates.mdc:79-86` absolute prohibition sur self-approval.

## Source
- `plans/PLAN_POST_VERIFY_2026-04-20.md` §1.1 ligne 28
- `reports/review/VERIFY_TRACKER_2026-04-20.md` F-VERIFY-03-01
- `reports/review/VERIFY_03_P3_REFUND_RETURNED_2026-04-20.md` (verdict FAIL)

## Routing (AGENTS.md §Model Roles)
- **PRIMARY_MODEL:** `GPT-5.4` (justif AGENTS.md:13-17 — complex backend, fiscal/lifecycle/sync sensitive, frozen-zone edit)
- **SUBAGENT:** `foodking-complex-implementer`
- **RUNNER_MODE:** `single-session` (ACTIVE_CYCLE.md)
- **Composer NON éligible** (AGENTS.md:16 — no auth/pricing/lifecycle)

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/OrderService.php` (frozen zone LOCK_A + LOCK_B, PARTIAL RELEASE autorisé par gate)
- `app/Domain/Order/OrderStateMachine.php` (guard `from === to` + idempotency check)
- `app/Services/LoyaltyService.php` (cashback idempotency)
- `app/Services/AuditLogService.php` (NF525 trail append-only, verify-chain)

### SCOPE_FILES (whitelist — aucune autre édition autorisée)
- `app/Services/OrderService.php` — méthode `changeStatus` (L1499-1567)
- `app/Domain/Order/OrderStateMachine.php` — `apply()` L156 + `allows()` L30-79
- `app/Services/LoyaltyService.php` — `refundLoyaltyPointsOnReturn` (à lire, possible extension idempotency)
- `tests/Feature/Returned/IdempotentReturnedTest.php` (nouveau fichier)
- `tests/Unit/Domain/Order/OrderStateMachineReturnedIdempotencyTest.php` (nouveau)

### SUBSYSTEMS_OFF_LIMITS (explicite, SCOPE_PRESSURE si touché)
- `app/Services/FrontendOrderService.php` (sauf si symétrie stricte requise — ESCALATION)
- `app/Services/KitchenDisplaySystemOrderService.php` (traité par cycle 04 P11_RETURNED_KDS_BYPASS_LOCKDOWN)
- `app/Services/Fiscal/ZReportService.php` (traité par cycle 02)
- `app/Services/PaymentService.php` (traité par cycle 03)
- `database/migrations/**` (aucune migration prévue)
- Routes, controllers, requests (aucune modif)

## Invariants at Risk (project-invariants)
- **OrderStatus state-machine SSOT** — guard `from === to` doit distinguer "rejeu idempotent" vs "transition implicite tolérée"
- **NF525 audit chain** — `AuditLogService::write('order.returned')` ne doit pas dédoubler → verify-chain resterait intègre MAIS le compteur séquentiel serait pollué
- **OrderService ↔ FrontendOrderService symmetry** — si la garde s'applique uniquement à OrderService, documenter l'asymétrie avec `SYMMETRY_NOTE` (soft gate per human-gates.mdc:36)
- **dispatch-after-commit** — `OrderStatusChanged::dispatch(..., after_commit=true)` conservé strictement

## Dependencies
- **Gate signé** (bloquant absolu)
- **Aucun cycle préalable requis** — C1 est le premier de la chaîne V1
- **Cycle 02 (Z_OPEN_HARDENING) dépend de C1** pour cohérence sealed-Z guard (voir gate §12)

## Plan bref (à dérouler par le subagent GPT-5.4)

1. Lire `OrderStateMachine::apply()` L156 + `allows()` L30-79 + trace d'appel depuis `OrderService::changeStatus` L1499-1567.
2. Ajouter dans `changeStatus` : `if ($order->status === OrderStatus::RETURNED && $request->status === OrderStatus::RETURNED) { return $this->idempotentResponse($order); }` **avant** tout appel à `LoyaltyService`, `AuditLogService`, ou dispatch event.
3. Dans `OrderStateMachine::allows(from, to)` L30-79 : si `from === to` ET `to === RETURNED`, renvoyer `true` sans `IllegalTransitionException` MAIS ne pas re-exécuter side-effects (apply() doit déjà gérer via early return `if ($from === $next) return;` L139-140 — vérifier que les side-effects downstream sont skip).
4. Audit : confirmer qu'`AuditLogService::write` n'est jamais appelé 2 fois sur même `(order_id, from, to, actor_id)` — contrainte UNIQUE existante ou ajout in-service guard.
5. Tests :
   - `IdempotentReturnedTest::test_double_returned_no_double_cashback` (Feature)
   - `IdempotentReturnedTest::test_double_returned_no_double_audit_row`
   - `IdempotentReturnedTest::test_double_returned_http_200_both_calls`
   - `OrderStateMachineReturnedIdempotencyTest::test_apply_skips_side_effects_on_same_status` (Unit)
6. Écrire `reports/execution/RUN_P11_RETURNED_IDEMPOTENCY_2026-04-20.md` avec diff résumé + tests verts preuve.

## Acceptance Tests (EXIT criteria)
- [ ] `vendor/bin/phpunit --filter IdempotentReturnedTest` → 3/3 vert
- [ ] `vendor/bin/phpunit --filter OrderStateMachineReturnedIdempotencyTest` → 1/1 vert
- [ ] `vendor/bin/phpunit --testsuite=Unit --filter OrderStateMachine` → régression zéro
- [ ] Grep : aucun `$order->cashBack` ou `AuditLogService::write` dupliqué sur 2e appel RETURNED (preuve dans RUN_*.md)
- [ ] `git diff` touche EXCLUSIVEMENT les fichiers listés en SCOPE_FILES

## Exit Criteria additionnels
- [ ] `SYMMETRY_NOTE` créé si `FrontendOrderService` non touché (soft gate, halt pour revue humaine post-audit)
- [ ] `reports/execution/RUN_P11_RETURNED_IDEMPOTENCY_2026-04-20.md` contient le "Final report" §auto-remediation.mdc:166-187

## Scope Pressure Protocol (`scope.mdc`)
Si le subagent détecte qu'un fichier hors SCOPE_FILES est requis :
1. **STOP** — ne pas éditer
2. Écrire dans `REPORT_FILE` : `SCOPE_PRESSURE: <fichier requis> — <raison>`
3. Remonter à Claude (parent) — Claude décide : ESCALATION humaine ou micro-replan dans scope

## Remediation (`auto-remediation.mdc`)
- **Attempt 1-2 (KO non critique)** → Claude diagnose + micro-replan + re-route GPT-5.4 + re-EXECUTE + re-AUDIT (même session, sans confirmation humaine)
- **Attempt 3 même `bug_signature`** → **HUMAN_GATE bug irrésolu** (format auto-remediation.mdc:116-131)
- **Diff touche zone critique hors SCOPE** → **HUMAN_GATE zone critique** (project-invariants.mdc + human-gates.mdc:23)

## Deliverables
- Diff applicatif minimal et ciblé (≤ ~80 lignes attendues)
- 4 tests automatisés (3 Feature + 1 Unit)
- `reports/execution/RUN_P11_RETURNED_IDEMPOTENCY_2026-04-20.md` (gabarit Final report §auto-remediation.mdc:166-187)
- Pas de modification docs/gates/ — le gate est déjà posé

## Communication
- Pas de commit hors SCOPE_FILES
- Pas d'édition `docs/`, `.cursor/`, `plans/`, autres `tasks/**`
- Rapport subagent court (résumé + file:line + test output — pas de full diff)
