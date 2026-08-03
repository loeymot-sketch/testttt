L'écriture a été refusée — je restitue donc le rapport intégral directement dans le chat, comme le prompt l'a demandé en premier (« réponse unique dense et exploitable »).

---

# CLAUDE SUPER MASTER PLAN REVIEW — CAISSE V1

**Date** : 2026-04-25
**Auteur** : Claude terminal Opus 4.7 — orchestrateur FoodKing, posture adversariale
**Périmètre** : audit `plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md`, 7 gates humains pendants, 30+ findings P0/P1 consolidés (caisse POS + kiosk + KDS), production d'un plan-de-plans exécutable.
**Statut** : aucune modification de code produit, aucun gate auto-approuvé.

---

## A — Verdict sur le plan actuel

### A.1 Solide
1. Séquencement P0→sentinels→security→contracts→KDS/kiosk→legacy/tests→go/no-go cohérent avec l'arbitrage `MERGE_WITH_CLAUDE_PHASE_ORDER` (`reports/audit/CODEX_CLAUDE_MEGA_PLAN_COMPARISON_CAISSE_V1_2026-04-25.md:9`).
2. 7 gates humains explicitement listés en Phase 0 (`docs/gates/GATE_FROZEN_ZONES_CAISSE_V1`, `GATE_FISCAL_KIOSK_V1`, `GATE_PAYMENT_LEDGER_V1`, `GATE_KDS_BUMP_V1`, `GATE_SCHEMA_MIGRATIONS_V1`, `GATE_PAYMENT_PROP_MUTATION_2026-04-26`, `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20`).
3. 16 sentinelles fail-first énoncées (l.682-699).
4. Couverture des 17 findings P0 revenue/fraud tracée Phase 2/3/4.
5. Frozen-zones doctrine réaffirmée (Phase 0 output `docs/gates/GATE_FROZEN_ZONES_CAISSE_V1.md`).
6. Sources audit référencées (MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP, AUDIT_TOTAL_SYSTEME, AUDIT_COMPLET_BORNE_KIOSK).

### A.2 Insuffisant
1. **Plan linéaire 9 phases au lieu d'un plan-de-plans DAG** (brief Codex l.56-97 exige PLAN-IDs + dépendances + parallèles).
2. **Allowlist/denylist par tâche absent** (« probable files » l.80 insuffisant).
3. **Rollback / canary / feature flags** non spécifiés (aucun nom de flag, aucun prédicat de rollback).
4. **Runtime/ops/observabilité sous-spécifiés** : queue driver, retry, dead-letter, workers, scheduler, broadcast scoping, cache TTL, alerting (gap `GAP-RUNTIME-*` du `MEGA_PLAN_READINESS_GAP_ANALYSIS:124-135`).
5. **Fiscal Z/refund/void/HMAC** insuffisamment détaillés (brief l.44).
6. **Option A/B paiement non dupliqué symétriquement** (brief l.65-66 exige PLAN-04A vs PLAN-04B distincts ; plan actuel agrège).
7. **Hardware lab proof trop tardive (Phase 8)** ; aucune réservation Phase 0 (brief l.50).
8. **Sentinelles : état rouge/vert attendu non documenté** (l.262 vague).
9. **Quote security minimaliste** : TTL non chiffré, idempotency consume key non spécifié, replay test absent.
10. **Legacy import bypass** : pas de pattern grep CI guard / pas de lint AST contre `pos-wizard.js` ou `kiosk_implementation/`.
11. **Branch-isolation surface incomplète** (list/show traités mais TransactionService, KDS list, fiscal Z, exports, OSS branch=0 non centralisés).
12. **Memory discipline Graphiti MISSING_IN_SESSION** (l.49-53), fallback `memory/INDEX.md` non défini.
13. **Évidence/preuve par tâche non normalisée**.
14. **Aucune matrice findings → tasks → tests → gates** unique.
15. **Aucun plan post-launch monitoring** (anomaly detection business/fiscal).

### A.3 Dangereux
1. **Cleanup stale pending vs payment-confirm tardif** (FIND-12 / `MEGA_RAPPORT_FINAL_DISPUTE` C-P0-008) sans rollback de TPE déjà encaissé → double charge possible.
2. **Option B paiement restreint** sans encadrement opérationnel : si CB activée par erreur, aucune barrière backend.
3. **KDS bump strict server-authority sans flag de rollout nommé** : risque blocage écrans cuisine.
4. **Migrations DB sans dry-run/rehearsal/backup** documentés.
5. **Hardware go-live non garanti** sans procédure fallback.
6. **Frozen zones non scope-précisées** → drift agent.

### A.4 Manquant pour exécution réelle
- Plan-de-plans DAG (PLAN-IDs, dépendances, gates bloquants, owners)
- Allowlist/denylist par TASK_ID
- Audit-prompt template par sous-plan
- Rollback / canary / feature-flag par changement risqué
- Matrice tests/red-team unifiée (§ H)
- Runbook ops/runtime/migration/observabilité (§ I)
- Workflow agents intégré au cycle FoodKing (§ J)
- Master checklist exécutable (§ K)

### Verdict synthétique

**`CLAUDE_PLAN_AUDIT_VERDICT: NEEDS_MAJOR_REPLAN`**

Le plan actuel est un *index-of-phases*, pas un plan d'exécution. Il identifie correctement la cible mais ne fournit pas la traçabilité, la décomposition, ni les garanties opérationnelles requises pour 8-10 semaines avec rotation Codex+Cursor+Claude.

---

## B — Les 12+ améliorations obligatoires

| # | Amélioration | Pourquoi | Où dans plan actuel | Nouveau plan/subplan requis | Risque si ignoré |
|---|---|---|---|---|---|
| 1 | Convertir 9 phases linéaires en plan-de-plans DAG | Brief Codex l.56-97 ; parallélisation no-code/tests/audit/hardware | Phases l.38 | PLAN-00 master DAG | Goulot Phase 0, perte 1-2 sem |
| 2 | Splitter PLAN-04A (ledger) / PLAN-04B (restrict) sous gate ledger | Brief l.65-66 ; gate décide branche | Phase 4 fusionne | PLAN-04A + PLAN-04B + PLAN-04-DECISION | Décision floue, scope creep |
| 3 | PLAN dédié rollback/canary/feature-flags | Brief l.42 ; risques fiscal/payment/KDS | Absent | PLAN-15 ROLLOUT_AND_ROLLBACK | Big-bang go-live, perte client |
| 4 | Plan ops/runtime/migration/observabilité dédié | Brief l.43 ; gap GAP-RUNTIME-* | Phase 8 vague | PLAN-14 OPS + PLAN-13 MIGRATION | Échec silencieux events, pertes batch |
| 5 | Allowlist/denylist + audit-prompt par TASK_ID | Brief l.80 | « probable files » | Annexe per-plan + `templates/AUDIT_PROMPT.md` | Drift, frozen-zone violation |
| 6 | Hardware lab-readiness PLAN dédié, démarré Phase 0 | Brief l.50 | Phase 8 « hardware smoke » | PLAN-16 HARDWARE_QUALIFICATION | Go-live invalidé tardivement |
| 7 | Branch isolation threat-model centralisé (7+ surfaces) | F-001..F-005 + AUDIT_TOTAL ; plan ne liste que list/show | Phase 2b restreint | PLAN-09 BRANCH_ISOLATION_HARDENING | Fuite multi-tenant, RGPD |
| 8 | Sentinelles : `expected_state` + `evidence_artifact` par sentinelle | l.262 vague | Phase 1 liste 16 sans état | PLAN-02 SENTINELS+EVIDENCE | Sentinelle vide trompe l'agent |
| 9 | Fiscal Z/refund/void/HMAC plan dédié + NF525 | Brief l.44 | Phase 6 fiscal kiosk seul | PLAN-08 FISCAL_Z_RECONCILIATION | Amende NF525, audit invalide |
| 10 | Legacy bypass guards CI : grep + AST + bundle scan + dynamic include test | Brief l.48 | Phase 7 grep textuel | PLAN-12 LEGACY_CUTOVER_GUARDS | Réintroduction dette |
| 11 | Memory discipline Graphiti + fallback `memory/INDEX.md` | l.49-53 missing | Aucun | PLAN-19 MEMORY_DISCIPLINE | Perte continuité |
| 12 | Matrice traceability finding → task → test → gate exportable | Brief « traceability complete » | Implicite | PLAN-01 TRACEABILITY_MATRIX | Findings orphelins, audit incomplet |
| 13 | Post-launch monitoring + anomaly alerting | Production-grade exigence | Absent | PLAN-22 POST_LAUNCH_OBSERVABILITY | Fraude/incident invisible |
| 14 | Quote security spec formelle (HMAC, TTL, idempotency, replay) | P0-12 + l.354 vague | Phase 3 textuel | Sub-plan PLAN-05.QUOTE_SPEC | Replay/tamper réussit |
| 15 | OS/FOS symmetry contract test | F-007 + invariant FoodKing | Implicite | Sub-plan PLAN-10.OS_FOS_SYMMETRY | Désync surfaces critiques |

---

## C — Findings non mappés ou trop faiblement mappés

| Source | Finding / sujet | Risque | Mappé ? | Action corrective | Plan cible |
|---|---|---|---|---|---|
| MEGA_RAPPORT_FINAL C-P0-010 | Queue number collision (`OrderService:828-854`) | Duplicates OSS/KDS | Mention vague Phase 2 | Sub-task explicite + test concurrency | PLAN-09 / PLAN-13 |
| MEGA_RAPPORT_FINAL C-P0-011 | No-op cashback / status side effects | Double remboursement | Phase 2 non détaillé | Feature `OrderStatusNoopSideEffectsTest` | PLAN-06 |
| MEGA_RAPPORT_FINAL C-P0-012 | POS cash via endpoint KDS (`/admin/kds-order/change-status`) | Couplage paiement/fulfilment | Phase 2 partiel | Route POS dédiée + suppression usage KDS | PLAN-06 / PLAN-10 |
| MEGA_RAPPORT_FINAL C-P0-014 | `/payment/{order}/pay` exposition order id | Énumération externe | Phase 4 mention | Signed intent + URL hash | PLAN-04A/B |
| MEGA_RAPPORT_FINAL C-P0-015 | Stripe cents bug (×100) | Montant facturé faux | Phase 4 conditionnel | Gate Stripe-active + test money | PLAN-17 |
| AUDIT_TOTAL F-004 | Idempotency POS catch non branche-scope | Recovery mauvaise branche | Plan vague | Test feature + namespace branch | PLAN-09 |
| GAP-OFFLINE-SCOPE | Offline POS/kiosk autorisé créer quoi ? | Faux paiement / orphan | Implicite Phase 6 | Gate `GATE_OFFLINE_SCOPE_V1` | PLAN-11 |
| GAP-WEB-PAYMENT-SCOPE | Stripe/web/table V1 actif/off ? | Endpoints publics fragiles | Aucun | Gate `GATE_WEB_PAYMENT_SCOPE_V1` | PLAN-17 |
| MASTER_REVIEW POS/KDS FIND-04 | `kioskFormatPrice.js` hardcode `EUR`/`fr-FR` | i18n cassé | Phase 6 | Sub-task locale-aware | PLAN-21 |
| MASTER_REVIEW FIND-05 | Bengali KDS i18n vide (27 clés) | UX dégradée | Phase 8 | Sub-task `bn.json` | PLAN-21 |
| MASTER_REVIEW FIND-06 | Focus trap POS modals | A11y / WCAG | Phase 8 | Sub-task focustrap | PLAN-21 |
| MASTER_REVIEW FIND-07 | Swiper KDS `dir="ltr"` hardcode | RTL arabe cassé | Phase 8 | Sub-task swiper dir | PLAN-21 |
| MASTER_REVIEW FIND-10 | `sync_metrics` croissance non bornée | DB bloat | Phase 8 vague | Scheduler purge + migration TTL | PLAN-13 / PLAN-14 |
| MASTER_REVIEW FIND-11 | `pos_parked_orders.expires_at` absent | Orphans | Phase 8 mention | Migration + scheduler | PLAN-13 |
| MASTER_REVIEW FIND-13 | Status `16` hardcoded kiosk | Drift enum | Phase 6 | Enum import + lint extend | PLAN-09 |
| KIOSK-DEEP-014 | Fiscal kiosk Z 3 options A/B/C | Trou fiscal NF525 | Gate Phase 0 | Plan dédié + Z scenarios | PLAN-08 |
| AUDIT_TOTAL F-010 | `/payment/{order}/pay` legacy public | Énumération | Phase 4 mention | Signed intent + middleware | PLAN-04A/B |
| GAP-AVAILABILITY-RELEASE | AvailabilityService cancel/refund partial fix non validé | Auto-86 bloqué | Pas mappé | Code review + tests | PLAN-09 / PLAN-06 |
| MASTER_REVIEW FIND-08/13 | Tests Feature POS/KDS minces (3 + 2 fichiers) | Couverture insuffisante | Phase 8 | Plan dédié coverage matrix | PLAN-18 |
| KIOSK-DEEP-001/002 | Menu/pricing legacy autoritative front | Catalogue divergent | Phase 6 | Suppression endpoints legacy + lint | PLAN-12 |
| GAP-LEGACY-QUARANTINE | `kiosk_implementation/**`, `borne (Remix)/**`, `pos-wizard.js` | Dette réintroduite | Phase 7 grep | Bandeau ARCHIVE + lint AST + bundle scan | PLAN-12 |
| AUDIT_TOTAL F-011 | POS subtotal forgeable (`PosOrderRequest:132-149`) | Bypass remise | Phase 2 partial | Sub-task `PricingService` autoritaire | PLAN-05 |

---

## D — Plan de plans final (22 plans)

| PLAN-ID | Nom | Objectif | Dépendances | Gates | Tests | Owner | Audit prompt | Sortie |
|---|---|---|---|---|---|---|---|---|
| PLAN-00 | MASTER_DAG_AND_GOVERNANCE | Cadre exécution global, RACI, calendrier | — | — | — | Claude | `audit-prompt-governance.md` | `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` |
| PLAN-01 | GOVERNANCE_TRACEABILITY_MATRIX | Matrice finding→task→test→gate | PLAN-00 | — | `scripts/check-traceability.sh` | Claude | `audit-prompt-trace.md` | `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1.md` |
| PLAN-02 | SENTINELS_AND_EVIDENCE_RIG | 18 sentinelles fail-first avec état attendu | PLAN-00 | — | Feature/Vitest/Playwright | QA + Codex | `audit-prompt-sentinels.md` | Suite tests rouges |
| PLAN-03 | HUMAN_GATES_RESOLUTION | 10 gates signés humain | PLAN-00, PLAN-02 | tous gates | — | Humain | — | 10 `docs/gates/GATE_*.md` |
| PLAN-04A | PAYMENT_LEDGER_FULL (si A) | Ledger + state machine + refund/split/void | PLAN-03 (gate A) | `LEDGER_V1=A`, `SCHEMA` | State machine, idempotency | BE | `audit-prompt-payment-ledger.md` | Migration + service + tests |
| PLAN-04B | PAYMENT_RESTRICT_PILOT (si B) | Restreindre méthodes, désactiver CB/Stripe | PLAN-03 (gate B) | `LEDGER_V1=B` | Refus 422/403 + audit | BE | `audit-prompt-payment-restrict.md` | Service + tests |
| PLAN-05 | ORDER_QUOTE_BACKEND_SSOT | Quote-first + signed intent + TTL + idempotency | PLAN-02, PLAN-03 | `SCHEMA` | Expir/tamper/replay | BE | `audit-prompt-quote.md` | OrderQuoteService + tests |
| PLAN-06 | POS_REVENUE_GUARDS | payment-confirm + no-op idem + cash route + cleanup race | PLAN-02, PLAN-03 | `FROZEN`, `PROP_MUTATION` | Feature x6, Vitest x3 | BE+FE | `audit-prompt-pos-guards.md` | OrderController + tests |
| PLAN-07 | KDS_RELEASE_AND_TRANSITIONS | KitchenRelease, whitelist, expected_status, pagination | PLAN-02, PLAN-03 | `KDS_BUMP` | Feature + concurrency 409 + Playwright | BE+FE | `audit-prompt-kds.md` | OrderStatusRequest + tests |
| PLAN-08 | FISCAL_Z_RECONCILIATION | Routing kiosk Z, refund pré/post-Z, HMAC, NF525 | PLAN-03 | `FISCAL`, `SCHEMA` | Z agg, refund/void scenarios | BE | `audit-prompt-fiscal.md` | ZReportService + tests |
| PLAN-09 | BRANCH_ISOLATION_HARDENING | 7 surfaces : list/show/transaction/KDS/fiscal/export/OSS | PLAN-02, PLAN-03 | `FROZEN` | Feature cross-branch x7 | BE | `audit-prompt-branch.md` | Patches + tests |
| PLAN-10 | OS_FOS_SYMMETRY_AND_CONTRACTS | OrderService / FrontendOrderService parité | PLAN-06, PLAN-09 | — | Contract tests | BE | `audit-prompt-symmetry.md` | Tests + doc |
| PLAN-11 | KIOSK_RUNTIME_OFFLINE_POLICY | Enum, menu unique, offline ID prefix, CB/TR offline, machine binding | PLAN-03 | `OFFLINE_SCOPE`(new), `FISCAL` | Vitest + Playwright offline replay | FE+BE | `audit-prompt-kiosk-runtime.md` | Composants + tests |
| PLAN-12 | LEGACY_CUTOVER_AND_GUARDS | ARCHIVE, lint AST, bundle scan, dynamic include test | PLAN-00 | — | Static scan + CI guard | BE+DevOps | `audit-prompt-legacy.md` | CI rules + tests |
| PLAN-13 | MIGRATION_DATA_SAFETY | Dry-run, rehearsal, backup, parked TTL, queue unique index | PLAN-03 | `SCHEMA` | Migration + smoke | BE+DBA | `audit-prompt-migration.md` | Migrations + runbook |
| PLAN-14 | OPS_RUNTIME_OBSERVABILITY | Queue/scheduler/workers/broadcast/cache/alerting | PLAN-13 | — | Preflight + after-commit + outbox | DevOps | `audit-prompt-ops.md` | Runbook + dashboards |
| PLAN-15 | ROLLOUT_CANARY_ROLLBACK | Flags + canary + rollback predicates | PLAN-04*, PLAN-08 | — | Canary smoke + drill | DevOps + BE | `audit-prompt-rollout.md` | Procédure + flags |
| PLAN-16 | HARDWARE_QUALIFICATION | TPE, ESC/POS, drawer, kiosk hardware | PLAN-00 | — | Hardware checklist | Ops + Tech-shop | `audit-prompt-hardware.md` | Hardware report signé |
| PLAN-17 | STRIPE_AND_WEB_PAYMENT_GATE | Stripe cents fix, web/table policy | PLAN-03 | `WEB_PAYMENT_SCOPE`(new), `STRIPE_CENTS`(new) | Money + integration | BE | `audit-prompt-stripe.md` | Service + tests |
| PLAN-18 | TEST_ARCHITECTURE_AND_COVERAGE | Coverage POS/KDS/Kiosk matrix | PLAN-02 | — | Coverage report | QA | `audit-prompt-tests.md` | Suite + matrix |
| PLAN-19 | MEMORY_DISCIPLINE_GRAPHITI_FALLBACK | Graphiti read Phase 0, ingest CLOSE, fallback `memory/INDEX.md` | PLAN-00 | — | Memory verify | Claude | `audit-prompt-memory.md` | Procédure + JSONL |
| PLAN-20 | DOCUMENTATION_AND_RUNBOOK | ORDER_FLOW, BUSINESS_RULES, AUTHZ, runbooks | PLAN-04..PLAN-08 | — | — | Tech writer | `audit-prompt-docs.md` | Docs propres |
| PLAN-21 | UX_FINITIONS_POS_KDS_KIOSK | discount v-model, RTL, i18n bn, focustrap, EUR locale | PLAN-00 | `PROP_MUTATION` (LOT-6) | Vitest + Playwright a11y | FE | `audit-prompt-ux.md` | Composants + tests |
| PLAN-22 | POST_LAUNCH_OBSERVABILITY_AND_ANOMALY | KPI LCP, fraud anomaly, fiscal anomaly, post-mortem | PLAN-14, PLAN-15 | — | Synthetic + alerting | DevOps + QA | `audit-prompt-postlaunch.md` | Dashboards + on-call |

---

## E — Décomposition détaillée (12 plans P0/P1)

### PLAN-00 — MASTER_DAG_AND_GOVERNANCE
- **Objectif** : produire `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` (DAG, owners, calendrier, RACI), instaurer cadence run-cycle/audit/gate logs.
- **Préconditions** : ce rapport signé.
- **Off-limits** : code produit (toutes zones).
- **Tâches** : (1) importer findings § C → matrice ; (2) DAG mermaid + tableau ; (3) owners par plan ; (4) cadence (daily orchestrateur, weekly steering).
- **Tests** : revue humain TL.
- **Preuves** : DAG signé, matrice CSV.
- **Audit prompt** : « cohérence DAG vs findings vs gates vs invariants FoodKing vs cycle officiel ».
- **PASS** : DAG complet, ≥35 findings tracés, owners alloués. **REWORK** : un seul gate ou owner manquant.

### PLAN-01 — GOVERNANCE_TRACEABILITY_MATRIX
- **Objectif** : matrice `Source | Finding-ID | Sévérité | Plan-ID | TASK_ID | Sentinelle | Test type | Gate | Owner | Status`.
- **Tâches** : (1) importer findings (5 sources) ; (2) mapper PLAN-XX et TASK_ID ; (3) identifier orphelins.
- **Tests** : `scripts/check-traceability.sh` assert 0 finding sans PLAN.
- **PASS** : 0 orphelin, 100% sévérités. **REWORK** : ≥1 P0 sans plan.

### PLAN-02 — SENTINELS_AND_EVIDENCE_RIG
- **Objectif** : 18 sentinelles fail-first avec `expected_state`, `evidence_artifact`, `commande_run`.
- **Sentinelles minimales** :
  - `PaymentConfirmAbilitySentinelTest` (P0)
  - `KdsTransitionWhitelistSentinelTest` (P0)
  - `OrderListBranchExactnessSentinelTest` (P0)
  - `OrderShowBranchGuardSentinelTest` (P0)
  - `KioskPromoPreviewCheckoutParitySentinelTest` (Vitest)
  - `OrderStatusNoopSideEffectsSentinelTest` (P0)
  - `PosCashEndpointSentinelTest` (intégration)
  - `CleanupVsConfirmRaceSentinelTest` (concurrency)
  - `PosDiscountReasonBindingSentinelTest` (Vitest)
  - `PaymentComponentPropMutationSentinelTest` (Vitest)
  - `AvailabilityReleaseIdempotencySentinelTest`
  - `ItemAvailabilityChangedPayloadSentinelTest`
  - `PosTotalServerAuthoritativeSentinelTest`
  - `PosSubtotalForgerySentinelTest`
  - `QueueNumberUniquenessSentinelTest` (concurrency)
  - `KioskOfflineIdPrefixSentinelTest` (Vitest)
  - `KioskCbTrOfflineRefusedSentinelTest` (Playwright)
  - `OrderStatusEnumKioskHardcodeSentinelTest` (lint static)
- **Preuves** : `reports/sentinels/CAISSE_V1_BASELINE_RUN_<date>.log`.
- **Audit prompt** : « pour chaque sentinelle, vérifier rouge pour la raison documentée, verra vert exclusivement après le patch ciblé ».

### PLAN-03 — HUMAN_GATES_RESOLUTION (CHEMIN CRITIQUE)
- **Objectif** : 10 gates signés (TL+BE+QA+UX+Ops+Product). Sans cela PLAN-04*, PLAN-06, PLAN-07, PLAN-08, PLAN-11 bloqués.
- **Tâches** : (1) dossier décision par gate ; (2) sessions humaines (2-3 max regroupées) ; (3) signature + verdict daté.
- **PASS** : 10/10. **REWORK** : ≥1 sans verdict.

### PLAN-04A / PLAN-04B — PAYMENT
- **Branche A — LEDGER_FULL** (si gate=A)
  - **Fichiers prob.** : `app/Models/PaymentLedger.php` (nouveau), `app/Services/PaymentLedgerService.php` (nouveau), migration `*payment_ledger*`, `app/Http/Controllers/Frontend/OrderController.php` (paymentConfirm refacto), `app/Services/Order/PaymentService.php`.
  - **Off-limits** : `OrderService::list/show` (PLAN-09).
  - **Tâches** : (1) migration ; (2) state machine `pending|authorized|captured|refunded|voided|failed` ; (3) refacto paymentConfirm ; (4) idempotency-key ; (5) audit log immuable ; (6) Stripe cents fix (si gate stripe=on).
  - **Tests** : `PaymentLedgerStateMachineTest`, `IdempotencyTest`, `RefundTest`, `VoidTest`, `StripeCentsConversionTest`.
  - **Rollback** : flag `payment_ledger_v1=off` (max 14j).
- **Branche B — RESTRICT_PILOT** (si gate=B)
  - **Fichiers prob.** : `PaymentService.php`, `PaymentMethodRequest.php`, route guard.
  - **Tâches** : (1) flag `payment_methods_pilot=cash_only|cash_card_supported` ; (2) refus middleware ; (3) audit log attempts.
  - **Tests** : `PaymentMethodRestrictedTest`, `PaymentMethodAttemptAuditTest`.
  - **Rollback** : flag → off.

### PLAN-05 — ORDER_QUOTE_BACKEND_SSOT
- **Spec** : `OrderQuoteService` autoritaire ; signed intent HMAC-SHA256 ; TTL 60s ; idempotency-key consume ; replay refusé.
- **Fichiers prob.** : `app/Services/Order/OrderQuoteService.php` (nouveau), migration `order_quotes`, `app/Http/Controllers/Admin/PosController.php`, kiosk equivalent.
- **Tâches** : (1) service ; (2) signed intent (HMAC + secret par device) ; (3) TTL + cron purge ; (4) idempotency table ; (5) refacto POS PaymentComponent quote-first ; (6) refacto kiosk equivalent ; (7) suppression total-local front.
- **Tests** : `QuoteExpirationTest`, `QuoteTamperTest`, `QuoteReplayIdempotencyTest`, `QuoteCurrencyOriginTest`, `QuoteDiscountAuthoritativeTest`.
- **Rollback** : flag `quote_v1=off` (max 7j).

### PLAN-06 — POS_REVENUE_GUARDS
- **Fichiers prob.** : `Frontend/OrderController::paymentConfirm`, `OrderService::changeStatus, changePaymentStatus`, `PaymentService::cashBack`, `routes/api.php` (nouveau `pos/collect-kiosk-cash`), `Pos/PaymentComponent.vue`, `CleanupStalePendingKioskOrders.php`.
- **Off-limits** : KDS (PLAN-07), branch isolation (PLAN-09).
- **Tâches** :
  1. Ability middleware `kiosk-payment-confirm` (device_id auth + machine resolver)
  2. Validation order_type + payment_method + branch + state
  3. No-op early guard + idempotence transaction_no
  4. Route POS dédiée + déprécation usage KDS endpoint
  5. Cleanup race : si confirm tardif → 422 + audit log + flag réconciliation TPE
  6. PaymentComponent prop mutation (Option A emit ou B copy selon gate)
- **Tests** : `PaymentConfirmAbilityTest`, `PaymentConfirmMachineResolverTest`, `OrderStatusNoopSideEffectsTest`, `PaymentNoopIdempotencyTest`, `CleanupVsConfirmRaceTest`, `PosCollectKioskCashRouteTest`, `PaymentComponentNoMutationVitestTest`.
- **Rollback** : flag `pos_revenue_guards=off` (max 7j).

### PLAN-07 — KDS_RELEASE_AND_TRANSITIONS
- **Fichiers prob.** : `OrderStateMachine.php`, `KdsOrderStatusRequest.php` (nouveau), `KitchenDisplaySystemOrderService.php`, `KitchenDisplaySystem.vue`, `stores/kds.js`.
- **Tâches** : (1) `isReleasedToKitchen()` formel ; (2) whitelist `KdsOrderStatusRequest` (ACCEPT/PREPARING/PREPARED) ; (3) `expected_status` versioning ms ; (4) pagination 200 + alerte overflow ; (5) test E2E multi-screen.
- **Tests** : `KdsTransitionWhitelistTest`, `KdsExpectedStatusConflictTest`, `KitchenReleaseRuleTest`, `KdsPaginationOverflowTest`, `KdsMultiScreenPlaywrightTest`.
- **Rollback** : flag `kds_strict_release=off`.

### PLAN-08 — FISCAL_Z_RECONCILIATION
- **Fichiers prob.** : `Fiscal/ZReportService.php`, `Fiscal/FiscalSealingService.php`, migrations fiscales, `FrontendOrderService.php` (kiosk routing).
- **Tâches** : (1) routing décidé A/B/C ; (2) Z aggregation ; (3) refund/void pré/post-Z ; (4) HMAC sceau + chaîne hash ; (5) NF525 mapping (numéros séquentiels, archive 6 ans).
- **Tests** : `ZAggregationKioskRoutingTest`, `RefundPreZTest`, `RefundPostZTest`, `VoidPreZTest`, `FiscalSealingHmacTest`, `FiscalArchiveTtlTest`.
- **Rollback** : flag `fiscal_z_v1=off` (max 24h, fiscal critique).

### PLAN-09 — BRANCH_ISOLATION_HARDENING
- **7 surfaces** : POS list, show, transaction list, KDS list, fiscal Z, export, OSS admin.
- **Fichiers prob.** : `OrderService.php:151,1330`, `TransactionService.php:23-66`, `OrderStatusScreenOrderService.php`, `ZReportService.php`, export/report controllers.
- **Tâches** : (1) `LIKE '%'.$branchId` → `=` exact + actor branch default ; (2) `OrderService::show` guard cross-branch ; (3) TransactionService branch obligatoire ; (4) OSS admin branch=0 only avec admin global, sinon 403 ; (5) test x7 ; (6) lint AST anti-pattern CI.
- **Tests** : `BranchExactness*Test` x6 + `OssAdminBranchPolicyTest`.

### PLAN-13 — MIGRATION_DATA_SAFETY
- **Migrations** : `*payment_ledger*`, `*order_quotes*`, `*pos_parked_orders_expires_at*`, `*sync_metrics_ttl*`, `*queue_number_unique_index*`.
- **Tâches** : (1) dry-run par migration ; (2) rehearsal staging full-volume ; (3) backup pré-migration ; (4) Up + Down testés ; (5) runbook par migration.
- **Tests** : `MigrationDryRunTest`, `MigrationRollbackTest`.
- **Rollback** : Down migration + restore backup.

### PLAN-14 — OPS_RUNTIME_OBSERVABILITY
- **Tâches** : (1) `bash scripts/ops-preflight-caisse-v1.sh` (queue worker count, scheduler last-run, broadcast healthcheck, cache ping, fiscal archive accessible, outbox depth) ; (2) dashboards (payment success rate, KDS latency, fiscal Z, branch leak counter, queue depth, worker errors) ; (3) alerting + on-call ; (4) outbox rescue mechanism.
- **Tests** : preflight CI smoke + chaos test queue stop/restart.

### PLAN-15 — ROLLOUT_CANARY_ROLLBACK
- **Flags** : `payment_ledger_v1`, `pos_revenue_guards`, `kds_strict_release`, `quote_v1`, `fiscal_z_v1`, `kiosk_offline_strict`.
- **Canary** : 1 branche pilot → 10% → 50% → 100%.
- **Rollback predicates** :
  - payment success rate < 95% sur 5 min → flag off + alerte
  - fiscal anomaly count > 0 → flag off immédiat + escalade
  - KDS error rate > 5% → flag off + revoir whitelist
- **Drill** : exercice rollback obligatoire avant go-live.

### PLAN-16 — HARDWARE_QUALIFICATION
- **Tâches** : (1) TPE approve/decline/timeout/cancel ; (2) ESC/POS print + failover offline ; (3) drawer open/lock/audit ; (4) kiosk hardware (touchscreen, NFC, scanner) ; (5) tablet POS (Wi-Fi/4G failover, sleep recovery).
- **Preuves** : `reports/hardware/CAISSE_V1_HARDWARE_QUALIF_<date>.md` signé.

### PLAN-22 — POST_LAUNCH_OBSERVABILITY_AND_ANOMALY
- **Tâches** : (1) KPI LCP POS/kiosk/KDS ; (2) fraud anomaly rules (payment-confirm sans ability, branch crossover, no-op double-trigger) ; (3) fiscal anomaly (Z mismatch, refund sans audit, sceau invalid) ; (4) cadence post-mortem J+1, J+7, J+30 ; (5) hotfix runbook.

> PLAN-10, PLAN-11, PLAN-12, PLAN-17, PLAN-18, PLAN-19, PLAN-20, PLAN-21 décomposés en synthèse en § D ; détail livrable dans PLAN-00.

---

## F — Graphe de dépendances

```
PLAN-00 (governance)
  ├─► PLAN-01 (traceability) ─► PLAN-02 (sentinels)
  ├─► PLAN-19 (memory)
  ├─► PLAN-12 (legacy guards)         [parallèle]
  ├─► PLAN-16 (hardware lab)          [parallèle, démarre Phase 0]
  ├─► PLAN-18 (test architecture)     [parallèle]
  └─► PLAN-03 (gates humains) ◄─ requiert PLAN-02
        ├─► PLAN-04A  XOR  PLAN-04B    ←  GATE_PAYMENT_LEDGER_V1
        ├─► PLAN-05 (quote SSOT)        ←  GATE_SCHEMA_MIGRATIONS_V1
        ├─► PLAN-06 (POS guards)        ←  GATE_FROZEN_ZONES + GATE_PROP_MUTATION
        ├─► PLAN-07 (KDS release)       ←  GATE_KDS_BUMP_V1
        ├─► PLAN-08 (fiscal Z)          ←  GATE_FISCAL_KIOSK_V1
        ├─► PLAN-09 (branch isolation)  ←  GATE_FROZEN_ZONES
        ├─► PLAN-11 (kiosk runtime)     ←  GATE_OFFLINE_SCOPE + GATE_FISCAL
        ├─► PLAN-13 (migrations)        ←  GATE_SCHEMA_MIGRATIONS
        └─► PLAN-17 (stripe/web)        ←  GATE_STRIPE_CENTS + GATE_WEB_PAYMENT_SCOPE

PLAN-04* + PLAN-05 + PLAN-06 + PLAN-09 ─► PLAN-10 (OS/FOS symmetry)
PLAN-13 ─► PLAN-14 (ops observability)
PLAN-04*, PLAN-08 stables ─► PLAN-15 (rollout/canary/rollback)
PLAN-04..PLAN-08 ─► PLAN-20 (documentation/runbooks)
PLAN-21 (UX finitions LOT-0) parallèle dès Phase 0 (sans gate)
PLAN-14 + PLAN-15 + go-live ─► PLAN-22 (post-launch)
```

### Travail parallélisable AVANT gates (no-code / low-risk)
- PLAN-01 traceability (no code)
- PLAN-02 sentinelles fail-first (test-only)
- PLAN-12 legacy guards CI
- PLAN-16 hardware lab readiness
- PLAN-18 test architecture skeleton
- PLAN-19 memory discipline procédure
- PLAN-20 documentation skeleton
- PLAN-21 UX finitions LOT-0 (discount v-model, RTL — sans gate selon `MASTER_REVIEW_POS_KDS_FINITIONS_BRIEF` § handoff)
- Préparation des dossiers décision pour gates humains (PLAN-03 input)

---

## G — Matrice gates

| Gate | Décision | Options | Recommandation Claude | Plans bloqués | Travail possible avant | Evidence requise |
|---|---|---|---|---|---|---|
| `GATE_FROZEN_ZONES_CAISSE_V1` | Quelles zones dégeler ? | A: open, B: refuse, C: partial method | **C** (allowlist scopée 7 surfaces P0) | PLAN-06, PLAN-09, PLAN-04* | PLAN-01, PLAN-02 | Liste exact méthodes + impact + audit |
| `GATE_FISCAL_KIOSK_V1` | Kiosk fiscal V1 ? | A: kiosk Z direct, B: POS finalize, C: bloquer paid-kiosk V1 | **C** si pas de Z auditable, sinon **B** | PLAN-08, PLAN-11 | PLAN-02 sentinelle Z agg | Mapping NF525 + politique refund pré/post-Z |
| `GATE_PAYMENT_LEDGER_V1` | Ledger ou pilote ? | A: ledger full, B: pilote restreint | **B** (pilote 1 branche) sauf si BE bandwidth ≥ 4 sem | PLAN-04A vs PLAN-04B | PLAN-02 | Bandwidth BE + roadmap V2 ledger |
| `GATE_KDS_BUMP_V1` | Authorité bump ? | A: local single-screen, B: server expected_status | **B** + flag `kds_strict_release` | PLAN-07 | PLAN-02 | Test multi-écran + plan rollback |
| `GATE_SCHEMA_MIGRATIONS_V1` | Migrations ? | A: toutes, B: subset, C: none | **A** avec rehearsal staging + backup | PLAN-04*, PLAN-05, PLAN-08, PLAN-13 | PLAN-13 dry-run | Rehearsal log + DBA approve |
| `GATE_PAYMENT_PROP_MUTATION_2026-04-26` | PaymentComponent fix ? | A: emit + parent, B: copy data | **A** (idiomatic Vue) | PLAN-06 sub-task, PLAN-21 LOT-6 | Vitest sentinelle | UX validation + dev review |
| `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` | P0 cycles antérieurs signés ? | A: all, B: subset, C: reverify | **A** si évidence dispo, sinon **C** | PLAN-06, PLAN-09 | Code review historique | Liste cycles + signoff |
| **NEW** `GATE_OFFLINE_SCOPE_V1` | Offline scope V1 | A: cash-only, B: cash+card avec ledger queue, C: pas d'offline V1 | **A** (cash-only, refus CB/TR par défaut) | PLAN-11 | Sentinelle offline-id-prefix | Dossier CB risk + politique |
| **NEW** `GATE_WEB_PAYMENT_SCOPE_V1` | Stripe/web/table V1 | A: actif, B: feature-flag off | **B** (off V1, livraison V2) | PLAN-17 | — | Bandwidth + risque endpoints publics |
| **NEW** `GATE_STRIPE_CENTS_ACTIVE` | Stripe actif → fix cents prio | A: actif → fix cents P0, B: off V1 | dépend gate web-payment | PLAN-17 | — | Décision web-payment d'abord |

---

## H — Matrice tests / red-team

| Test | Type | Surface | Scénario | Commande probable | Plan | Bloquant |
|---|---|---|---|---|---|---|
| PaymentConfirmAbilityTest | PHP Feature | Backend | non-kiosk Sanctum → 403 | `php artisan test --filter=PaymentConfirmAbility` | PLAN-06 | OUI |
| KdsTransitionWhitelistTest | PHP Feature | KDS | Chef PREPARING→CANCELED → 422 | idem | PLAN-07 | OUI |
| OrderListBranchExactnessTest | PHP Feature | Backend | branch=1 ne voit pas branch=10 | idem | PLAN-09 | OUI |
| OrderShowBranchGuardTest | PHP Feature | Backend | GET `/order/<id>` autre branche → 403 | idem | PLAN-09 | OUI |
| TransactionBranchExactnessTest | PHP Feature | Backend | TransactionService::list cross-branch | idem | PLAN-09 | OUI |
| FiscalZBranchExactnessTest | PHP Feature | Fiscal | Z branche A inclut pas branche B | idem | PLAN-09 | OUI |
| OrderStatusNoopSideEffectsTest | PHP Feature | Backend | double cancel → un seul cashback | idem | PLAN-06 | OUI |
| CleanupVsConfirmRaceTest | PHP Feature concurrency | Backend | cleanup REJECTED, confirm tardif → 422 + audit | idem | PLAN-06 | OUI |
| QuoteExpirationTest | PHP Feature | Backend | quote > TTL → 410 | idem | PLAN-05 | OUI |
| QuoteTamperTest | PHP Feature | Backend | hash modifié → 401 | idem | PLAN-05 | OUI |
| QuoteReplayIdempotencyTest | PHP Feature | Backend | consume 2x → 409 idempotent | idem | PLAN-05 | OUI |
| QueueNumberUniquenessTest | PHP Feature concurrency | Backend | 100 commandes simultanées → 100 numbers uniques | idem | PLAN-09 | OUI |
| PaymentLedgerStateMachineTest | PHP Feature | Backend (si A) | transitions valid/invalid | idem | PLAN-04A | OUI |
| PaymentMethodRestrictedTest | PHP Feature | Backend (si B) | CB submit → 422 + audit | idem | PLAN-04B | OUI |
| ZAggregationKioskRoutingTest | PHP Feature | Fiscal | kiosk PAID → routage selon décision | idem | PLAN-08 | OUI |
| RefundPreZTest / RefundPostZTest | PHP Feature | Fiscal | refund avant/après Z | idem | PLAN-08 | OUI |
| FiscalSealingHmacTest | PHP Unit | Fiscal | sceau HMAC + chaîne hash | idem | PLAN-08 | OUI |
| KdsExpectedStatusConflictTest | PHP Feature concurrency | KDS | 2 chefs même bump → 409 | idem | PLAN-07 | OUI |
| PosCollectKioskCashRouteTest | PHP Feature | POS | route dédiée OK + KDS endpoint refus | idem | PLAN-06 | OUI |
| PosDiscountReasonBindingTest | Vitest | POS FE | v-model binding | `npm run test:unit -- discountReason` | PLAN-21 LOT-0 | NON |
| PaymentComponentNoMutationTest | Vitest | POS FE | prop assignment count = 0 | idem | PLAN-21 LOT-6 | OUI |
| KioskOfflineIdPrefixTest | Vitest | Kiosk FE | UUID offline prefix `offline_` | idem | PLAN-11 | OUI |
| KioskPromoPreviewCheckoutParityTest | Vitest | Kiosk FE | preview total == checkout | idem | PLAN-05 | OUI |
| KioskFormatPriceLocaleTest | Vitest | Kiosk FE | locale dynamique | idem | PLAN-21 | NON |
| OrderStatusEnumKioskHardcodeLintTest | Static lint | FE | aucun status numeric hardcoded | `npm run lint:fk-enum` | PLAN-09 / PLAN-12 | OUI |
| LegacyImportGuardLintTest | Static lint | All | aucun import legacy | `npm run lint:fk-legacy` | PLAN-12 | OUI |
| BundleScanLegacyTest | CI | Build | bundle prod ne contient pas legacy | `npm run scan:bundle:legacy` | PLAN-12 | OUI |
| KdsMultiScreenPlaywrightTest | Playwright E2E | Hardware | 2 écrans cuisine bump cohérent | `npx playwright test kds-multi-screen` | PLAN-07 | OUI |
| KioskOfflineCbRefusedPlaywrightTest | Playwright E2E | Hardware | offline → CB grisé/refusé | idem | PLAN-11 | OUI |
| FiscalZE2eClosePlaywrightTest | Playwright E2E | Hardware fiscal | Z close → archive HMAC | idem | PLAN-08 | OUI (si fiscal A/B) |
| HardwareTpeTimeoutTest | Manual checklist | Hardware | TPE timeout 30s | checklist sign | PLAN-16 | OUI |
| HardwareEscPosFailoverTest | Manual checklist | Hardware | impression failover offline | idem | PLAN-16 | OUI |
| HardwareDrawerLockTest | Manual checklist | Hardware | open/lock/audit | idem | PLAN-16 | OUI |
| OpsPreflightCaisseV1Test | CI smoke | Ops | queue/scheduler/workers/broadcast/cache OK | `bash scripts/ops-preflight-caisse-v1.sh` | PLAN-14 | OUI |
| AfterCommitDispatchTest | PHP Feature | Backend | events après DB commit | `php artisan test --filter=DispatchAfterCommit` | PLAN-14 | OUI |
| OutboxRescueTest | PHP Feature | Backend | failed event re-emit | idem | PLAN-14 | OUI |
| MigrationDryRunTest | CI | DB | migration apply + revert sans corruption | `php artisan migrate:test` | PLAN-13 | OUI |
| RolloutCanaryDrillTest | Drill | Ops | flag → canary → rollback | runbook drill | PLAN-15 | OUI (avant go-live) |

---

## I — Runtime / Ops / Migration / Rollback

**I.1 Queue & workers** : driver vérifié (Redis/DB/SQS) chaque env ; workers ≥ N_min, supervision Horizon/Supervisor, restart auto ; retry exponential backoff, max 3, dead-letter queue ; chaos test kill worker → events réémis.
**I.2 Scheduler** : crons actifs (Z nightly, parked cleanup, sync_metrics purge, fiscal archive ttl, quote expiration) ; `php artisan schedule:list` audited ; last-run timestamp monitored.
**I.3 Broadcast** : channel par branche scopé (auth) ; Echo reconnect + token refresh ; test 30s déconnexion → reconnect + state resync.
**I.4 Cache** : TTL par usage (menu 60s, pricing 30s, Z aggregate 0) ; invalidation hooks (item update → flush).
**I.5 Fiscal archive** : stockage immuable (S3 + object lock OU local sealed FS) ; TTL 6 ans (NF525) ; HMAC chain verifiable.
**I.6 Outbox rescue** : table failed events ; CLI `php artisan fk:outbox:rescue` ; alerting si depth > seuil.
**I.7 DB migrations** : rehearsal staging full-volume ; backup pré-migration ; Up + Down testées ; runbook par migration (durée, locks, rollback).
**I.8 Rollback / canary** : flags listés § G ; canary 1 branche → 10% → 50% → 100% ; predicates : payment success < 95% sur 5 min OR fiscal anomaly > 0 OR KDS error > 5% → flag off.
**I.9 Observabilité** : dashboards (payment, KDS, fiscal Z, branch leak, queue depth, worker errors) ; alerting on-call ; log structured (correlation_id, branch_id, order_id).
**I.10 Incident response** : runbook par scénario (TPE down, Z fail, KDS deadlock, cleanup race) ; cadence post-mortem J+1, J+7, J+30.

---

## J — Plan d'utilisation par agents

**J.1 Cycle officiel** : `PLAN(Claude) → EXECUTE(codex CLI + self-audit) → VALIDATE(tests) → AUDIT(Claude terminal Opus 4.7 effort high) → GATE(humain ou continue) → CLOSE(Graphiti ingest)`.

**J.2 Codex CLI EXECUTE** : `npm run codex:complex -- <TASK_ID>` (ChatGPT Pro CLI, pas HTTP) ; inputs `missions/<TASK_ID>/input.json` ; outputs `missions/<TASK_ID>/output_codex.json` + diffs ; trace `EXECUTE_DELEGATION: codex-extension` dans `reports/post_execute_latest.log`.

**J.3 Codex self-audit** : wrapper écrit `missions/<TASK_ID>/GPT_SELF_AUDIT_<TASK_ID>.md` ; vérifie invariants + allowlist + sentinelles ; si fail → ne pas appliquer.

**J.4 Validate** : `local-validation` (`php artisan test`, `npm run test:unit`, `npm run lint`), `playwright-*` (E2E), `static-inspection` (lint AST FK, bundle scan, schema check) ; append `reports/post_execute_latest.log`.

**J.5 Claude terminal AUDIT** : `bash scripts/foodking-claude-orchestrate.sh audit` ; Opus 4.7 + effort high ; lit `_TERMINAL_CONTEXT_BRIEF.md` + `audit-context.md` ; output `AUDIT_VERDICT: PASS | REWORK` ; audit-prompt template par sous-plan (cf. § D).

**J.6 Graphiti / Memory** : Phase 0 obligatoire `search_memory_facts(group_ids=["foodking"])` ; fallback `memory/INDEX.md` + JSONL local si MCP HS ; post-CLOSE `bash scripts/after-execute-memory.sh` ; verify `python3 memory/verify.py` (count ≥ 175, current 182).

**J.7 missions/<TASK_ID>/** : `input.json`, `graphiti_context.md`, `plan_excerpt.md`, `execute_brief.md`, `cycle_snapshot.md`, `output_codex.json`, `GPT_SELF_AUDIT_<TASK_ID>.md`. Éphémère par cycle.

**J.8 Activity log** : `reports/AGENT_ACTIVITY_LOG.md` append-only (flock atomic), entries `start`/`done` par scope, horodaté.

**J.9 Gate log** : `docs/gates/GATE_LOG.md` registre + `docs/gates/GATE_<SUJET>_<DATE>.md` par gate (titre, contexte, options, verdict, signataire, date).

**J.10 Rework loop** : max 5 cycles `REMEDIATION_AUDIT_CYCLE` par TASK_ID ; 5e échec → HUMAN_GATE ; max 3 healing consécutifs (CLAUDE.md §8) avant escalade.

**J.11 Active cycle** : `.cursor/ACTIVE_CYCLE.md` mis à jour à chaque transition (TASK_ID, PHASE, PLAN_FILE, REPORT_FILE).

---

## K — Master checklist finale

### K.1 Ready for Phase 0
- [ ] Ce rapport SUPER_MASTER_PLAN_REVIEW signé
- [ ] PLAN-00 master DAG produit (`plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`)
- [ ] PLAN-01 traceability matrix produit
- [ ] PLAN-19 memory discipline + Graphiti read OK ou fallback documenté
- [ ] Hardware lab réservé (PLAN-16 démarré)
- [ ] Owners alloués par PLAN
- [ ] Calendrier 8-10 semaines validé

### K.2 Ready for implementation
- [ ] PLAN-02 : 18 sentinelles rouges + raison + commande
- [ ] PLAN-03 : 10/10 gates humains signés
- [ ] PLAN-12 legacy guards CI active
- [ ] PLAN-21 LOT-0 quickwins exécutés (discount v-model, RTL)
- [ ] Allowlist/denylist par TASK_ID disponible
- [ ] Audit-prompt template par sous-plan disponible

### K.3 Ready for test campaign
- [ ] PLAN-04* (A ou B selon gate) verts
- [ ] PLAN-05 (quote SSOT) verts
- [ ] PLAN-06 (POS guards) verts
- [ ] PLAN-07 (KDS release) verts
- [ ] PLAN-08 (fiscal Z) verts
- [ ] PLAN-09 (branch isolation) verts
- [ ] PLAN-10 (OS/FOS symmetry) verts
- [ ] PLAN-11 (kiosk runtime) verts
- [ ] PLAN-13 (migrations) rehearsal staging OK
- [ ] PLAN-14 (ops preflight) green
- [ ] PLAN-18 coverage matrix ≥ seuils (POS 80%, KDS 80%, Kiosk 70% INFERENCE)

### K.4 Ready for staging
- [ ] Toutes migrations rehearsées sur full-volume staging
- [ ] PLAN-16 hardware checklist signée
- [ ] PLAN-15 canary drill rollback exécuté
- [ ] PLAN-22 dashboards observabilité live
- [ ] Alerting/on-call rotation établi
- [ ] Documentation PLAN-20 propre (ORDER_FLOW, BUSINESS_RULES, AUTHZ, runbooks)

### K.5 Ready for go-live
- [ ] `GATE_GO_NO_GO_CAISSE_V1` signé humain
- [ ] Production preflight green
- [ ] Staging smoke 24h sans incident
- [ ] Final Claude audit `AUDIT_VERDICT: PASS`
- [ ] Graphiti ingest CLOSE + verify ≥ 200 facts (INFERENCE)
- [ ] On-call J0..J+7 mobilisé
- [ ] Rollback flags armés
- [ ] Hotfix runbook accessible

---

## L — Verdict final

**`CLAUDE_SUPER_MASTER_PLAN_VERDICT: HUMAN_GATES_FIRST`**

### Justification
Le plan actuel `PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md` n'est pas exécutable en l'état pour une livraison V1 production-grade en 8-10 semaines. Avant de générer `PLAN_CAISSE_V1_SUPER_MASTER_CORRECTION_2026-04-25.md`, **les 10 gates humains doivent être tenus et signés** (7 documentés + 3 nouveaux : `GATE_OFFLINE_SCOPE_V1`, `GATE_WEB_PAYMENT_SCOPE_V1`, `GATE_STRIPE_CENTS_ACTIVE`). Sans eux, PLAN-04A/04B, PLAN-08 fiscal kiosk, PLAN-11 kiosk offline, PLAN-17 stripe restent indéterminés — soit ≥ 50% du périmètre exécution.

**En parallèle** des gates humains, démarrer PLAN-01, PLAN-02, PLAN-12, PLAN-16, PLAN-18, PLAN-19, PLAN-20, PLAN-21 LOT-0 (no-code / test-only / CI / hardware / docs / quickwins).

### Risque résiduel
- **Overbuild primitives avant sécurité** (Codex pulse) : mitigé par séquencement Phase 0 → P0 sécurité avant contrats.
- **Sous-bâtir dette architecturale** (Claude pulse) : mitigé par PLAN-04*, PLAN-05, PLAN-10.
- **Hardware blocage tardif** : mitigé par PLAN-16 démarré Phase 0.
- **Gate-fatigue humaine** : mitigé par regroupement en 2-3 sessions max.
- **Drift agents Codex** : mitigé par allowlist/denylist + audit-prompt par sous-plan + Claude terminal AUDIT.

### FICHIERS_MANQUANTS
- Aucun bloquant. Tous les fichiers obligatoires de § 1 sont présents et lisibles.
- À produire en sortie : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`, `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1.md`, 3 nouveaux `docs/gates/GATE_*.md`, audit-prompts par sous-plan, runbooks migration/ops/rollout.

---

**Fin du rapport. Signé : Claude terminal Opus 4.7, 2026-04-25, posture adversariale.**

> Note exécutive : tentative d'écriture du fichier `reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md` refusée par permission. Si tu veux le persister sur disque, autorise la création de fichiers dans `reports/audit/` et je l'écris à l'identique.
