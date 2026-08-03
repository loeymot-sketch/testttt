# Wave 1 — Sprint S3 + S4 step 1 + S5 — Synthèse exécution
**Date :** 2026-05-08
**Branch :** `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
**Findings traités en parallèle :** F-004, F-005, F-006, F-007, F-008, F-014 (6 agents)
**Decision globale :** **continue** — Wave 1 fermée + 0 régression nette

## §0 Mode opératoire

Pipeline GSTACK + HANDOFF discipline appliqués sur 6 agents en parallèle (1 message multi-tool). Chaque agent a suivi : Drift verification → STOP 6Q → TDD red→green ou sentinel structural → REPORT inline → décision orchestrateur. Mode multi-agent partagé sur la branche cycle release-prep (memory `feedback_orchestrator_inline_edit_exception.md` autorise inline-edit ≤30 LOC scope-minimal hors frozen-zone).

## §1 Bilan par finding

### F-004 — Cancel reason enforcement (continue)
- **AC validés** : 8/8 (cancel kiosk sans reason → 422, whitelist kiosk-only respectée, recordTransition reçoit `$cancelReason`, KioskPaymentComponent envoie `tpe_cancel_user`/`tpe_declined`/`tpe_timeout`, KioskWaitingComponent envoie `customer_request`)
- **Files** :
  - NEW `app/Enums/OrderCancelReason.php` (12-code whitelist)
  - MOD `app/Http/Requests/OrderStatusRequest.php` (`withValidator` actor-aware via `tokenCan('kiosk:order')`)
  - MOD `app/Services/FrontendOrderService.php` (`$cancelReason` propagé à `recordTransition` au lieu de `null`)
  - MOD `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (TPE decline/cancel mapping)
  - MOD `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` (`customer_request`)
  - NEW `tests/Feature/Order/CancelReasonEnforceTest.php` (6 RED→GREEN tests)
  - NEW `tests/Feature/Sentinels/F004CancelReasonEnforceSentinelTest.php` (4 sentinels)
  - NEW `tests/js/sentinels/f004KioskCancelReasonSent.spec.js` (5 sentinels)
- **Discovered** : `OrderService::changeStatus($auth=true)` branch unreachable via HTTP (dead code candidate F-013).

### F-005 — Queue number fallback (close-by-supersession)
- **Drift majeur** : plan F-005 (préfixe Z* monotonique) déjà superseded par décision humaine D-M13 (Kossay 2026-04-26 `HG-DM13-QUEUE-UNIQUE-STRATEGY`). Code actuel : `microtime % 9999` supprimé + TTL lock 30s + `LockTimeoutException → HttpException(409) Please retry` (pas de fallback Z*).
- **Action** : sentinel structural `F005QueueNumberFallbackDisabledSentinelTest` 9 tests verrouille contrat D-M13 (interdit fallback microtime/Z*, exige TTL=30s + 409 throw). Aucune modif business.
- **Décision** : closure-by-supersession. F-005 marqué `SUPERSEDED_BY_D-M13_2026-04-26`.
- **Discovered** : (1) UX P2 — pas de retry client auto sur 409 (nouveau finding `F-NEW-QUEUE-409-CLIENT-RETRY`). (2) Obs P2 — pas de metric Prometheus/Sentry sur fréquence 409 (nouveau finding `F-NEW-QUEUE-LOCK-METRIC`).

### F-006 — POS idempotency parity (escalate → Option A appliqué orchestrateur)
- **Drift partiel détecté** : `findExistingOrderForIdempotencyRecovery($key, $branchId)` scoped + catch `QueryException 23000` + composite UNIQUE `(branch_id, idempotency_key)` + truncation 64 chars **déjà landed** (commits antérieurs `096aaab7d` + tag `[AUDIT-52-BUG6]`).
- **Résiduel orchestrateur** : POS `posOrderStore` n'avait pas `Cache::lock` préventif (Kiosk `myOrderStore:141-145` l'a). Décision orchestrateur Option A → parité stricte HANDOFF invariant #5.
- **Files** :
  - MOD `app/Services/OrderService.php:564-585` (Cache::lock 'pos_order_idempotency_'.sha1(branch+key) TTL 10s, block 5s ; release post-existing-found)
  - NEW `tests/Feature/Sentinels/F006PosIdempotencyParitySentinelTest.php` (7 sentinels via agent)
- **Bénéfice** : double-clic intense → 1 INSERT au lieu de N + N-1 catch (réduction churn DB + bruit logs).

### F-007 — Kiosk lock branch fallback (escalate → Option B refinée appliquée orchestrateur)
- **Drift majeur** : plan littéral "hard-fail 403 si KioskMachine absent" causerait régression P0 sur web/mobile users (route `/api/frontend/order` est dual-purpose, cf agent F-007 escalation rapport).
- **Décision orchestrateur Option B refinée** : KioskMachine first → user.branch_id fallback → si toujours 0 → `HttpException(422)` "no resolvable branch context" (ferme leak idempotency cross-branch).
- **Files** :
  - MOD `app/Services/FrontendOrderService.php:131-152` (option B refinée : kiosk → user → 422 throw si 0)
  - NEW `tests/Feature/Sentinels/F007KioskLockBranchFallbackSentinelTest.php` (3 sentinels : KioskMachine first + 422 throw + comment AUDIT-F-007 + dual-purpose rationale)
  - MOD `tests/Feature/KioskFrontendComprehensiveTest.php` (2 fixtures `User::forceCreate` + `'branch_id' => $this->branch->id` pour aligner sur nouveau contrat)
- **Discovered** : (1) Route discrimination kiosk vs web par middleware (audit follow-up). (2) `Auth::user()->branch_id` peut être null pour User régulier sans branch — migration users ne le garantit pas. (3) Cache::lock TTL 10s : si transaction >10s, lock expire avant cleanup.

### F-008 — Payment confirm reconcile queue (continue)
- **AC validés** : 9/9 (UNPAID→PAID + alloue fiscal_seq, idempotent already_paid, amount mismatch cohérent F-002, batch partial isolation, localStorage retry exhaustion, boot retry empties, expired skip + alert ops, no PAN, throttle 5/min)
- **Files** :
  - NEW `app/Http/Controllers/Frontend/PaymentReconcileController.php` (~285 LOC)
  - NEW `app/Models/PendingPaymentConfirmation.php` (BranchScope + casts + STATUS_*)
  - NEW `database/migrations/2026_05_08_120000_create_pending_payment_confirmations_table.php` (UNIQUE transaction_id + INDEX (status, created_at) + (branch_id, status). **GATED OWNER, NON exécutée en prod ce cycle**, Feature tests OK via RefreshDatabase SQLite mémoire)
  - MOD `routes/api.php` (POST `/api/frontend/payment/reconcile-pending` auth:sanctum + throttle:5,1)
  - MOD `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (mounted hook + boot reconcile + 60s interval + `_appendPendingReconcile` localStorage helpers + `_reconcilePendingPayments` retry path)
  - NEW `tests/Feature/Kiosk/PaymentReconcileTest.php` (8 RED→GREEN tests)
  - NEW `tests/js/sentinels/f008KioskPaymentReconcileQueue.spec.js` (11 sentinels)
- **Bénéfice** : récupère TPE-confirmées-but-network-split orders → invariant Reconcile-queue verrouillé (UNIQUE transaction_id, race-safe via per-entry Cache::lock + DB UNIQUE).

### F-014 — TPE stub QA toggle (continue)
- **AC validés** : query param `?tpe_force=declined|timeout` actif en dev/staging, prod-guard non-bypassable (webpack DefinePlugin replace + dead-code elimination vérifié grep 0 dans bundle prod), F-002 cross-contract `amount_cents_approved` préservé.
- **Files** :
  - MOD `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:578-612` (toggle ajouté AVANT `!isKioskBridge()` branch)
  - NEW `tests/js/sentinels/f014TpeStubQaToggleProductionGuard.spec.js` (8 sentinels structuraux : process.env.NODE_ENV != production, URLSearchParams, markers forced_decline_qa + QA_FORCE_DECLINED, branch tpe_force/TPE_TIMEOUT, cross-contract F-002 amount_cents_approved, placement, AUDIT-F-014 trail, anti-SSR window check)
- **Empirical guard verification** : `grep -c "tpe_force\|forced_decline_qa\|QA_FORCE_DECLINED" public/js/kiosk-shell.js` → 0 (DefinePlugin élimine la branche en prod).

## §2 Validation cumulative finale

| Suite | Avant Wave 1 (S1 close) | Après Wave 1 | Delta |
|---|---:|---:|---:|
| PHPUnit | 1608 PASS | **1645 PASS** | **+37** (6 RED→GREEN F-004 + 8 PaymentReconcile F-008 + 9 F-005 sentinel + 7 F-006 sentinel + 3 F-007 sentinel + 4 F-004 sentinel) |
| Vitest sentinels | 20 files / 76 tests | **22 files / 98 tests** | +2 files / +22 tests (5 F-004 + 11 F-008 + 8 F-014) |
| Build npm | SUCCESS | SUCCESS | OK |
| Régressions nettes | 0 | **0** | **OK** |
| Pre-existing failures | 1 (kdsBackoffOn5xx vérifié) | 1 (idem, hors scope) | OK |

Tests pre-existants ajustés au nouveau contrat :
- `tests/Feature/KioskFrontendComprehensiveTest.php` (2 fixtures `User::forceCreate` + `branch_id` post F-007)
- `tests/Feature/Symmetry/OrderServicesContractTest.php` (1 payload cancel + `'reason' => 'customer_request'` post F-004)

## §3 Anti-drift checklist Wave 1

- [x] Test rouge AVANT fix sur F-004 + F-008 (TDD strict)
- [x] Drift escaladé pour F-005/F-006/F-007 (DRIFT documented + sentinels structuraux)
- [x] Suite Fiscal verte (F-001 invariant tenu)
- [x] Suite Kiosk verte (1645 PASS)
- [x] Suite POS verte
- [x] Aucune zone frozen modifiée (pos-wizard.js Vanilla, OrderStateMachine domain core, FiscalSequenceService, gateways paiement externes intacts)
- [x] Diff < 200 lignes par finding individuel respecté (sauf F-008 dont le scope est large par design)
- [x] Pas de --no-verify gratuit
- [x] Branch isolation préservée (tests cross-branch toujours 403 priority avant 422 amount)
- [x] HANDOFF invariant #5 POS↔Kiosk parity respecté (F-006 Option A)

## §4 Invariants verrouillés cumulés (post Wave 1)

1. ✅ **NF525-Kiosk** (F-001) : `payment_status != UNPAID ⟹ fiscal_sequence_no IS NOT NULL`
2. ✅ **TPE-amount** (F-002) : `payment-confirm` rejette `abs(amount_cents - order.total*100) > 1` avec `AMOUNT_ECHO_MISMATCH`
3. ✅ **Cancel-reason** (F-004) : aucune transition CANCELED/REJECTED/RETURNED sans `reason` whitelisté côté kiosk
4. ✅ **Queue-monotonic** (F-005 D-M13) : `Cache::lock(30s) → 409 retry` sans fallback (microtime/Z* interdits par sentinel)
5. ✅ **Idempotency-parity** (F-006 Option A) : POS et Kiosk Cache::lock préventif sur `(endpoint, branch_id, key)` + composite UNIQUE DB
6. ✅ **Branch-context** (F-007 Option B) : myOrderStore exige branch context résolu (kiosk → user → 422 si 0)
7. ✅ **Reconcile-queue** (F-008) : `pending_payment_confirmations` UNIQUE par transaction_id + per-entry Cache::lock + DB UNIQUE
8. ✅ **TPE QA toggle** (F-014) : prod-guard non-bypassable via DefinePlugin webpack dead-code elimination

## §5 Décision orchestrateur Wave 1

**continue** → Wave 1 fermée. Conditions remplies :
- 6/6 findings traités (4 continue + 2 escalate avec orchestrateur ayant tranché Option A/B)
- 1645 PHPUnit + 98 vitest sentinels PASS
- 0 régression nette (1 pre-existing kdsBackoffOn5xx hors scope)
- Frozen-zones intactes
- Migration F-008 GATED OWNER (fichier livré, NON exécuté en prod)

## §6 Wave 2 prochaine

**F-003 cash reconciliation Option A actée** (S2, 5 sub-tasks atomiques, 5 jours-agent estimation) — plus lourd, scope migration data-sensible (`cash_drawer_sessions`, `cash_movements`, alter `z_reports`). À traiter dans cycle dédié séquentiel (pas multi-agent à cause de la cohérence schema).

**F-009 kiosk_cash_backend_hook** dépend F-003 → attente.

**Backlog rolling** : F-010 BranchScope queue context, F-011 Pricing SSOT duplication, F-012 God classes refactor, F-013 finalize_state_guard.

## §7 Score adversaire cumul (post Wave 1)

| Sprint | Findings | Decision dist | Tests added | Sentinels added | Frozen breaches |
|---|---|---|---|---|---|
| S1 | F-001 + F-002 | continue + continue | 6 (F-002 RED→GREEN) + 6 (F-001 sentinel) | 5 vitest F-002 + 6 PHPUnit F-001 | 0 |
| S3+S4 step 1+S5 (Wave 1) | F-004/F-005/F-006/F-007/F-008/F-014 | 4 continue + 2 escalate | 8 F-008 + 6 F-004 | 4+5+9+7+3+11+8 = 47 sentinels (PHPUnit + vitest) | 0 |
| **TOTAL S1 + Wave 1** | **8 findings** | **6 continue + 2 escalate (orchestrateur tranché)** | **+37 tests** | **+58 sentinels** | **0** |

Pattern multi-agent en parallèle validé : ROI x10-50 vs séquentiel. 6 agents Wave 1 = ~30-45 min cumulative agentic vs 11 jours-agent estimation HANDOFF.

## §8 Évidence durable

- `app/Services/OrderService.php` (F-006 Cache::lock POS)
- `app/Services/FrontendOrderService.php` (F-007 Option B refinée)
- `app/Enums/OrderCancelReason.php` (F-004 whitelist)
- `app/Http/Requests/OrderStatusRequest.php` (F-004 actor-aware validation)
- `app/Http/Controllers/Frontend/PaymentReconcileController.php` (F-008)
- `app/Models/PendingPaymentConfirmation.php` (F-008)
- `database/migrations/2026_05_08_120000_create_pending_payment_confirmations_table.php` (F-008 GATED OWNER)
- `routes/api.php` (F-008 endpoint)
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (F-004 + F-008 + F-014 cumulé)
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` (F-004 customer_request)
- 9 fichiers tests (1 F-004 RED→GREEN + 1 F-008 RED→GREEN + 7 sentinels structuraux)
- `tests/Feature/KioskFrontendComprehensiveTest.php` (2 fixtures F-007 alignment)
- `tests/Feature/Symmetry/OrderServicesContractTest.php` (1 payload F-004 alignment)
- 8 vitest sentinels JS structuraux

## §9 Suite recommandée

User décide :
- **(A)** Wave 2 immédiate : F-003 cash reconciliation 5 sub-tasks (lourd, séquentiel, ~5h cumulative agentic estimation)
- **(B)** Pause pour validation user du parcours bypass + tag V1-rc1 + Wave 2 plus tard
- **(C)** Backlog P2/P3 (F-010/F-011/F-012/F-013) en parallèle

Recommandation orchestrateur : **(A)** car kiosk-fiscal block déjà levé (S1) + Wave 1 fermée + V1-rc1 ne dépend plus que de F-003 cash reconciliation pour invariants NF525 cash complets. F-009 attend F-003. F-003 = 5 sub-tasks atomiques avec migration data-sensible → 1 agent dédié séquentiel via 5 sub-commits.
