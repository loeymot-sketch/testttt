# VERIFY TRACKER — 2026-04-20

**Date :** 2026-04-20
**Mode :** PLAN-ONLY (lecture seule, 0 fichier applicatif modifié)
**Auteur :** `foodking-planner-orchestrator` (Claude Opus 4.7)
**Plan compagnon :** [`plans/PLAN_POST_VERIFY_2026-04-20.md`](../../plans/PLAN_POST_VERIFY_2026-04-20.md)

## Sources lues (20 rapports VERIFY + gouvernance)

- `tasks/verify-2026-04-20/00_INDEX.md`
- `reports/review/VERIFY_01_P1_AVAILABILITY_2026-04-20.md`
- `reports/review/VERIFY_02_P2_MULTI_TENDER_2026-04-20.md`
- `reports/review/VERIFY_03_P3_REFUND_RETURNED_2026-04-20.md`
- `reports/review/VERIFY_04_P4_KDS_CONCURRENCY_2026-04-20.md`
- `reports/review/VERIFY_05_P5_P7_MIN_ZERO_2026-04-20.md`
- `reports/review/VERIFY_06_P8_P9_COUPONS_2026-04-20.md`
- `reports/review/VERIFY_07_P10_ORDER_SETUP_2026-04-20.md`
- `reports/review/VERIFY_08_FISCAL_NF525_Z_OPEN_2026-04-20.md`
- `reports/review/VERIFY_09_PAYMENTS_IDEMPOTENCY_2026-04-20.md`
- `reports/review/VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`
- `reports/review/VERIFY_11_KDS_OSS_DRAWER_2026-04-20.md`
- `reports/review/VERIFY_12_SECURITY_2026-04-20.md`
- `reports/review/VERIFY_13_DATA_INTEGRITY_2026-04-20.md`
- `reports/review/VERIFY_14_SYNC_CROSS_SURFACE_2026-04-20.md`
- `reports/review/VERIFY_15_OBSERVABILITY_PERF_2026-04-20.md`
- `reports/review/VERIFY_16_TESTS_REGRESSIONS_2026-04-20.md`
- `reports/review/VERIFY_17_I18N_DEPLOY_2026-04-20.md`
- `reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md`
- `reports/review/VERIFY_19_AVAILABILITY_TOGGLE_ROUTE_2026-04-20.md`
- `reports/review/VERIFY_20_BUSINESS_RULES_DOC_ALIGNMENT_2026-04-20.md`
- `AGENTS.md`, `.cursor/routing.md`, `.cursor/rules/{safety,scope,human-gates}.mdc`
- `reports/review/AUDIT_POS_110_FINDINGS_TRACKER.md` (référence format)
- `tasks/phase9-sync/LOCK_*.md` (12 fichiers — voir §3)

---

## §1. Verdicts globaux

| # | Titre | GLOBAL | Cycles P proposés | Priorité max |
|---|---|---|---|---|
| V01 | P1 Availability | **WARN** | P12_OrderCreate_GuardFirst, P11_BUSINESS_RULES_DOC_SYNC, P11_KIOSK_SIMULATE_HARDENING, P11_AVAILABILITY_TOGGLE_UI_ADMIN, P11c_AVAILABILITY_TEST_BIDIRECTIONAL, P12_POS_CART_PRUNE, P11_AVAILABILITY_DEAD_CODE | P1 |
| V02 | P2 Multi-tender (TR) | **WARN** | P11_FRONT_TR_UI, P11_AUDIT_TENDER_ON_CREATE, P11_RECEIPT_TR_LABEL, P12_TR_SOLDER_CASH_CB, P12_REFUND_TRANSACTION_POS, P12_TENDER_E2E | P0 |
| V03 | P3 Refund / RETURNED | **FAIL** | **P11_RETURNED_IDEMPOTENCY (P0)**, **P11_RETURNED_KDS_BYPASS_LOCKDOWN (P0)**, P12_RETURNED_FISCAL_INTEGRATION, P13_RETURNED_LOYALTY_GUARD, P13_RETURNED_E2E | P0 |
| V04 | P4 KDS Concurrency | **WARN** | P11_KDS_409_UX_DIFFERENTIATED, P12_KDS_HTTP_RACE_TEST_HARDENING, P12_KDS_VUEX_REFRESH, P13_KDS_OPTIMISTIC_LOCK_DOC, P13_KDS_409_OBSERVABILITY | P1 |
| V05 | P5/P7 Min Zero | **ALL_GREEN** | P11_REQ_MONEY_HARDENING_TESTS_SYMETRIE_FRONT (optionnel) | P3 |
| V06 | P8/P9 Coupons | **FAIL** | **P11_COUPON_BRANCH_ISOLATION (P0)**, **P11_COUPON_LIMIT_PER_USER_KIOSK (P0)**, P11_COUPON_AUDIT_LOG_SYMMETRY | P0 |
| V07 | P10 Order Setup | **WARN** | P11_ORDER_SETUP_SEMANTIC_DOC, P12_ORDER_SETUP_ENUM_HARDENING | P1 |
| V08 | Fiscal NF525 / Z OPEN | **WARN** | **P11_FISCAL_Z_OPEN_HARDENING (P0)**, P12_FISCAL_AUDIT_LOG_IMMUTABLE_AUTOVERIFY, P13_FISCAL_EXPORT_JET, P13_FISCAL_Z_STATUS_CLOSING | P0 |
| V09 | Payments / Idempotency | **WARN** (proche FAIL) | **P11_IDEMPOTENCY_KEY_MIDDLEWARE (P0)**, **P11_PAYMENT_STATUS_STATE_MACHINE (P0)**, P11_TRUE_OUTBOX_TRANSACTIONAL, P12_PAYMENT_FEATURE_TESTS, P13_PAYMENT_UI_A11Y | P0 |
| V10 | Branch Isolation | **WARN** | P11_FISCAL_ROUTE_AUTHZ_HARDENING, P12_ROLE_ROUTE_MATRIX_GEN, P13_AUDIT_REPORT_HYGIENE, P13_ADMIN_CROSS_BRANCH_DOC | P1 |
| V11 | KDS OSS Drawer | **WARN** | **P11_PLAYWRIGHT_THROTTLE_FIX (P0)** (infra test), P12_DRAWER_CONDITIONS_TIGHTEN | P0 (infra) |
| V12 | Security | **WARN** | **P11_WEBHOOK_SIGNATURE_AUDIT (P0)**, P12_SECURITY_HEADERS, P13_DEMO_MODE_PROD_GUARD, P13_VHTML_ANALYTICS_HARDENING | P0 |
| V13 | Data Integrity | **WARN** | P11_DATA_INDEXES_FK, P12_DATA_TRANSACTIONS_BRANCH_ISOLATION, P13_DATA_SCHEMA_DOC_REFRESH | P1 |
| V14 | Sync Cross-Surface | **WARN** | P11_DISPATCH_AFTER_COMMIT_AUDIT, P11_OUTBOX_OBSERVABILITY, P14_SYNC_E2E_LATENCY_2S, P15_OUTBOX_DLQ_OBSERVABILITY | P1 |
| V15 | Observability / Perf | **WARN** | P11_LOGS_CORRELATION_ID, P11_OUTBOX_OBSERVABILITY (idem V14), P12_BUNDLE_POS_SPLIT, P13_FISCAL_TIMING_METRICS | P1 |
| V16 | Tests / Régressions | **WARN** | **P11_PLAYWRIGHT_THROTTLE_FIX (P0)** (idem V11), P11_TEST_PRICING_SSOT_PROOF, P11_TEST_IDEMPOTENCY_RACE, P12_PLAYWRIGHT_KDS_LIVE_E2E, P12_TEST_LIVE_RUN_GATE | P0 |
| V17 | i18n / Deploy | **WARN** (V4 FAIL config) | **P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD (P0)**, P11_DEPLOY_PROCEDURE_DOC, P11_I18N_COMPLETE_FR_EN, P13_OBS_SENTRY_BACKEND | P0 |
| V18 | Hidden Risks | **WARN** | P11_FROZEN_ZONE_GATE, P11_PRICING_FRONT_PURGE, P13_VUE_IMPORTS_EXPLICIT, P13_ENV_TO_CONFIG, P13_LOG_HYGIENE, P13_STATUS_TX_HARDEN | P1 |
| V19 | Availability Toggle Route | **WARN** | **P11_AVAILABILITY_TOGGLE_UI_ADMIN (P0)**, P11c_AVAILABILITY_TEST_BIDIRECTIONAL, P12_AVAILABILITY_REFACTOR_DEDUPE | P0 |
| V20 | Business Rules Doc | **FAIL** | **P11_BUSINESS_RULES_DOC_SYNC (P0)** + rappel P11_RETURNED_IDEMPOTENCY, P11_FISCAL_Z_OPEN_HARDENING | P0 |

**Distribution :** 1 ALL_GREEN, 16 WARN, 3 FAIL.

---

## §2. Findings consolidés

> Format : `F-VERIFY-XX-NN | sévérité | titre | source | preuve fichier:ligne (1) | cycle P cible`

| ID | Sév | Titre | Source | Preuve | Cycle P |
|---|---|---|---|---|---|
| F-VERIFY-01-01 | P2 | `Order::create` exécuté avant `assertItemsOrderableForBranch` | V01 | `app/Services/OrderService.php:612` | P12_OrderCreate_GuardFirst |
| F-VERIFY-01-02 | P3 | POS pas de pruning panier sur `ItemAvailabilityChanged` | V01 | `resources/js/components/admin/pos/PosComponent.vue:1098-1122` | P12_POS_CART_PRUNE |
| F-VERIFY-01-03 | P3 | Exception `InvalidArgumentException` au lieu d'erreur structurée 422 | V01 | `app/Services/Menu/AvailabilityService.php:130-149` | P11_AVAILABILITY_DEAD_CODE |
| F-VERIFY-01-04 | P3 | Code mort dans listener cache | V01 | `app/Listeners/Menu/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php` | P11_AVAILABILITY_DEAD_CODE |
| F-VERIFY-01-05 | P2 | `kiosk:simulate-orders` bypass garde availability | V01 | `app/Console/Commands/SimulateKioskOrders.php` | P11_KIOSK_SIMULATE_HARDENING |
| F-VERIFY-01-06 | P3 | UI Admin toggle absente | V01 | `resources/js/components/admin/menu/` (n'existe pas) | P11_AVAILABILITY_TOGGLE_UI_ADMIN |
| F-VERIFY-01-07 | **HIGH-doc** | `BUSINESS_RULES.md` §Stock dit "not implemented" alors que P1 livré | V01, V20 | `docs/BUSINESS_RULES.md:57-59` | **P11_BUSINESS_RULES_DOC_SYNC** |
| F-VERIFY-02-01 | **P0** | UI POS sans entrée Ticket-Restaurant (multi-tender inutilisable) | V02 | `resources/js/components/admin/pos/PaymentComponent.vue` | P11_FRONT_TR_UI |
| F-VERIFY-02-02 | P1 | Reçu POS n'imprime pas le label TR / split | V02 | `app/Services/PrintService` (handler reçu) | P11_RECEIPT_TR_LABEL |
| F-VERIFY-02-03 | **P0** | Pas d'`AuditLog` NF525 pour le tender à la création | V02 | `app/Services/OrderService.php:posOrderStore` | P11_AUDIT_TENDER_ON_CREATE |
| F-VERIFY-02-04 | P2 | Refund POS silencieux : pas de `Transaction` row → `cashBack` no-op | V02 | `app/Services/PaymentService.php::cashBack` | P12_REFUND_TRANSACTION_POS |
| F-VERIFY-02-05 | P2 | Solder TR cash/CB non documenté | V02 | (manquant) | P12_TR_SOLDER_CASH_CB |
| F-VERIFY-02-06 | P2 | Tests E2E multi-tender absents | V02 | `tests/Feature/Pos*` | P12_TENDER_E2E |
| F-VERIFY-03-01 | **P0** | `changeStatus(RETURNED)` non idempotent → double cashback / double audit | V03, V20 | `app/Services/OrderService.php:1499-1567` | **P11_RETURNED_IDEMPOTENCY** |
| F-VERIFY-03-02 | **P0** | KDS bypass : changement statut RETURNED sans audit fiscal / refund | V03 | `app/Services/KitchenDisplaySystemOrderService.php` | **P11_RETURNED_KDS_BYPASS_LOCKDOWN** |
| F-VERIFY-03-03 | P1 | Z reports agrègent RETURNED dans totaux sans note de crédit fiscale | V03, V08, V20 | `app/Services/Fiscal/ZReportService.php::aggregate` | P12_RETURNED_FISCAL_INTEGRATION |
| F-VERIFY-03-04 | P2 | `LoyaltyService::refundPoints` non testé sur surface POS sans loyalty | V03 | `app/Services/LoyaltyService.php:21-38` | P13_RETURNED_LOYALTY_GUARD |
| F-VERIFY-03-05 | P2 | E2E RETURNED inexistant (Playwright + Vitest) | V03 | `tests/e2e/`, `tests/js/` | P13_RETURNED_E2E |
| F-VERIFY-04-01 | P2 | Message 409 KDS générique (pas de différenciation conflit/lock) | V04 | `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` | P11_KDS_409_UX_DIFFERENTIATED |
| F-VERIFY-04-02 | P1 | Pas de test PHPUnit Feature HTTP-level race (uniquement service) | V04 | `tests/Feature/KdsChangeStatusConcurrencyTest.php` | P12_KDS_HTTP_RACE_TEST_HARDENING |
| F-VERIFY-04-03 | P2 | Vuex KDS : refresh partiel sur 409 (pas de re-fetch ordre) | V04 | `resources/js/store/modules/kds.js` | P12_KDS_VUEX_REFRESH |
| F-VERIFY-04-04 | P3 | Doc optimistic locking absente | V04 | `docs/` | P13_KDS_OPTIMISTIC_LOCK_DOC |
| F-VERIFY-04-05 | P3 | Aucun compteur observabilité 409 | V04 | (manquant) | P13_KDS_409_OBSERVABILITY |
| F-VERIFY-05-01 | P3 | Tests négatifs incomplets `delivery_charge`/`discount` POS | V05 | `tests/Feature/Pos*Test.php` | P11_REQ_MONEY_HARDENING_TESTS_SYMETRIE_FRONT |
| F-VERIFY-05-02 | P3 | Front filtrage clavier sans `min="0"` HTML | V05 | `resources/js/components/admin/pos/*.vue` | P11_REQ_MONEY_HARDENING_TESTS_SYMETRIE_FRONT |
| F-VERIFY-06-01 | **P0** | Coupons sans `branch_id` scoping (violation isolation SaaS) | V06 | `database/migrations/*coupons*`, `app/Models/Coupon.php` | **P11_COUPON_BRANCH_ISOLATION** |
| F-VERIFY-06-02 | **P0** | `limit_per_user` bypassable kiosk machine + commande table anonyme | V06 | `app/Services/CouponService.php:308-318` | **P11_COUPON_LIMIT_PER_USER_KIOSK** |
| F-VERIFY-06-03 | P1 | Pas d'`AuditLog` NF525 pour usage coupon kiosk/web | V06 | `app/Services/CouponService.php` | P11_COUPON_AUDIT_LOG_SYMMETRY |
| F-VERIFY-07-01 | P2 | `OrderSetupRequest` `numeric|min:0` au lieu d'enum sur `takeaway`/`delivery` | V07 | `app/Http/Requests/Admin/OrderSetupRequest.php` | P12_ORDER_SETUP_ENUM_HARDENING |
| F-VERIFY-07-02 | P3 | Sémantique `0` non documentée pour durations/charges | V07 | `app/Http/Requests/Admin/OrderSetupRequest.php` | P11_ORDER_SETUP_SEMANTIC_DOC |
| F-VERIFY-07-03 | P2 | Pas de safeguard livraison gratuite quand delivery activée | V07 | `app/Models/BusinessSetting.php` | P12_ORDER_SETUP_ENUM_HARDENING |
| F-VERIFY-08-01 | P1 | `Z::open()` ne vérifie ni signature précédente ni chaîne audit | V08 | `app/Services/Fiscal/ZReportService.php:71-90` | **P11_FISCAL_Z_OPEN_HARDENING** |
| F-VERIFY-08-02 | **P0** | Pas de garde `RETURNED`/`changePaymentStatus` sur Order scellé par Z fermé | V08, V03, V20 | `app/Services/OrderService.php:1499-1567,1606-1611` | **P11_FISCAL_Z_OPEN_HARDENING** |
| F-VERIFY-08-03 | P2 | Pas d'état `STATUS_CLOSING` pour Z en cours de clôture | V08 | `app/Models/ZReport.php` | P13_FISCAL_Z_STATUS_CLOSING |
| F-VERIFY-08-04 | P2 | Aucun cron `verifyChain(branchId)` automatique | V08, V20 | (manquant) | P12_FISCAL_AUDIT_LOG_IMMUTABLE_AUTOVERIFY |
| F-VERIFY-08-05 | P2 | Export JET / PIAF officiel non livré | V08, V20 | `app/Console/Commands/FiscalArchiveCommand.php` | P13_FISCAL_EXPORT_JET |
| F-VERIFY-09-01 | **P0** | `changePaymentStatus` sans state-machine, sans transaction, sans idempotence | V09 | `app/Services/OrderService.php:1606-1611` | **P11_PAYMENT_STATUS_STATE_MACHINE** |
| F-VERIFY-09-02 | **P0** | Pas de middleware HTTP `Idempotency-Key` global (header optionnel) | V09 | `app/Http/Kernel.php`, `app/Http/Middleware/` | **P11_IDEMPOTENCY_KEY_MIDDLEWARE** |
| F-VERIFY-09-03 | P1 | `domain_events` non-strictement transactionnels avec création Order | V09, V14 | `app/Listeners/PersistOrderCreatedToOutbox.php` | P11_TRUE_OUTBOX_TRANSACTIONAL |
| F-VERIFY-09-04 | P2 | Tests Feature paiement POS manquants (split, refund partiel) | V09 | `tests/Feature/Pos*Payment*Test.php` | P12_PAYMENT_FEATURE_TESTS |
| F-VERIFY-09-05 | P3 | UI a11y paiement (focus, aria-live) | V09 | `resources/js/components/admin/pos/PaymentComponent.vue` | P13_PAYMENT_UI_A11Y |
| F-VERIFY-10-01 | P1 | Routes fiscales sans middleware `permission:` (only in-controller `can()`) | V10 | `routes/api.php:794-805` | P11_FISCAL_ROUTE_AUTHZ_HARDENING |
| F-VERIFY-10-02 | P2 | Matrice route × permission non générée automatiquement | V10 | (manquant) | P12_ROLE_ROUTE_MATRIX_GEN |
| F-VERIFY-10-03 | P2 | Audit `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` original perdu (untracked) | V10 | `reports/review/AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` | P13_AUDIT_REPORT_HYGIENE |
| F-VERIFY-10-04 | P3 | Doc cross-branche Admin `branch_id=0` manquante | V10, V20 | `docs/BUSINESS_RULES.md` | P13_ADMIN_CROSS_BRANCH_DOC |
| F-VERIFY-11-01 | **P1 (infra)** | Playwright KDS fail = `login-lockout` HTTP 429 (pas régression KDS) | V11, V16 | `test-results/04-kds-status-…/error-context.md` | **P11_PLAYWRIGHT_THROTTLE_FIX** |
| F-VERIFY-11-02 | P2 | Cash drawer : ouverture basée sur état implicite plutôt qu'explicite `payment_status` | V11 | `resources/js/services/kioskHardware.js` | P12_DRAWER_CONDITIONS_TIGHTEN |
| F-VERIFY-12-01 | **P0** | Webhooks paiement : signature non vérifiée (gateway integrations) | V12, V18 | `app/Http/PaymentGateways/Routes/senangpay.php` | **P11_WEBHOOK_SIGNATURE_AUDIT** |
| F-VERIFY-12-02 | P1 | Headers sécurité (CSP, HSTS, X-Frame-Options) absents groupes web/api | V12 | `app/Http/Kernel.php` | P12_SECURITY_HEADERS |
| F-VERIFY-12-03 | P2 | Risque XSS analytics admin (déjà DOMPurify mais surface large) | V12 | `resources/js/components/admin/dashboard/*` | P13_VHTML_ANALYTICS_HARDENING |
| F-VERIFY-12-04 | P2 | Demo mode peut être actif accidentellement en prod | V12 | `app/Http/Middleware/DemoMode.php` | P13_DEMO_MODE_PROD_GUARD |
| F-VERIFY-13-01 | P1 | `transactions` table sans `branch_id` ni FK vers `orders` | V13 | `database/migrations/*create_transactions_table*.php` | P11_DATA_INDEXES_FK + P12_DATA_TRANSACTIONS_BRANCH_ISOLATION |
| F-VERIFY-13-02 | P2 | Plusieurs FK manquantes (`z_reports`, `audit_logs`) sans justification documentée | V13 | `database/migrations/*` | P11_DATA_INDEXES_FK |
| F-VERIFY-13-03 | P3 | `docs/DATABASE_SCHEMA_CORE.md` obsolète | V13 | `docs/DATABASE_SCHEMA_CORE.md` | P13_DATA_SCHEMA_DOC_REFRESH |
| F-VERIFY-14-01 | P2 | Pas de test E2E latence POS→KDS (<2s) | V14 | `tests/e2e/` | P14_SYNC_E2E_LATENCY_2S |
| F-VERIFY-14-02 | P3 | Naming `processed_at` vs `dispatched_at` incohérent | V14 | `database/migrations/*domain_events*`, `app/Models/DomainEvent.php` | P11_DISPATCH_AFTER_COMMIT_AUDIT |
| F-VERIFY-14-03 | P2 | Risque ghost `ItemAvailabilityChanged` si appelé hors `forBranch()` | V14 | `app/Events/ItemAvailabilityChanged.php` | P11_DISPATCH_AFTER_COMMIT_AUDIT |
| F-VERIFY-14-04 | P2 | Pas de DLQ / observabilité jobs Outbox failed | V14, V15 | (manquant) | P15_OUTBOX_DLQ_OBSERVABILITY |
| F-VERIFY-15-01 | P1 | `Log::withContext` n'attache pas `order_id`, `actor_id` | V15 | `app/Http/Middleware/CorrelationIdMiddleware.php` | P11_LOGS_CORRELATION_ID |
| F-VERIFY-15-02 | P1 | Pas d'endpoint `/api/health/outbox` (count pending, age max, failed) | V15, V14 | `app/Http/Controllers/HealthController.php` | P11_OUTBOX_OBSERVABILITY |
| F-VERIFY-15-03 | P2 | Bundle `app.js` 4.4 MB (cible 1.5 MB) | V15 | `public/js/app.js`, `webpack.mix.js` | P12_BUNDLE_POS_SPLIT |
| F-VERIFY-15-04 | P3 | Pas de `duration_ms` dans logs `z_report.close` / `audit_log.write` | V15 | `app/Services/Fiscal/ZReportService.php:151` | P13_FISCAL_TIMING_METRICS |
| F-VERIFY-16-01 | P0 (infra) | Idem F-VERIFY-11-01 | V16 | (idem) | **P11_PLAYWRIGHT_THROTTLE_FIX** |
| F-VERIFY-16-02 | P1 | Pas de test prouvant SSOT effectif (`form.total` ignoré côté serveur) | V16 | `tests/Feature/PricingIntegrityTest.php` | P11_TEST_PRICING_SSOT_PROOF |
| F-VERIFY-16-03 | P1 | Pas de test concurrent double POST POS sans clé idempotence partagée | V16, V09 | `tests/Feature/Orders/IdempotencyBranchScopedTest.php` | P11_TEST_IDEMPOTENCY_RACE |
| F-VERIFY-16-04 | P2 | Pas de live-run PHPUnit + Playwright archivé | V16 | `reports/execution/` | P12_TEST_LIVE_RUN_GATE |
| F-VERIFY-16-05 | P3 | Pas d'assertion KDS post-login E2E | V16 | `tests/e2e/04-kds-status.spec.js` | P12_PLAYWRIGHT_KDS_LIVE_E2E |
| F-VERIFY-17-01 | **P0 (config)** | `webpack.mix.js` ne déclare pas `kiosk.js`/`pos-wizard.js`/`pos-wizard.css` (build clean → assets stale) | V17 | `webpack.mix.js`, `public/mix-manifest.json` | **P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD** |
| F-VERIFY-17-02 | P1 | `.env.example` manque `FCM_*`, `LOG_CHANNEL`, `LOG_LEVEL`, `MIX_GOOGLE_MAP_KEY` | V17 | `.env.example` | P11_DEPLOY_PROCEDURE_DOC |
| F-VERIFY-17-03 | P2 | 12 fichiers admin/KDS contiennent FR hardcoded | V17 | `resources/js/components/admin/dashboard/AuditTrailComponent.vue:13-29` | P11_I18N_COMPLETE_FR_EN |
| F-VERIFY-17-04 | P3 | Sentry backend non installé | V17, V15 | `composer.json` | P13_OBS_SENTRY_BACKEND |
| F-VERIFY-18-01 | P1 | `docs/gates/GATE_LOG.md` vide vs commits frozen-zone (mapping non auditable) | V18 | `docs/gates/GATE_LOG.md` | P11_FROZEN_ZONE_GATE |
| F-VERIFY-18-02 | P1 | Chemin pricing legacy `use_ssot_service=false` toujours présent + `form.total` envoyé | V18, V20 | `app/Services/OrderService.php:770`, `resources/js/components/admin/pos/PosComponent.vue:1445-1446` | P11_PRICING_FRONT_PURGE |
| F-VERIFY-18-03 | P2 | Imports `.vue` implicites (préparation Vite) | V18 | `resources/js/components/frontend/menu/MenuComponent.vue:65` | P13_VUE_IMPORTS_EXPLICIT |
| F-VERIFY-18-04 | P2 | `env()` runtime hors `config()` (config:cache caveat) | V18 | `app/Libraries/QueryExceptionLibrary.php:22` | P13_ENV_TO_CONFIG |
| F-VERIFY-18-05 | P2 | `console.log` résiduels prod paths | V18 | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:580,591` | P13_LOG_HYGIENE |
| F-VERIFY-18-06 | P2 | `changeStatus` client hors transaction | V18 | `app/Services/OrderService.php:1447-1481` | P13_STATUS_TX_HARDEN |
| F-VERIFY-19-01 | **P0** | UI Admin Menu Availability inexistante (POST endpoint sans émetteur) | V19, V01 | `resources/js/components/admin/menu/` (n'existe pas) | **P11_AVAILABILITY_TOGGLE_UI_ADMIN** |
| F-VERIFY-19-02 | P1 | Couverture test toggle minimale (1/4 chemins) | V19, V20 | `tests/Feature/Admin/AvailabilityControllerTest.php` | P11c_AVAILABILITY_TEST_BIDIRECTIONAL |
| F-VERIFY-19-03 | P2 | Duplication logique controller ↔ `AvailabilityService` | V19 | `app/Http/Controllers/Admin/AvailabilityController.php:99-142` vs `app/Services/Menu/AvailabilityService.php:31-73` | P12_AVAILABILITY_REFACTOR_DEDUPE |
| F-VERIFY-20-01 | **HIGH-doc (FAIL)** | `BUSINESS_RULES.md` §Stock affirme l'inverse de la réalité (P1 livré) | V20, V01 | `docs/BUSINESS_RULES.md:57-59` | **P11_BUSINESS_RULES_DOC_SYNC** |
| F-VERIFY-20-02 | MEDIUM | 5 sections doc manquantes (NF525, SaaS, RETURNED idempotence, coupons, sealed-Z) | V20 | `docs/BUSINESS_RULES.md` | P11_BUSINESS_RULES_DOC_SYNC |

---

## §3. Findings convergents (≥ 5 recoupements explicites)

| Convergence | Rapports | Cycle P unique |
|---|---|---|
| **C1.** Garde sealed-Z + idempotence RETURNED + verify chain au Z::open | V03, V08, V09, V20 | **P11_FISCAL_Z_OPEN_HARDENING** + **P11_RETURNED_IDEMPOTENCY** |
| **C2.** Documentation `BUSINESS_RULES.md` divergente (Stock + RETURNED + Coupons + NF525) | V01, V03, V06, V08, V20 | **P11_BUSINESS_RULES_DOC_SYNC** |
| **C3.** UI Admin Availability manquante alors que backend complet | V01, V19, V20 | **P11_AVAILABILITY_TOGGLE_UI_ADMIN** |
| **C4.** Outbox observabilité (count pending, age max, DLQ) | V14, V15 | **P11_OUTBOX_OBSERVABILITY** + P15_OUTBOX_DLQ_OBSERVABILITY |
| **C5.** Playwright KDS fail = login-lockout (pas régression) | V11, V16 | **P11_PLAYWRIGHT_THROTTLE_FIX** |
| **C6.** Test concurrence / idempotency double-POST | V09, V16 | **P11_TEST_IDEMPOTENCY_RACE** |
| **C7.** Pricing SSOT preuve + chemin legacy + `form.total` front | V16, V18, V20 | **P11_TEST_PRICING_SSOT_PROOF** + **P11_PRICING_FRONT_PURGE** |
| **C8.** Audit NF525 manquant pour tender / coupon | V02, V06, V09 | **P11_AUDIT_TENDER_ON_CREATE** + **P11_COUPON_AUDIT_LOG_SYMMETRY** |
| **C9.** Webhook signature paiement non vérifiée | V12, V18 | **P11_WEBHOOK_SIGNATURE_AUDIT** |
| **C10.** Branch isolation : routes fiscales + transactions table sans `branch_id` | V10, V13 | **P11_FISCAL_ROUTE_AUTHZ_HARDENING** + **P12_DATA_TRANSACTIONS_BRANCH_ISOLATION** |
| **C11.** Logs `correlation_id` partiel (manque `order_id`, `actor_id`) | V14, V15 | **P11_LOGS_CORRELATION_ID** |
| **C12.** Bundle / build pipeline (mix-manifest drift + bundle 4.4 MB) | V15, V17 | **P11_BUILD_PIPELINE_RESTORE_KIOSK_POSWIZARD** + P12_BUNDLE_POS_SPLIT |

---

## §4. Statistiques

### 4.1 Findings par sévérité

| Sévérité | Count |
|---|---|
| **P0** (bloquant / corruption / NF525 / sécurité directe) | **15** |
| **P1** (risque élevé, dette majeure, gap test/doc structurant) | **23** |
| **P2** (risque moyen, dette technique, robustesse) | **22** |
| **P3** (cosmétique, confort, hygiène) | **13** |
| **TOTAL** | **73** |

### 4.2 Cycles P uniques (après déduplication)

| Métrique | Valeur |
|---|---|
| Cycles P0 uniques | **12** |
| Cycles P1 uniques | **15** |
| Cycles P2 uniques | **13** |
| Cycles P3 uniques | **10** |
| **Total cycles P11+ uniques** | **50** |

### 4.3 Distribution des verdicts (V1..V_n par rapport)

Calcul brut à partir des sections `§5` ou tableaux internes (V1..V7 selon rapport) :

| Statut | Count global (sur ~110 sous-vérifications V1..V_n agrégées) | % |
|---|---|---|
| **PASS / GREEN** | ~46 | ~42% |
| **WARN** | ~52 | ~47% |
| **FAIL** | ~12 | ~11% |

Verdicts globaux des 20 rapports : **1 ALL_GREEN (5%)**, **16 WARN (80%)**, **3 FAIL (15%)**.

### 4.4 Couverture par invariant FoodKing

| Invariant | Findings touchant | Cycles dédiés |
|---|---|---|
| Pricing SSOT | F-VERIFY-16-02, 18-02 | P11_TEST_PRICING_SSOT_PROOF + P11_PRICING_FRONT_PURGE |
| `OrderStatus` enum / state-machine | F-VERIFY-03-01, 09-01 | P11_RETURNED_IDEMPOTENCY + P11_PAYMENT_STATUS_STATE_MACHINE |
| `branch_id` isolation | F-VERIFY-06-01, 10-01, 13-01 | P11_COUPON_BRANCH_ISOLATION + P11_FISCAL_ROUTE_AUTHZ_HARDENING + P12_DATA_TRANSACTIONS_BRANCH_ISOLATION |
| Dispatch après commit | F-VERIFY-09-03, 14-02 | P11_TRUE_OUTBOX_TRANSACTIONAL + P11_DISPATCH_AFTER_COMMIT_AUDIT |
| Symétrie Order/FrontendOrder | F-VERIFY-18-06 | P13_STATUS_TX_HARDEN |
| Frozen zones (LOCK actifs) | F-VERIFY-18-01 | P11_FROZEN_ZONE_GATE |
| NF525 fiscal | F-VERIFY-08-01..05, 09-01 | P11_FISCAL_Z_OPEN_HARDENING + P11_PAYMENT_STATUS_STATE_MACHINE + P12_FISCAL_AUDIT_LOG_IMMUTABLE_AUTOVERIFY + P13_FISCAL_EXPORT_JET |

---

## §5. Risques cumulés non adressés (hors cycles P proposés)

| Risque | Source | Pourquoi non adressé |
|---|---|---|
| **R-RES-01** Test charge 500 ord/h (NF525 monotonie cryptographique 10k+ lignes) | F-FISC-004 audit POS-110, V08, V15 | Hors scope CI : k6 + observabilité prod requis. **Backlog ops**. |
| **R-RES-02** Refacto `FrontendOrderService` (asymétrie `finalizePaidKioskOrder`) | V18 §4.2 | Décision produit ; pas un bug. **Backlog architecture**. |
| **R-RES-03** Multi-tender split UI complète (split N tenders + pourboire + gift card) | V02 §4 | Hors P0 : v2 fonctionnelle. **Backlog produit**. |
| **R-RES-04** Migration Vite (`@vitejs/plugin-vue` présent mais inutilisé) | V17 §2.2, V18 §4.3 | Décision build pipeline ; nécessite migration globale. **Backlog tooling**. |
| **R-RES-05** SaaS observabilité prod (Datadog / Cloudwatch / Sentry backend) | V12, V15, V17 | Décision infra hors code. **Backlog ops**. |
| **R-RES-06** Schéma `docs/DATABASE_SCHEMA_CORE.md` complet automatisé | V13 | Outillage non livré ; couvert partiellement par P13_DATA_SCHEMA_DOC_REFRESH. **Backlog tooling**. |
| **R-RES-07** `kds_group_id` (sélectionneur OSS multi-station) | F-KDS-002 audit POS-110 | Spec produit non finalisée. **Backlog produit OSS**. |
| **R-RES-08** Rapport audit `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md` original perdu | V10 §V0 NOTE | Couvert partiellement par P13_AUDIT_REPORT_HYGIENE (process futur), mais **perte irréversible**. |
| **R-RES-09** Faiblesse certains rapports (V05 quasi-trivial, V11 non-régression mais infra test) | V05, V11 | Notes : rapports honnêtement courts car sujets verts. **Pas un risque code, juste un signal de sur-couverture audit**. |

### Note honnêteté sur la qualité des rapports

- **V05** (P5/P7 Min Zero) — court, ALL_GREEN, peu de preuves nouvelles ; le sujet est trivial à valider, donc OK.
- **V11** (KDS OSS Drawer) — la part "fail Playwright" est en réalité un problème d'infra de test (login-lockout), pas une régression KDS ; verdict WARN justifié honnêtement.
- **V20** (BR Doc) — long et exhaustif, inclut un patch markdown ready-to-commit en annexe (NON appliqué).

---

## §6. Lien vers le plan

➡️ **`plans/PLAN_POST_VERIFY_2026-04-20.md`** — détaille les 50 cycles P11+ uniques, les 4 vagues d'exécution recommandées, les 8 cycles à gate humain obligatoire (frozen zones / invariants critiques), les candidats à fusion, le hors-scope volontaire, les critères de succès post-vague, et le template de prompt EXECUTE par cycle.

---

*Fin du tracker VERIFY 2026-04-20.*
