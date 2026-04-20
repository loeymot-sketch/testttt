# PLAN POST-VERIFY — 2026-04-20

**Date :** 2026-04-20
**Mode :** PLAN-ONLY (orchestration multi-agents AGENTS.md `PLAN → EXECUTE → VALIDATE → AUDIT → [HUMAN GATE | CLOSE]`)
**Source :** [`reports/review/VERIFY_TRACKER_2026-04-20.md`](../reports/review/VERIFY_TRACKER_2026-04-20.md) §1
**Auteur :** `foodking-planner-orchestrator` (Claude Opus 4.7)

> **Routing modèle (rappel AGENTS.md §Model Roles)** :
> - **Claude** = planning, orchestration, audit final, gate detection, scope discipline (NE PEUT PAS écrire de code applicatif).
> - **GPT-5.4** (subagent `foodking-complex-implementer`) = backend complexe, sync-sensitive, lifecycle, fiscal NF525, idempotence, schéma, frozen-zone-adjacent.
> - **Composer** (subagent `foodking-routine-implementer`) = UI bornée, edits config, tests isolés, docs, mineurs sûrs ; NE PEUT PAS toucher schéma / migrations / auth / pricing / dispatch / lifecycle.
>
> **Citations AGENTS.md justifiant les routings P0** (ces lignes sont la base de chaque décision ci-dessous) :
> - "GPT-5.4 — complex backend logic, fiscal/lifecycle/sync sensitive areas, frozen-zone-adjacent edits, schema migrations" → **P0 fiscal/lifecycle/idempotency** (P11_RETURNED_IDEMPOTENCY, P11_FISCAL_Z_OPEN_HARDENING, P11_PAYMENT_STATUS_STATE_MACHINE, P11_IDEMPOTENCY_KEY_MIDDLEWARE, P11_COUPON_BRANCH_ISOLATION, P11_RETURNED_KDS_BYPASS_LOCKDOWN, P11_AUDIT_TENDER_ON_CREATE, P11_WEBHOOK_SIGNATURE_AUDIT, P11_TRUE_OUTBOX_TRANSACTIONAL).
> - "Composer — bounded UI changes, configuration tweaks, isolated tests, documentation, no schema, no auth, no pricing" → **P0 UI / docs / config** (P11_BUSINESS_RULES_DOC_SYNC, P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD, P11_AVAILABILITY_TOGGLE_UI_ADMIN, P11_PLAYWRIGHT_THROTTLE_FIX, P11_FRONT_TR_UI).
> - "Claude — planning, audit, gate detection, scope arbitration, never touches application code" → tout ce plan + audit final post-vague.

---

## §1. Cycles P11+ — Table maître (50 cycles uniques)

> Légende : **PRIMARY_MODEL** = `GPT5` (foodking-complex-implementer) | `CMP` (foodking-routine-implementer) | `CLD` (foodking-planner-orchestrator). **GATE** = oui/non. **j-h** = jours-homme estimés.

### 1.1 P0 — Critiques (12 cycles, traiter en V1+V2)

> **Addendum 2026-04-20 (post cycle 05)** : F-VERIFY-17-01 (build pipeline pos-wizard) **requalifié** — pas un bug manifest, mais une décision architecturale à prendre (migration `asset()+time()` → `mix()` dans `master.blade.php`). Voir `reports/execution/RUN_P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD_2026-04-20.md` §Final report. Cycle P11_BUILD_PIPELINE_RESTORE clos REQUALIFIED ; cycle successeur éventuel : `P11_POS_WIZARD_MIX_MIGRATION` (V2/V3, scope étendu `master.blade.php` + ES module refactor). Alternative : `wontfix intentionnel` (cache-busting request-based actuel suffisant).

| Cycle | Findings | Prio | PRIMARY_MODEL | Subagent | j-h | GATE | Invariants touchés | Dépendances |
|---|---|---|---|---|---|---|---|---|
| **P11_RETURNED_IDEMPOTENCY** | F-VERIFY-03-01, 20-* | P0 | GPT5 | complex-impl | 1.0 | **OUI** | OrderStatus state-machine, NF525 audit, dispatch-after-commit | LOCK_A+B OrderService |
| **P11_RETURNED_KDS_BYPASS_LOCKDOWN** | F-VERIFY-03-02 | P0 | GPT5 | complex-impl | 0.5 | **OUI** | OrderStatus, NF525, branch isolation | LOCK_A+B OrderService, KitchenDisplaySystemOrderService |
| **P11_FISCAL_Z_OPEN_HARDENING** | F-VERIFY-08-01,02, 03-03, 09-* | P0 | GPT5 | complex-impl | 1.5 | **OUI** | NF525 fiscal, OrderStatus | ZReportService + OrderService (LOCK_A+B) |
| **P11_PAYMENT_STATUS_STATE_MACHINE** | F-VERIFY-09-01 | P0 | GPT5 | complex-impl | 1.5 | **OUI** | Pricing SSOT, OrderStatus, NF525, dispatch | LOCK_A+B OrderService, LOCK_B PaymentService |
| **P11_IDEMPOTENCY_KEY_MIDDLEWARE** | F-VERIFY-09-02 | P0 | GPT5 | complex-impl | 1.0 | **OUI** | branch isolation, sync, security | LOCK_B routes/api |
| **P11_COUPON_BRANCH_ISOLATION** | F-VERIFY-06-01 | P0 | GPT5 | complex-impl | 1.5 | **OUI** | branch_id isolation, schéma | Migration coupons + LOCK_B DiscountCalculator |
| **P11_COUPON_LIMIT_PER_USER_KIOSK** | F-VERIFY-06-02 | P0 | GPT5 | complex-impl | 1.0 | OUI (touche kiosk machine auth) | branch isolation, security | CouponService, KioskMachine auth |
| **P11_WEBHOOK_SIGNATURE_AUDIT** | F-VERIFY-12-01 | P0 | GPT5 | complex-impl | 1.0 | **OUI** | security, payment, NF525 | PaymentGateways/Routes/* |
| **P11_BUSINESS_RULES_DOC_SYNC** | F-VERIFY-20-01,02, 01-07 | P0 | CMP | routine-impl | 0.5 | non | (docs only) | aucune |
| **P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD** | F-VERIFY-17-01 | P0 | CMP | routine-impl | 0.25 | non | (config build) | aucune |
| **P11_AVAILABILITY_TOGGLE_UI_ADMIN** | F-VERIFY-19-01, 01-06 | P0 | CMP | routine-impl | 1.0 | non (UI bornée) | (UI only, backend déjà gated) | aucune |
| **P11_PLAYWRIGHT_THROTTLE_FIX** | F-VERIFY-11-01, 16-01 | P0 (infra) | CMP | routine-impl | 0.25 | non (env-conditionnel) | (test infra) | aucune |

### 1.2 P1 — Hardening (15 cycles, traiter en V3)

| Cycle | Findings | Prio | PRIMARY_MODEL | Subagent | j-h | GATE | Invariants touchés | Dépendances |
|---|---|---|---|---|---|---|---|---|
| **P11_TRUE_OUTBOX_TRANSACTIONAL** | F-VERIFY-09-03, 14-* | P1 | GPT5 | complex-impl | 1.0 | **OUI** | dispatch-after-commit, sync | LOCK_B EventContract, OrderService |
| **P11_FRONT_TR_UI** | F-VERIFY-02-01 | P1 | CMP | routine-impl | 1.0 | non | (UI bornée POS) | (après P11_AUDIT_TENDER_ON_CREATE) |
| **P11_AUDIT_TENDER_ON_CREATE** | F-VERIFY-02-03 | P1 | GPT5 | complex-impl | 0.5 | OUI (NF525) | NF525 audit | OrderService::posOrderStore |
| **P11_RECEIPT_TR_LABEL** | F-VERIFY-02-02 | P1 | CMP | routine-impl | 0.5 | non | (presentation) | PrintService |
| **P11_FISCAL_ROUTE_AUTHZ_HARDENING** | F-VERIFY-10-01 | P1 | GPT5 | complex-impl | 0.5 | OUI (auth) | branch isolation, fiscal | LOCK_B routes/api |
| **P11_DATA_INDEXES_FK** | F-VERIFY-13-01,02 | P1 | GPT5 | complex-impl | 1.5 | **OUI** | schéma, branch isolation | Migrations transactions/z_reports/audit_logs |
| **P11_LOGS_CORRELATION_ID** | F-VERIFY-15-01 | P1 | GPT5 | complex-impl | 0.5 | non | observability | OrderService, FrontendOrderService, listeners |
| **P11_OUTBOX_OBSERVABILITY** | F-VERIFY-15-02, 14-04 | P1 | GPT5 | complex-impl | 1.0 | non | observability | HealthController + cron |
| **P11_FROZEN_ZONE_GATE** | F-VERIFY-18-01 | P1 | CMP | routine-impl | 0.5 | non | (process / docs) | docs/gates/GATE_LOG.md |
| **P11_PRICING_FRONT_PURGE** | F-VERIFY-18-02 | P1 | GPT5 | complex-impl | 1.5 | **OUI** | Pricing SSOT | LOCK_A PricingService + LOCK_A+B OrderService + front |
| **P11_TEST_PRICING_SSOT_PROOF** | F-VERIFY-16-02 | P1 | CMP | routine-impl | 0.25 | non | (tests bornés) | aucune |
| **P11_TEST_IDEMPOTENCY_RACE** | F-VERIFY-16-03 | P1 | GPT5 | complex-impl | 0.5 | non | (tests sync) | (après P11_IDEMPOTENCY_KEY_MIDDLEWARE) |
| **P11_DEPLOY_PROCEDURE_DOC** | F-VERIFY-17-02 | P1 | CMP | routine-impl | 0.25 | non | (docs/.env) | aucune |
| **P11_COUPON_AUDIT_LOG_SYMMETRY** | F-VERIFY-06-03 | P1 | GPT5 | complex-impl | 0.5 | OUI (NF525) | NF525 audit | CouponService, FrontendOrderService |
| **P11c_AVAILABILITY_TEST_BIDIRECTIONAL** | F-VERIFY-19-02 | P1 | CMP | routine-impl | 0.5 | non | (tests bornés) | aucune |

### 1.3 P2 — Robustesse (13 cycles, traiter en V4)

| Cycle | Findings | Prio | PRIMARY_MODEL | Subagent | j-h | GATE | Invariants touchés | Dépendances |
|---|---|---|---|---|---|---|---|---|
| P12_OrderCreate_GuardFirst | F-VERIFY-01-01 | P2 | GPT5 | complex-impl | 0.5 | OUI (LOCK OrderService) | OrderStatus, branch isolation | LOCK_A+B OrderService |
| P12_POS_CART_PRUNE | F-VERIFY-01-02 | P2 | CMP | routine-impl | 0.5 | non | (UI POS) | aucune |
| P12_KDS_HTTP_RACE_TEST_HARDENING | F-VERIFY-04-02 | P2 | GPT5 | complex-impl | 0.5 | non | (tests complex) | aucune |
| P12_KDS_VUEX_REFRESH | F-VERIFY-04-03 | P2 | CMP | routine-impl | 0.25 | non | (Vuex front) | aucune |
| P12_RETURNED_FISCAL_INTEGRATION | F-VERIFY-03-03 | P2 | GPT5 | complex-impl | 1.5 | **OUI** | NF525, OrderStatus | ZReportService + OrderService |
| P12_FISCAL_AUDIT_LOG_IMMUTABLE_AUTOVERIFY | F-VERIFY-08-04 | P2 | GPT5 | complex-impl | 0.5 | OUI (NF525) | NF525 | AuditLogService + Kernel cron |
| P12_PAYMENT_FEATURE_TESTS | F-VERIFY-09-04 | P2 | GPT5 | complex-impl | 0.5 | non | (tests fiscal) | (après P11_PAYMENT_STATUS_STATE_MACHINE) |
| P12_DATA_TRANSACTIONS_BRANCH_ISOLATION | F-VERIFY-13-01 | P2 | GPT5 | complex-impl | 1.0 | **OUI** | branch isolation, schéma | Migration + Transaction model |
| P12_AVAILABILITY_REFACTOR_DEDUPE | F-VERIFY-19-03 | P2 | GPT5 | complex-impl | 0.5 | non | (refacto controller→service) | aucune |
| P12_BUNDLE_POS_SPLIT | F-VERIFY-15-03 | P2 | CMP | routine-impl | 1.0 | non | (build) | aucune |
| P12_SECURITY_HEADERS | F-VERIFY-12-02 | P2 | CMP | routine-impl | 0.25 | non | (middleware sécurité) | aucune |
| P12_ORDER_SETUP_ENUM_HARDENING | F-VERIFY-07-01,03 | P2 | GPT5 | complex-impl | 0.5 | non | (validation) | OrderSetupRequest |
| P12_TR_SOLDER_CASH_CB + P12_REFUND_TRANSACTION_POS + P12_TENDER_E2E (fusion `P12_MULTI_TENDER_PHASE2`) | F-VERIFY-02-04,05,06 | P2 | GPT5 | complex-impl | 1.5 | OUI (paiement) | NF525, paiement | (après P11_FRONT_TR_UI) |

### 1.4 P3 — Hygiène / confort (10 cycles)

| Cycle | Findings | Prio | PRIMARY_MODEL | Subagent | j-h | GATE | Notes |
|---|---|---|---|---|---|---|---|
| P13_FISCAL_EXPORT_JET | F-VERIFY-08-05 | P3 | GPT5 | complex-impl | 3.0 | OUI (NF525) | Backlog officiel JET/PIAF |
| P13_FISCAL_Z_STATUS_CLOSING | F-VERIFY-08-03 | P3 | GPT5 | complex-impl | 0.5 | OUI (NF525) | |
| P13_VUE_IMPORTS_EXPLICIT | F-VERIFY-18-03 | P3 | CMP | routine-impl | 0.5 | non | Préparation Vite |
| P13_LOG_HYGIENE | F-VERIFY-18-05 | P3 | CMP | routine-impl | 0.25 | non | console.log purge |
| P13_ENV_TO_CONFIG | F-VERIFY-18-04 | P3 | CMP | routine-impl | 0.1 | non | QueryExceptionLibrary |
| P13_STATUS_TX_HARDEN | F-VERIFY-18-06 | P3 | GPT5 | complex-impl | 0.5 | OUI (LOCK OrderService) | |
| P13_FISCAL_TIMING_METRICS | F-VERIFY-15-04 | P3 | CMP | routine-impl | 0.25 | non | duration_ms logs |
| P13_AUDIT_REPORT_HYGIENE | F-VERIFY-10-03 | P3 | CMP | routine-impl | 0.25 | non | Politique reports/ |
| P13_DEMO_MODE_PROD_GUARD | F-VERIFY-12-04 | P3 | CMP | routine-impl | 0.25 | non | Garde demo mode |
| P13_VHTML_ANALYTICS_HARDENING | F-VERIFY-12-03 | P3 | CMP | routine-impl | 0.25 | non | Surface analytics |

> **Cycles supplémentaires non priorisés (P3 / observabilité / docs)** : P14_SYNC_E2E_LATENCY_2S, P15_OUTBOX_DLQ_OBSERVABILITY, P12_PLAYWRIGHT_KDS_LIVE_E2E, P12_TEST_LIVE_RUN_GATE, P11_KIOSK_SIMULATE_HARDENING, P11_AVAILABILITY_DEAD_CODE, P11_DISPATCH_AFTER_COMMIT_AUDIT, P11_I18N_COMPLETE_FR_EN, P12_ROLE_ROUTE_MATRIX_GEN, P11_REQ_MONEY_HARDENING_TESTS_SYMETRIE_FRONT, P12_DRAWER_CONDITIONS_TIGHTEN, P13_DATA_SCHEMA_DOC_REFRESH, P13_OBS_SENTRY_BACKEND, P13_RETURNED_LOYALTY_GUARD, P13_RETURNED_E2E, P13_KDS_OPTIMISTIC_LOCK_DOC, P13_KDS_409_OBSERVABILITY, P13_PAYMENT_UI_A11Y, P13_ADMIN_CROSS_BRANCH_DOC.

**Total cycles uniques recensés : 50** (12 P0 + 15 P1 + 13 P2 + 10 P3, listes 1.1–1.4 + cycles P3 supplémentaires comptés en bloc).

---

## §2. Vague d'exécution recommandée

### Vague V1 — P0 critique corruption / fiscal / sécurité directe (parallélisable partiel)

**Objectif :** stopper l'hémorragie (corruption monétaire RETURNED, NF525 sealed-Z, doc induit en erreur, build pipeline cassé).

| # | Cycle | Parallèle ? | Dépendance |
|---|---|---|---|
| 1 | **P11_RETURNED_IDEMPOTENCY** (GPT5) | non (frozen zone) | GATE |
| 2 | **P11_FISCAL_Z_OPEN_HARDENING** (GPT5) | non (frozen + NF525) | GATE, après #1 (cohérence sealed-Z guard) |
| 3 | **P11_PAYMENT_STATUS_STATE_MACHINE** (GPT5) | non (frozen + NF525) | GATE, après #2 |
| 4 | **P11_RETURNED_KDS_BYPASS_LOCKDOWN** (GPT5) | non (frozen) | GATE, après #1 |
| 5 | **P11_BUSINESS_RULES_DOC_SYNC** (CMP) | **oui** (parallèle backend) | aucune |
| 6 | **P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD** (CMP) | **oui** | aucune |
| 7 | **P11_AVAILABILITY_TOGGLE_UI_ADMIN** (CMP) | **oui** | aucune |
| 8 | **P11_PLAYWRIGHT_THROTTLE_FIX** (CMP) | **oui** | aucune |

### Vague V2 — P0 hardening (sécurité, idempotence HTTP, isolation)

| # | Cycle | Parallèle ? | Dépendance |
|---|---|---|---|
| 9 | **P11_IDEMPOTENCY_KEY_MIDDLEWARE** (GPT5) | non (LOCK routes/api) | GATE, après V1 |
| 10 | **P11_COUPON_BRANCH_ISOLATION** (GPT5) | non (schéma + LOCK_B) | GATE |
| 11 | **P11_COUPON_LIMIT_PER_USER_KIOSK** (GPT5) | partiel (après #10) | GATE, après #10 |
| 12 | **P11_WEBHOOK_SIGNATURE_AUDIT** (GPT5) | **oui** | GATE |

### Vague V3 — P1 hardening (sync transactionnel, observability, doc/UI structurels)

Tous parallélisables sauf chaînes explicites.

| # | Cycle | Parallèle ? | Dépendance |
|---|---|---|---|
| 13 | P11_TRUE_OUTBOX_TRANSACTIONAL (GPT5) | non (LOCK EventContract) | GATE, après V2 |
| 14 | P11_FISCAL_ROUTE_AUTHZ_HARDENING (GPT5) | oui | GATE |
| 15 | P11_DATA_INDEXES_FK (GPT5) | non (migration) | GATE |
| 16 | P11_LOGS_CORRELATION_ID (GPT5) | oui | non |
| 17 | P11_OUTBOX_OBSERVABILITY (GPT5) | oui | non |
| 18 | P11_AUDIT_TENDER_ON_CREATE (GPT5) | oui | OUI (NF525) |
| 19 | P11_FRONT_TR_UI (CMP) | oui | après #18 |
| 20 | P11_RECEIPT_TR_LABEL (CMP) | oui | non |
| 21 | P11_PRICING_FRONT_PURGE (GPT5) | non (LOCK_A PricingService) | GATE, après V2 |
| 22 | P11_FROZEN_ZONE_GATE (CMP) | oui | non |
| 23 | P11_TEST_PRICING_SSOT_PROOF (CMP) | oui | après #21 (preuve serveur) |
| 24 | P11_TEST_IDEMPOTENCY_RACE (GPT5) | oui | après #9 |
| 25 | P11_DEPLOY_PROCEDURE_DOC (CMP) | oui | non |
| 26 | P11_COUPON_AUDIT_LOG_SYMMETRY (GPT5) | oui | OUI (NF525), après #11 |
| 27 | P11c_AVAILABILITY_TEST_BIDIRECTIONAL (CMP) | oui | après #7 (UI émetteur) |

### Vague V4 — P2 / P3 (robustesse + hygiène + dette tooling)

Toute la liste §1.3 + §1.4 en parallèle (sauf chaînes documentées). Critère d'entrée : V3 close + audit Claude OK.

---

## §3. Gate humain requis

**Frozen zones actives détectées (12 LOCK files dans `tasks/phase9-sync/`) :**

| Fichier frozen | LOCK file | Cycles concernés (gate obligatoire) |
|---|---|---|
| `app/Services/OrderService.php` | LOCK_A_P9_5_OrderService_2026-04-18.md, LOCK_B_POS_9_2_3_OrderService_2026-04-18.md | P11_RETURNED_IDEMPOTENCY, P11_RETURNED_KDS_BYPASS_LOCKDOWN, P11_FISCAL_Z_OPEN_HARDENING, P11_PAYMENT_STATUS_STATE_MACHINE, P11_TRUE_OUTBOX_TRANSACTIONAL, P11_AUDIT_TENDER_ON_CREATE, P11_PRICING_FRONT_PURGE, P12_OrderCreate_GuardFirst, P13_STATUS_TX_HARDEN |
| `app/Services/FrontendOrderService.php` | LOCK_A_P9_5_FrontendOrderService_2026-04-18.md, LOCK_B_POS_9_2_FrontendOrderService_2026-04-18.md | P11_PRICING_FRONT_PURGE, P11_COUPON_AUDIT_LOG_SYMMETRY, P11_LOGS_CORRELATION_ID, P11_TRUE_OUTBOX_TRANSACTIONAL |
| `app/Services/Pricing/PricingService.php` + Pricing Requests | LOCK_A_P9_5_PricingService_PricingRequests_2026-04-18.md | P11_PRICING_FRONT_PURGE |
| `app/Services/PaymentService.php` | LOCK_B_POS_9_2_3_PaymentService_2026-04-18.md | P11_PAYMENT_STATUS_STATE_MACHINE |
| `app/Services/Pricing/DiscountCalculator.php` | LOCK_B_POS_9_4_BL_DiscountCalculator_2026-04-18.md | P11_COUPON_BRANCH_ISOLATION |
| `app/Domain/Events/EventContract*` | LOCK_B_POS_9_3_EventContract_2026-04-18.md | P11_TRUE_OUTBOX_TRANSACTIONAL |
| `routes/api.php` | LOCK_B_POS_9_2_routes_api_2026-04-18.md | P11_IDEMPOTENCY_KEY_MIDDLEWARE, P11_FISCAL_ROUTE_AUTHZ_HARDENING |
| `app/Http/Controllers/Admin/OrderController.php` | LOCK_B_POS_9_2_OrderController_admin_2026-04-18.md | (potentiel — vérifier scope ad hoc) |
| Migration `idempotency_key` | LOCK_A_P9_5_idempotency_key_migration_2026-04-18.md | P11_IDEMPOTENCY_KEY_MIDDLEWARE |
| Migration `OrderItem allergens` | LOCK_A_P9_5_OrderItem_migration_allergens_2026-04-18.md | P11_DATA_INDEXES_FK (transitif si tables croisées) |

**Gate humain obligatoire (Brief Gate format `.cursor/rules/human-gates.mdc` §Gate Brief)** pour tout cycle marqué **GATE = OUI** dans §1. Cycles UI / docs / config / tests bornés (tous Composer P0/P1/P2/P3) sont **dispensés** de gate sauf si leur diff réel touche un fichier frozen.

**Invariants critiques requérant gate (rappel `.cursor/rules/human-gates.mdc`) :**
- Schéma / migrations DB → P11_DATA_INDEXES_FK, P11_COUPON_BRANCH_ISOLATION, P12_DATA_TRANSACTIONS_BRANCH_ISOLATION.
- Auth / tokens (Sanctum / API Key / kiosk machine) → P11_COUPON_LIMIT_PER_USER_KIOSK (touche kiosk machine path), P11_FISCAL_ROUTE_AUTHZ_HARDENING.
- Pricing SSOT → P11_PRICING_FRONT_PURGE.
- OrderState machine → P11_RETURNED_IDEMPOTENCY, P11_PAYMENT_STATUS_STATE_MACHINE, P11_RETURNED_KDS_BYPASS_LOCKDOWN, P12_OrderCreate_GuardFirst, P13_STATUS_TX_HARDEN.
- NF525 fiscal (audit_log immuable, séquence fiscale, Z reports) → P11_FISCAL_Z_OPEN_HARDENING, P11_AUDIT_TENDER_ON_CREATE, P11_COUPON_AUDIT_LOG_SYMMETRY, P12_RETURNED_FISCAL_INTEGRATION, P12_FISCAL_AUDIT_LOG_IMMUTABLE_AUTOVERIFY, P13_FISCAL_EXPORT_JET, P13_FISCAL_Z_STATUS_CLOSING.
- Webhook / payment gateway signature → P11_WEBHOOK_SIGNATURE_AUDIT.

---

## §4. Cycles candidats à fusion

| Fusion proposée | Cycles initiaux | Justification |
|---|---|---|
| **P11_FISCAL_Z_OPEN_HARDENING** (déjà fusionné) | sealed-Z guard cité par V03+V08+V20, verify-chain cité par V08, idempotence Z OPEN | Tous touchent `ZReportService::open()` / `OrderService::changeStatus(RETURNED)` — un seul cycle backend cohérent. |
| **P11_BUSINESS_RULES_DOC_SYNC** (déjà fusionné) | §Stock (V01+V20), §RETURNED (V03+V20), §Coupons (V06+V20), §NF525 (V08+V20), §SaaS (V10+V20) | Tous éditent `docs/BUSINESS_RULES.md` — un seul commit doc. |
| **P11_OUTBOX_OBSERVABILITY** (déjà fusionné) | endpoint outbox (V14+V15), gauge cron (V15), DLQ failed (V14) | Touche `HealthController` + Kernel cron + nouveau endpoint — un cycle. |
| **P11_PRICING_FRONT_PURGE** (déjà fusionné) | suppression chemin legacy `OrderService:770` + `FrontendOrderService:412` (V18) + arrêt `form.total` front (V18) + alignement preuve test (V16) | Touche back+front, mais finalité unique : SSOT exclusif. |
| **P12_MULTI_TENDER_PHASE2** (proposée) | P12_TR_SOLDER_CASH_CB + P12_REFUND_TRANSACTION_POS + P12_TENDER_E2E (tous V02 P2) | Trois améliorations multi-tender post-MVP — même surface PaymentService + tests. |
| **P12_DATA_INTEGRITY_BUNDLE** (à évaluer) | P11_DATA_INDEXES_FK + P12_DATA_TRANSACTIONS_BRANCH_ISOLATION | Migrations contiguës ; impact CI = fusion souhaitable si l'impl est faite ensemble. **Décision parent.** |
| **P11_COUPON_HARDENING_BUNDLE** (à évaluer) | P11_COUPON_BRANCH_ISOLATION + P11_COUPON_LIMIT_PER_USER_KIOSK + P11_COUPON_AUDIT_LOG_SYMMETRY | Touche `CouponService` + migration coupons + FrontendOrderService — fusion possible mais augmente le risque diff. **Décision parent : recommandé NE PAS fusionner pour gate granulaire.** |

---

## §5. Hors scope volontaire (backlog)

| Item | Source | Justification non-traitement immédiat |
|---|---|---|
| Multi-tender split N tenders + pourboire + gift card UI complète | V02 §4 | V2 produit ; le présent plan se borne au TR utilisable. |
| Refacto complet `FrontendOrderService` (asymétrie `finalizePaidKioskOrder` documentée) | V18 §4.2 | Asymétrie intentionnelle assumée ; refacto = projet dédié. |
| Export JET / PIAF officiel NF525 | V08, V20 | Déjà listé P13_FISCAL_EXPORT_JET (3 j-h, gate NF525) — peut glisser en V5/backlog. |
| Migration Vite (build moderne) | V17 §2.2, V18 §4.3 | Décision tooling globale, non-bloquante prod. |
| Sentry backend + observabilité Datadog/Cloudwatch | V12, V15, V17 | Décision infra prod, hors code application. |
| Test charge 500 ord/h (k6) + monotonie crypto 10k+ Z | F-FISC-004, V08, V15 | Job perf out of CI. |
| `kds_group_id` (multi-station OSS) | F-KDS-002 | Spec produit OSS non finalisée. |
| Refacto complet `docs/DATABASE_SCHEMA_CORE.md` automatisé | V13 | Outillage ; couvert partiellement P13_DATA_SCHEMA_DOC_REFRESH. |
| Récupération rapport perdu `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` | V10 V0 NOTE | Perte irréversible — couvert prospectivement par P13_AUDIT_REPORT_HYGIENE. |
| Refacto `OrderItem allergens` (déjà sous LOCK actif P9.5) | LOCK_A_P9_5_OrderItem_migration_allergens_2026-04-18.md | Travail en cours hors scope verify ; migration `2026_04_20_131600_backfill_fr_codes_in_order_items_allergens_snapshot.php` déjà côté workspace. |

---

## §6. Critères de succès post-vague

### Sortie V1 (P0 critique) → entrée V2

- [ ] Tous les 8 cycles V1 mergés sur main avec audit Claude PASSED.
- [ ] PHPUnit Feature suite verte locale + CI (PHP 8.3 + MySQL).
- [ ] `tests/Feature/PosOrderBL2AuditCallSitesTest` étendu pour couvrir double-RETURNED idempotent (P11_RETURNED_IDEMPOTENCY).
- [ ] `tests/Feature/Fiscal/ZReportSealedGuardTest` créé (P11_FISCAL_Z_OPEN_HARDENING).
- [ ] `npm run prod` clean produit `public/js/{app,kiosk,pos-wizard}.js` + `public/css/{app,pos-wizard}.css` (P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD).
- [ ] `docs/BUSINESS_RULES.md` aligné avec code (P11_BUSINESS_RULES_DOC_SYNC).
- [ ] Playwright `04-kds-status` passe sans 429 (P11_PLAYWRIGHT_THROTTLE_FIX).
- [ ] Aucun nouveau finding sévérité ≥ P1 introduit (audit Claude post-vague).

### Sortie V2 (P0 hardening) → entrée V3

- [ ] Middleware `Idempotency-Key` actif sur tous les `POST /api/*` mutants (P11_IDEMPOTENCY_KEY_MIDDLEWARE).
- [ ] Coupons scopés par `branch_id` ; toute UNIQUE / FK migration appliquée + rollback testé (P11_COUPON_BRANCH_ISOLATION).
- [ ] Webhooks paiement vérifient signature HMAC (P11_WEBHOOK_SIGNATURE_AUDIT) — test `tests/Feature/PaymentGateways/SenangPayWebhookSignatureTest`.

### Sortie V3 (P1) → entrée V4

- [ ] `Outbox` strict transactionnel + endpoint `/api/health/outbox` répond `pending_count, max_age_seconds, failed_count` (P11_TRUE_OUTBOX_TRANSACTIONAL + P11_OUTBOX_OBSERVABILITY).
- [ ] `Log::withContext` propage `correlation_id`, `user_id`, `branch_id`, `order_id`, `actor_id` (P11_LOGS_CORRELATION_ID).
- [ ] `tests/Feature/PricingSsotProofTest` créé et vert (P11_TEST_PRICING_SSOT_PROOF).
- [ ] `docs/gates/GATE_LOG.md` rempli rétroactivement depuis 2026-04-14 (P11_FROZEN_ZONE_GATE).
- [ ] UI POS Ticket-Restaurant utilisable + label reçu correct (P11_FRONT_TR_UI + P11_RECEIPT_TR_LABEL).

### Sortie V4 (P2/P3) → mise en prod

- [ ] Bundle `app.js` ≤ 2.0 MB (cible 1.5 MB, dette résiduelle acceptée P12_BUNDLE_POS_SPLIT).
- [ ] Headers sécurité (CSP, HSTS, X-Frame-Options) actifs (P12_SECURITY_HEADERS).
- [ ] Tous tests Vitest + PHPUnit + Playwright verts en run live archivé (`reports/execution/RUN_*`).
- [ ] Audit Claude final : aucune dérive d'invariant.
- [ ] **Gate humain `GATE_PROD_FINAL_2026-XX`** signé par stakeholders fiscal + tech + produit.

---

## §7. Modèle de prompt EXECUTE par cycle

```text
TASK_ID = <CYCLE_ID> (ex: P11_RETURNED_IDEMPOTENCY)
SUBAGENT = <foodking-complex-implementer | foodking-routine-implementer>
PRIMARY_MODEL = <GPT-5.4 | Composer>
PHASE = EXECUTE (AGENTS.md PLAN→EXECUTE→VALIDATE→AUDIT)
SCOPE_FILES = <liste exhaustive des fichiers autorisés à modifier>
SUBSYSTEMS_OFF_LIMITS = <liste explicite, ex: "PaymentService, ZReportService">
INVARIANTS_AT_RISK = <ex: "OrderStatus state-machine, NF525 audit chain, dispatch-after-commit">
GATE_REQUIRED = <oui/non>
DEPENDENCIES = <CYCLES préalables clos, ex: "aucune" ou "P11_FISCAL_Z_OPEN_HARDENING">
ACCEPTANCE_TESTS = <liste des tests PHPUnit/Vitest/Playwright à créer ou faire passer>
EXIT_CRITERIA = <bullets concrets, ex: "double appel changeStatus(RETURNED) ne rejoue ni cashBack ni audit">
SCOPE_PRESSURE_PROTOCOL = stop + report + ask Claude (no implicit scope expansion)
DELIVERABLES =
  - diff applicatif minimal et ciblé
  - test(s) automatisé(s) prouvant l'EXIT_CRITERIA
  - rapport `reports/execution/RUN_<CYCLE_ID>_2026-XX-XX.md` avec preuve avant/après
  - si GATE_REQUIRED=oui : `docs/gates/GATE_<CYCLE_ID>_2026-XX-XX.md` (Brief format human-gates.mdc)
COMMUNICATION = pas de commit hors SCOPE_FILES ; toute extension demande SCOPE_PRESSURE
```

---

## §8. Annexe — Mapping VERIFY → cycles P uniques

| VERIFY | Cycles ouverts (référence unique) |
|---|---|
| V01 | P12_OrderCreate_GuardFirst, P12_POS_CART_PRUNE, P11_AVAILABILITY_DEAD_CODE, P11_KIOSK_SIMULATE_HARDENING, **P11_AVAILABILITY_TOGGLE_UI_ADMIN**, **P11_BUSINESS_RULES_DOC_SYNC**, P11c_AVAILABILITY_TEST_BIDIRECTIONAL |
| V02 | P11_FRONT_TR_UI, **P11_AUDIT_TENDER_ON_CREATE**, P11_RECEIPT_TR_LABEL, P12_MULTI_TENDER_PHASE2 |
| V03 | **P11_RETURNED_IDEMPOTENCY**, **P11_RETURNED_KDS_BYPASS_LOCKDOWN**, P12_RETURNED_FISCAL_INTEGRATION, P13_RETURNED_LOYALTY_GUARD, P13_RETURNED_E2E |
| V04 | P11_KDS_409_UX_DIFFERENTIATED, P12_KDS_HTTP_RACE_TEST_HARDENING, P12_KDS_VUEX_REFRESH, P13_KDS_OPTIMISTIC_LOCK_DOC, P13_KDS_409_OBSERVABILITY |
| V05 | P11_REQ_MONEY_HARDENING_TESTS_SYMETRIE_FRONT (optionnel) |
| V06 | **P11_COUPON_BRANCH_ISOLATION**, **P11_COUPON_LIMIT_PER_USER_KIOSK**, P11_COUPON_AUDIT_LOG_SYMMETRY |
| V07 | P11_ORDER_SETUP_SEMANTIC_DOC, P12_ORDER_SETUP_ENUM_HARDENING |
| V08 | **P11_FISCAL_Z_OPEN_HARDENING**, P12_FISCAL_AUDIT_LOG_IMMUTABLE_AUTOVERIFY, P13_FISCAL_Z_STATUS_CLOSING, P13_FISCAL_EXPORT_JET |
| V09 | **P11_IDEMPOTENCY_KEY_MIDDLEWARE**, **P11_PAYMENT_STATUS_STATE_MACHINE**, P11_TRUE_OUTBOX_TRANSACTIONAL, P12_PAYMENT_FEATURE_TESTS, P13_PAYMENT_UI_A11Y |
| V10 | P11_FISCAL_ROUTE_AUTHZ_HARDENING, P12_ROLE_ROUTE_MATRIX_GEN, P13_AUDIT_REPORT_HYGIENE, P13_ADMIN_CROSS_BRANCH_DOC |
| V11 | **P11_PLAYWRIGHT_THROTTLE_FIX**, P12_DRAWER_CONDITIONS_TIGHTEN |
| V12 | **P11_WEBHOOK_SIGNATURE_AUDIT**, P12_SECURITY_HEADERS, P13_DEMO_MODE_PROD_GUARD, P13_VHTML_ANALYTICS_HARDENING |
| V13 | P11_DATA_INDEXES_FK, P12_DATA_TRANSACTIONS_BRANCH_ISOLATION, P13_DATA_SCHEMA_DOC_REFRESH |
| V14 | P11_DISPATCH_AFTER_COMMIT_AUDIT, **P11_OUTBOX_OBSERVABILITY**, P14_SYNC_E2E_LATENCY_2S, P15_OUTBOX_DLQ_OBSERVABILITY |
| V15 | P11_LOGS_CORRELATION_ID, **P11_OUTBOX_OBSERVABILITY** (idem V14), P12_BUNDLE_POS_SPLIT, P13_FISCAL_TIMING_METRICS |
| V16 | **P11_PLAYWRIGHT_THROTTLE_FIX** (idem V11), P11_TEST_PRICING_SSOT_PROOF, P11_TEST_IDEMPOTENCY_RACE, P12_PLAYWRIGHT_KDS_LIVE_E2E, P12_TEST_LIVE_RUN_GATE |
| V17 | **P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD**, P11_DEPLOY_PROCEDURE_DOC, P11_I18N_COMPLETE_FR_EN, P13_OBS_SENTRY_BACKEND |
| V18 | P11_FROZEN_ZONE_GATE, P11_PRICING_FRONT_PURGE, P13_VUE_IMPORTS_EXPLICIT, P13_ENV_TO_CONFIG, P13_LOG_HYGIENE, P13_STATUS_TX_HARDEN |
| V19 | **P11_AVAILABILITY_TOGGLE_UI_ADMIN** (idem V01), P11c_AVAILABILITY_TEST_BIDIRECTIONAL, P12_AVAILABILITY_REFACTOR_DEDUPE |
| V20 | **P11_BUSINESS_RULES_DOC_SYNC** (idem V01) — hub doc qui rappelle P11_RETURNED_IDEMPOTENCY, P11_FISCAL_Z_OPEN_HARDENING, P11_COUPON_BRANCH_ISOLATION |

---

*Fin du PLAN POST-VERIFY 2026-04-20.*
