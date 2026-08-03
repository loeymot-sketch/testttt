# TRACEABILITY MATRIX — CAISSE V1

## 0. Verdict

`TRACEABILITY_STATUS: COMPLETE`

Matrice complete et machine-verifiable pour Caisse V1. Les gates sont cites comme dependances; aucune approbation humaine n est cochee.

## 1. Compteurs

- Total findings: 102
- Severity split: P0=44, P1=37, P2=20, INFO=1
- Avec Test_Command explicite: 80/102 (78%)
- Avec Gate: 50/102 (49%)
- Unmapped: 0/102 (0%)

## 2. Matrice principale

| FK-ID | Source | Description | Severity | Plan-ID | TASK_ID | Sentinel | Test_Command | Gate | Owner | Status | Evidence |
|-------|--------|-------------|----------|---------|---------|----------|--------------|------|-------|--------|----------|
| FK-001 | MASTER_REQUEST_CV1;CLAUDE_SUPER_MASTER_REVIEW | Cycle produit requis avant toute correction Caisse V1 | P0 | PLAN-00 | CV1-M01-TRACEABILITY-MATRIX | (none) | bash scripts/check-traceability.sh | (none) | QA | in_progress | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:306 |
| FK-002 | CLAUDE_SUPER_MASTER_REVIEW | Plan initial lineaire insuffisant; DAG plan-of-plans requis | P0 | PLAN-00 | CV1-M01-TRACEABILITY-MATRIX | (none) | bash scripts/check-traceability.sh | (none) | QA | in_progress | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:71 |
| FK-003 | CLAUDE_SUPER_MASTER_REVIEW | Allowlist, denylist et audit prompt manquent par TASK_ID | P0 | PLAN-00 | CV1-M01-TRACEABILITY-MATRIX | (none) | bash scripts/check-traceability.sh | (none) | QA | in_progress | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:75 |
| FK-004 | CLAUDE_SUPER_MASTER_REVIEW | Choix paiement ledger full vs pilote restreint non separe | P0 | PLAN-03 | CV1-M03-GATES-DRAFT | (none) | PREUVE_MANQUANTE | GATE_PAYMENT_LEDGER_V1 | Human | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:72 |
| FK-005 | CLAUDE_SUPER_MASTER_REVIEW | Rollback, canary et feature flags absents du plan | P0 | PLAN-15 | CV1-M15-ROLLOUT-CANARY | RolloutCanaryDrillTest | bash runbooks/rollout-canary-drill.sh | (none) | DevOps | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:73 |
| FK-006 | CLAUDE_SUPER_MASTER_REVIEW | Ops runtime, migrations et observabilite trop vagues | P0 | PLAN-14 | CV1-M14-OPS-PREFLIGHT | OpsPreflightCaisseV1Test | bash scripts/ops-preflight-caisse-v1.sh | (none) | DevOps | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:74 |
| FK-007 | CLAUDE_SUPER_MASTER_REVIEW | Hardware lab-readiness doit demarrer en Phase 0 | P1 | PLAN-16 | CV1-M16-HARDWARE-LAB | HardwareTpeTimeoutTest | PREUVE_MANQUANTE | (none) | Ops | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:76 |
| FK-008 | CLAUDE_SUPER_MASTER_REVIEW | Threat-model branch isolation doit couvrir 7 surfaces | P0 | PLAN-09 | CV1-M09-BRANCH-ISOLATION | OrderListBranchExactnessSentinelTest | php artisan test --filter=OrderListBranchExactness | GATE_FROZEN_ZONES_CAISSE_V1 | BE | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:77 |
| FK-009 | CLAUDE_SUPER_MASTER_REVIEW | Sentinelles sans expected_state ni evidence_artifact | P0 | PLAN-02 | CV1-M02-SENTINEL-BASELINE | PaymentConfirmAbilitySentinelTest | php artisan test --filter=PaymentConfirmAbility | (none) | QA | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:78 |
| FK-010 | CLAUDE_SUPER_MASTER_REVIEW | Fiscal Z, refund, void et HMAC insuffisamment detailles | P0 | PLAN-08 | CV1-M08-FISCAL-Z-NF525 | FiscalSealingHmacTest | php artisan test --filter=FiscalSealingHmac | GATE_FISCAL_KIOSK_V1 | BE | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:79 |
| FK-011 | CLAUDE_SUPER_MASTER_REVIEW | Legacy bypass guards CI doivent etre grep, AST et bundle scan | P0 | PLAN-12 | CV1-M12-LEGACY-GUARDS-CI | LegacyImportGuardLintTest | npm run lint:fk-legacy | (none) | DevOps | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:80 |
| FK-012 | CLAUDE_SUPER_MASTER_REVIEW;MEGA_PLAN_READINESS_GAP_ANALYSIS | Graphiti et fallback memory/INDEX doivent etre prouves | P1 | PLAN-19 | CV1-M19-MEMORY-DISCIPLINE | (none) | python3 memory/verify.py | (none) | QA | verified | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:136 |
| FK-013 | CLAUDE_SUPER_MASTER_REVIEW | Traceability finding to task/test/gate doit etre exportable | P0 | PLAN-01 | CV1-M01-TRACEABILITY-MATRIX | (none) | bash scripts/check-traceability.sh | (none) | QA | in_progress | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:82 |
| FK-014 | CLAUDE_SUPER_MASTER_REVIEW | Monitoring post-launch et alerting anomalies absents | P1 | PLAN-22 | CV1-M22-POST-LAUNCH-OBSERVABILITY | (none) | PREUVE_MANQUANTE | (none) | DevOps | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:83 |
| FK-015 | CLAUDE_SUPER_MASTER_REVIEW | Quote security spec formelle HMAC TTL replay requise | P0 | PLAN-05 | CV1-M05-ORDER-QUOTE | QuoteTamperTest | php artisan test --filter=QuoteTamper | GATE_SCHEMA_MIGRATIONS_V1 | BE | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:84 |
| FK-016 | CLAUDE_SUPER_MASTER_REVIEW;MASTER_REVIEW_POS_KDS_FINITIONS | OS/FOS symmetry contract test requis | P1 | PLAN-10 | CV1-M10-OS-FOS-SYMMETRY | (none) | php artisan test --filter=OrderServiceFrontendOrderServiceContract | GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 | BE | planned | reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:85 |
| FK-017 | AUDIT_POS:F-001;AUDIT_POS:T-001;MEGA_RAPPORT_FINAL_DISPUTE | POS encaisse encore sur un total local non quote | P0 | PLAN-05 | CV1-M05-ORDER-QUOTE | PosTotalServerAuthoritativeSentinelTest | php artisan test --filter=PosTotalServerAuthoritative | GATE_SCHEMA_MIGRATIONS_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:328 |
| FK-018 | AUDIT_POS:F-002;AUDIT_POS:T-002 | Gate remise POS base sur subtotal client forgeable | P0 | PLAN-06 | CV1-M06-POS-REVENUE-GUARDS | PosSubtotalForgerySentinelTest | php artisan test --filter=PosSubtotalForgery | GATE_FROZEN_ZONES_CAISSE_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:334 |
| FK-019 | AUDIT_POS:F-003;AUDIT_POS:T-003 | Paiement POS sans ledger ni state machine moderne | P0 | PLAN-04A | CV1-M04A-PAYMENT-LEDGER-FULL | PaymentLedgerStateMachineTest | php artisan test --filter=PaymentLedgerStateMachine | GATE_PAYMENT_LEDGER_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:340 |
| FK-020 | AUDIT_POS:F-004;AUDIT_POS:T-004;CLAUDE_SUPER_MASTER_REVIEW | Queue number non unique sous fallback microtime | P0 | PLAN-13 | CV1-M13-MIGRATIONS-SAFETY | QueueNumberUniquenessSentinelTest | php artisan test --filter=QueueNumberUniqueness | GATE_SCHEMA_MIGRATIONS_V1 | BE+DBA | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:346 |
| FK-021 | AUDIT_POS:F-005;AUDIT_POS:T-005;MASTER_REQUEST_CV1 | Catch duplicate idempotency POS non branch-scope | P1 | PLAN-09 | CV1-M09-BRANCH-ISOLATION | (none) | php artisan test --filter=PosIdempotencyBranchScope | GATE_FROZEN_ZONES_CAISSE_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:352 |
| FK-022 | AUDIT_POS:F-006;AUDIT_POS:T-006;MASTER_REQUEST_CV1 | Requests status/payment acceptent trop large | P1 | PLAN-10 | CV1-M10-OS-FOS-SYMMETRY | OrderStatusEnumKioskHardcodeSentinelTest | npm run lint:fk-enum | (none) | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:358 |
| FK-023 | AUDIT_POS:F-007;AUDIT_POS:T-007;MEGA_RAPPORT_FINAL_DISPUTE;KIOSK-DEEP-018 | POS collecte le cash kiosk via endpoint KDS | P0 | PLAN-06 | CV1-M06-POS-REVENUE-GUARDS | PosCashEndpointSentinelTest | php artisan test --filter=PosCollectKioskCashRoute | GATE_FROZEN_ZONES_CAISSE_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:364 |
| FK-024 | AUDIT_POS:F-008;AUDIT_POS:T-008 | Money model reste float/decimal applicatif | P1 | PLAN-04A | CV1-M04A-PAYMENT-LEDGER-FULL | (none) | php artisan test --filter=MoneyRounding | GATE_PAYMENT_LEDGER_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:370 |
| FK-025 | AUDIT_POS:F-009;AUDIT_POS:T-009;AUDIT_POS:T-024 | Device hardware TPE, printer, drawer non valide reel | P1 | PLAN-16 | CV1-M16-HARDWARE-LAB | HardwareTpeTimeoutTest | PREUVE_MANQUANTE | (none) | Ops | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:376 |
| FK-026 | AUDIT_POS:F-010;AUDIT_POS:T-017;MEGA_RAPPORT_FINAL_DISPUTE | Route web payment expose un order id brut | P0 | PLAN-17 | CV1-M17-WEB-STRIPE-SCOPE | (none) | php artisan test --filter=SignedPaymentIntent | GATE_WEB_PAYMENT_SCOPE_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:382 |
| FK-027 | AUDIT_POS:F-011;AUDIT_POS:T-015;MEGA_RAPPORT_FINAL_DISPUTE | Stripe convertit mal les decimaux en cents si actif | P0 | PLAN-17 | CV1-M17-WEB-STRIPE-SCOPE | (none) | php artisan test --filter=StripeCentsConversion | GATE_STRIPE_CENTS_ACTIVE | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:388 |
| FK-028 | AUDIT_POS:F-012;AUDIT_POS:T-018 | Transactions et PaymentService sans idempotence financiere | P0 | PLAN-04A | CV1-M04A-PAYMENT-LEDGER-FULL | PaymentLedgerStateMachineTest | php artisan test --filter=PaymentProviderReferenceUnique | GATE_PAYMENT_LEDGER_V1 | BE+DBA | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:394 |
| FK-029 | AUDIT_POS:F-013;AUDIT_KIOSK:KIOSK-DEEP-013 | Kiosk TPE confirme sans ledger ni verification montant | P0 | PLAN-06 | CV1-M06-POS-REVENUE-GUARDS | PaymentConfirmAbilitySentinelTest | php artisan test --filter=PaymentConfirmAbility | GATE_FROZEN_ZONES_CAISSE_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:400 |
| FK-030 | AUDIT_POS:F-014;AUDIT_POS:T-016;MEGA_RAPPORT_FINAL_DISPUTE | CB/TR kiosk offline peut payer sans commande reconciliable | P0 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | KioskCbTrOfflineRefusedSentinelTest | npx playwright test tests/e2e/kiosk-offline-cb-refused.spec.js | GATE_OFFLINE_SCOPE_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:406 |
| FK-031 | AUDIT_POS:F-015;AUDIT_POS:T-021;AUDIT_KIOSK:KIOSK-DEEP-004 | Status CANCELED=16 duplique cote kiosk/frontend | P0 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | OrderStatusEnumKioskHardcodeSentinelTest | npm run lint:fk-enum | (none) | FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:412 |
| FK-032 | AUDIT_POS:F-016;AUDIT_POS:T-019 | POS wizard garde des prix et recaps locaux | P1 | PLAN-05 | CV1-M05-ORDER-QUOTE | PosTotalServerAuthoritativeSentinelTest | php artisan test --filter=PosTotalServerAuthoritative | GATE_SCHEMA_MIGRATIONS_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:418 |
| FK-033 | AUDIT_POS:F-017;AUDIT_POS:T-020 | KDS/OSS traitent branch_id=0 global sans role Admin strict | P0 | PLAN-09 | CV1-M09-BRANCH-ISOLATION | OrderShowBranchGuardSentinelTest | php artisan test --filter=OssAdminBranchPolicy | GATE_FROZEN_ZONES_CAISSE_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:424 |
| FK-034 | AUDIT_POS:F-018;AUDIT_POS:T-022 | Credit wallet peut double-debiter sous callback concurrent | P1 | PLAN-04A | CV1-M04A-PAYMENT-LEDGER-FULL | (none) | php artisan test --filter=CreditWalletIdempotency | GATE_PAYMENT_LEDGER_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:430 |
| FK-035 | AUDIT_POS:F-019;AUDIT_POS:T-023 | POS V4 public expose configs runtime non minimales | P2 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | npx vitest run tests/js/posBootConfig.spec.js | (none) | FE | deferred | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:436 |
| FK-036 | AUDIT_POS:F-020;AUDIT_POS:T-025;AUDIT_POS:T-026;MEGA_PLAN_READINESS_GAP_ANALYSIS | Contrat OrderIntent commun POS/kiosk/web/table absent | P0 | PLAN-05 | CV1-M05-ORDER-QUOTE | (none) | php artisan test --filter=OrderIntentContract | GATE_SCHEMA_MIGRATIONS_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:442 |
| FK-037 | AUDIT_POS:F-021;AUDIT_POS:T-027;MEGA_RAPPORT_FINAL_DISPUTE | Kitchen release implicite via status, pas ticket explicite | P0 | PLAN-07 | CV1-M07-KDS-RELEASE | KdsTransitionWhitelistSentinelTest | php artisan test --filter=KitchenReleaseRule | GATE_KDS_BUMP_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:448 |
| FK-038 | AUDIT_POS:F-022;AUDIT_POS:T-028;MEGA_RAPPORT_FINAL_DISPUTE | KDS hard-cap 50 peut masquer des tickets | P1 | PLAN-07 | CV1-M07-KDS-RELEASE | (none) | php artisan test --filter=KdsPaginationOverflow | GATE_KDS_BUMP_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:454 |
| FK-039 | AUDIT_POS:F-023;AUDIT_POS:T-029 | KDS dedupe utilise updated_at en secondes | P1 | PLAN-07 | CV1-M07-KDS-RELEASE | (none) | php artisan test --filter=KdsVersionMonotonic | GATE_KDS_BUMP_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:460 |
| FK-040 | AUDIT_POS:F-024;AUDIT_POS:T-030 | Admin global KDS degrade en polling non realtime role-checke | P1 | PLAN-09 | CV1-M09-BRANCH-ISOLATION | (none) | php artisan test --filter=KdsGlobalAdminRealtime | GATE_FROZEN_ZONES_CAISSE_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:466 |
| FK-041 | AUDIT_POS:F-025;AUDIT_POS:T-031 | Web/table PENDING visibles client mais non transmis KDS | P1 | PLAN-07 | CV1-M07-KDS-RELEASE | (none) | php artisan test --filter=WebTableAcceptanceSla | (none) | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:472 |
| FK-042 | AUDIT_POS:F-026;AUDIT_POS:T-032;AUDIT_KIOSK:KIOSK-DEEP-018 | Cash kiosk couple encaissement et statut cuisine | P1 | PLAN-06 | CV1-M06-POS-REVENUE-GUARDS | PosCashEndpointSentinelTest | php artisan test --filter=PosCollectKioskCashRoute | GATE_FROZEN_ZONES_CAISSE_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:478 |
| FK-043 | AUDIT_POS:F-027 | OrderCreated est trop large pour piloter le KDS | P1 | PLAN-07 | CV1-M07-KDS-RELEASE | (none) | php artisan test --filter=KitchenReleaseRule | GATE_KDS_BUMP_V1 | BE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:484 |
| FK-044 | AUDIT_POS:F-028;AUDIT_POS:T-016 | Offline kiosk CB/TR casse commande paiement KDS | P0 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | KioskCbTrOfflineRefusedSentinelTest | npx playwright test tests/e2e/kiosk-offline-cb-refused.spec.js | GATE_OFFLINE_SCOPE_V1 | BE+FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:490 |
| FK-045 | AUDIT_POS:T-011 | Upsell POS backend absent | P2 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | npx vitest run tests/js/posUpsellBackend.spec.js | (none) | FE | deferred | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:737 |
| FK-046 | AUDIT_POS:T-012;MASTER_REVIEW_POS_KDS_FINITIONS | Pass UX/a11y POS incomplet | P2 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | npx playwright test tests/e2e/pos-a11y.spec.js | (none) | FE | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:738 |
| FK-047 | AUDIT_POS:T-013 | Matrice tests CI production manquante | P2 | PLAN-18 | CV1-M18-TEST-ARCHITECTURE | (none) | PREUVE_MANQUANTE | (none) | QA | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:739 |
| FK-048 | AUDIT_POS:T-014 | Roadmap SaaS V2 tenant isolation non tranchee | P2 | PLAN-22 | CV1-M22-POST-LAUNCH-OBSERVABILITY | (none) | PREUVE_MANQUANTE | (none) | Product | deferred | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:740 |
| FK-049 | AUDIT_POS:T-033 | Matrice E2E page par page jusqu au KDS manquante | P1 | PLAN-18 | CV1-M18-TEST-ARCHITECTURE | (none) | PREUVE_MANQUANTE | (none) | QA | planned | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:759 |
| FK-050 | AUDIT_POS:T-034 | Instrumentation abandon et upsell par etape absente | P2 | PLAN-22 | CV1-M22-POST-LAUNCH-OBSERVABILITY | (none) | PREUVE_MANQUANTE | (none) | Product | deferred | reports/audit/AUDIT_TOTAL_SYSTEME_FOCUS_CAISSE_POS_2026-04-25.md:760 |
| FK-051 | AUDIT_KIOSK:KIOSK-DEEP-001;T-KIOSK-001 | Kiosk charge le menu par anciens endpoints et premiere branche | P0 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | (none) | npx vitest run tests/js/kiosk-menu-source.spec.js | (none) | FE | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:164 |
| FK-052 | AUDIT_KIOSK:KIOSK-DEEP-002;T-KIOSK-002 | Logique locale addons/prix encore active cote borne | P0 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | KioskPromoPreviewCheckoutParitySentinelTest | npx vitest run tests/js/kiosk-pricing-ssot.spec.js | (none) | FE | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:165 |
| FK-053 | AUDIT_KIOSK:KIOSK-DEEP-003;KIOSK-DEEP-015;T-KIOSK-003 | Identifiant offline kiosk genere incompatible avec routes | P0 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | KioskOfflineIdPrefixSentinelTest | npx vitest run tests/js/kiosk-offline-id-prefix.spec.js | GATE_OFFLINE_SCOPE_V1 | FE | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:166 |
| FK-054 | AUDIT_KIOSK:KIOSK-DEEP-005;T-KIOSK-005 | Upsell kiosk utilise endpoint ancien non branch-scope strict | P1 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | (none) | npx vitest run tests/js/kiosk-upsell-branch.spec.js | (none) | FE | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:168 |
| FK-055 | AUDIT_KIOSK:KIOSK-DEEP-006;T-KIOSK-006;MASTER_REQUEST_CV1 | Promo kiosk preview/final ou champ discount_amount divergent | P0 | PLAN-05 | CV1-M05-ORDER-QUOTE | KioskPromoPreviewCheckoutParitySentinelTest | npx vitest run tests/js/kiosk-promo-preview-checkout.spec.js | GATE_SCHEMA_MIGRATIONS_V1 | BE+FE | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:322 |
| FK-056 | AUDIT_KIOSK:KIOSK-DEEP-007;KIOSK-DEEP-016;KIOSK-DEEP-017 | Analytics kiosk offline v2 et sendBeacon non fiables | P1 | PLAN-22 | CV1-M22-POST-LAUNCH-OBSERVABILITY | (none) | npx vitest run tests/js/kiosk-analytics-transport.spec.js | (none) | FE+DevOps | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:170 |
| FK-057 | AUDIT_KIOSK:KIOSK-DEEP-008 | Cartes produits kiosk ont interactions imbriquees faibles | P1 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | npx playwright test tests/e2e/kiosk-product-cards.spec.js | (none) | FE | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:171 |
| FK-058 | AUDIT_KIOSK:KIOSK-DEEP-009 | Cash kiosk marque paye/accepte immediatement | P1 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | (none) | php artisan test --filter=KioskCashPaymentPolicy | GATE_FISCAL_KIOSK_V1 | BE+FE | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:172 |
| FK-059 | AUDIT_KIOSK:KIOSK-DEEP-010;T-KIOSK-010 | Docs API kiosk decrivent ancien modele non runtime | P1 | PLAN-20 | CV1-M20-RUNBOOKS-SKELETON | (none) | PREUVE_MANQUANTE | (none) | Product | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:173 |
| FK-060 | AUDIT_KIOSK:KIOSK-DEEP-011;T-KIOSK-011 | Provisioning kiosk garde credentials et branche par defaut | P2 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | (none) | php artisan test --filter=KioskProvisioningSecurity | (none) | BE | deferred | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:174 |
| FK-061 | AUDIT_KIOSK:KIOSK-DEEP-012;T-KIOSK-009 | Tests kiosk ne capturent pas les vrais bugs frontend | P2 | PLAN-18 | CV1-M18-TEST-ARCHITECTURE | (none) | npx playwright test tests/e2e/03-kiosk-wizard.spec.js | (none) | QA | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:175 |
| FK-062 | AUDIT_KIOSK:KIOSK-DEEP-014 | Fiscalite kiosk/Z a options A/B/C non tranchees | P0 | PLAN-08 | CV1-M08-FISCAL-Z-NF525 | ZAggregationKioskRoutingTest | php artisan test --filter=ZAggregationKioskRouting | GATE_FISCAL_KIOSK_V1 | BE | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:1174 |
| FK-063 | AUDIT_KIOSK:KIOSK-DEEP-019 | PIN admin kiosk fallback backend 1234 en prod | P1 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | (none) | php artisan test --filter=KioskAdminPinFallback | (none) | BE+FE | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:1340 |
| FK-064 | AUDIT_KIOSK:KIOSK-DEEP-020 | KioskMachine manque contraintes DB et filtres exacts | P2 | PLAN-13 | CV1-M13-MIGRATIONS-SAFETY | (none) | php artisan test --filter=KioskMachineUniqueness | GATE_SCHEMA_MIGRATIONS_V1 | BE+DBA | deferred | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:1372 |
| FK-065 | AUDIT_KIOSK:KIOSK-DEEP-021 | Route loyalty scan manque middleware ability route-level | P1 | PLAN-11 | CV1-M11-KIOSK-RUNTIME | (none) | php artisan test --filter=KioskLoyaltyScanAbility | (none) | BE | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:1399 |
| FK-066 | AUDIT_KIOSK:KIOSK-DEEP-022 | Design System kiosk present mais pas runtime canonique | P2 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | PREUVE_MANQUANTE | (none) | FE | deferred | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:1423 |
| FK-067 | AUDIT_KIOSK:T-KIOSK-012;CLAUDE_SUPER_MASTER_REVIEW | Prototypes kiosk doivent etre marques archive non-runtime | P1 | PLAN-12 | CV1-M12-LEGACY-GUARDS-CI | LegacyImportGuardLintTest | npm run lint:fk-legacy | (none) | DevOps | planned | reports/audit/AUDIT_COMPLET_BORNE_KIOSK_CONNECTEE_DEEP_2026-04-25.md:1020 |
| FK-068 | MASTER_REQUEST_CV1 | KDS multi-ecran manque expected_status explicite | P0 | PLAN-07 | CV1-M07-KDS-RELEASE | KdsExpectedStatusConflictSentinelTest | php artisan test --filter=KdsExpectedStatusConflict | GATE_KDS_BUMP_V1 | BE+FE | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:369 |
| FK-069 | MASTER_REQUEST_CV1 | KDS list et orderItems divergent sur PREPARED | P1 | PLAN-07 | CV1-M07-KDS-RELEASE | (none) | php artisan test --filter=KdsOrderItemsListParity | GATE_KDS_BUMP_V1 | BE | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:429 |
| FK-070 | MASTER_REQUEST_CV1 | Availability event peut partir avant commit | P1 | PLAN-14 | CV1-M14-OPS-PREFLIGHT | AfterCommitDispatchTest | php artisan test --filter=DispatchAfterCommit | (none) | BE | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:465 |
| FK-071 | MASTER_REQUEST_CV1 | DiningTable release incomplet entre table et commande | P1 | PLAN-10 | CV1-M10-OS-FOS-SYMMETRY | (none) | php artisan test --filter=DiningTableReleaseAfterPosOrder | (none) | BE | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:483 |
| FK-072 | MASTER_REQUEST_CV1 | Legacy kiosk contient logique non authoritative | P1 | PLAN-12 | CV1-M12-LEGACY-GUARDS-CI | LegacyImportGuardLintTest | npm run lint:fk-legacy | (none) | DevOps | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:502 |
| FK-073 | MASTER_REQUEST_CV1 | POS UI paiement incomplet vs backend enum TR | P1 | PLAN-06 | CV1-M06-POS-REVENUE-GUARDS | (none) | npx vitest run tests/js/pos-ticket-restaurant.spec.js | GATE_PAYMENT_LEDGER_V1 | BE+FE | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:520 |
| FK-074 | MASTER_REQUEST_CV1 | Multi-payment et remboursements partiels limites | P1 | PLAN-04A | CV1-M04A-PAYMENT-LEDGER-FULL | (none) | php artisan test --filter=PartialRefundLedger | GATE_PAYMENT_LEDGER_V1 | BE | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:539 |
| FK-075 | MASTER_REQUEST_CV1 | Print et fiscal audit best effort doivent etre alertes | P1 | PLAN-14 | CV1-M14-OPS-PREFLIGHT | (none) | php artisan test --filter=ReceiptAuditFailureAlert | (none) | BE+DevOps | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:557 |
| FK-076 | MASTER_REQUEST_CV1 | Realtime dedupe per-tab ne doit pas porter integrite | P2 | PLAN-14 | CV1-M14-OPS-PREFLIGHT | (none) | npx vitest run tests/js/realtime-dedupe.spec.js | (none) | FE | deferred | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:576 |
| FK-077 | MASTER_REQUEST_CV1 | Public js compile ne doit pas etre source patchee | P2 | PLAN-12 | CV1-M12-LEGACY-GUARDS-CI | BundleScanLegacyTest | npm run scan:bundle:legacy | (none) | DevOps | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:593 |
| FK-078 | MASTER_REQUEST_CV1 | Audio et hardware direct doivent etre classes par surface | P2 | PLAN-16 | CV1-M16-HARDWARE-LAB | (none) | PREUVE_MANQUANTE | (none) | Ops | planned | reports/audit/MASTER_REQUEST_CAISSE_V1_ULTRA_DEEP_SINGLE_FILE_2026-04-25.md:610 |
| FK-079 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-01 | discountReason POS sans v-model bloque les remises | P0 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | PosDiscountReasonBindingSentinelTest | npx vitest run tests/js/sentinels/PosDiscountReasonBindingSentinelTest.spec.js | (none) | FE | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:22 |
| FK-080 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-02 | Gate frozen consolidé reste pending pour zones P0 | P0 | PLAN-03 | CV1-M03-GATES-DRAFT | (none) | PREUVE_MANQUANTE | GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 | Human | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:51 |
| FK-081 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-03 | PaymentComponent mute directement ses props | P0 | PLAN-21 | CV1-M21B-PAYMENT-REFACTOR | PaymentComponentPropMutationSentinelTest | npx vitest run tests/js/sentinels/PaymentComponentPropMutationSentinelTest.spec.js | GATE_PAYMENT_PROP_MUTATION_2026-04-26 | FE | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:79 |
| FK-082 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-04 | kioskFormatPrice hardcode fr-FR et EUR | P1 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | KioskFormatPriceLocaleTest | npx vitest run tests/js/kiosk-format-price-locale.spec.js | (none) | FE | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:110 |
| FK-083 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-05 | bn.json manque les cles KDS | P1 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | npx vitest run tests/js/i18n-kds-keys.spec.js | (none) | FE | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:137 |
| FK-084 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-06 | Focus trap POS importe mais jamais active | P1 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | npx playwright test tests/e2e/pos-focustrap.spec.js | (none) | FE | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:166 |
| FK-085 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-08 | Tests Feature POS trop minces | P1 | PLAN-18 | CV1-M18-TEST-ARCHITECTURE | (none) | php artisan test --testsuite=Feature --filter=Pos | (none) | QA | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:228 |
| FK-086 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-09 | Swiper KDS force dir=ltr et casse RTL | P2 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | npx vitest run tests/js/kds-rtl.spec.js | (none) | FE | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:260 |
| FK-087 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-10 | sync_metrics croît sans TTL ni purge | P2 | PLAN-14 | CV1-M14-OPS-PREFLIGHT | (none) | php artisan test --filter=SyncMetricsPurgeJob | (none) | DevOps | deferred | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:286 |
| FK-088 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-11 | pos_parked_orders n a pas expires_at | P2 | PLAN-13 | CV1-M13-MIGRATIONS-SAFETY | (none) | php artisan test --filter=ParkedOrderExpiration | GATE_SCHEMA_MIGRATIONS_V1 | BE+DBA | deferred | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:315 |
| FK-089 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-12 | PaymentComponent ne refresh pas sur 401 mid-payment | P2 | PLAN-21 | CV1-M21B-PAYMENT-REFACTOR | (none) | npx vitest run tests/js/payment-401-retry.spec.js | GATE_PAYMENT_PROP_MUTATION_2026-04-26 | FE | deferred | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:345 |
| FK-090 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-13 | Tests Feature KDS trop minces pour transitions/stations | P2 | PLAN-18 | CV1-M18-TEST-ARCHITECTURE | (none) | php artisan test --filter=KdsStatusTransition | (none) | QA | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:374 |
| FK-091 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-14 | Gates POS V4 cutover et KPI LCP restent pending | P2 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | PREUVE_MANQUANTE | HG-W2-1 | Human | deferred | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:404 |
| FK-092 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-15 | Bloc pricing allowed POS attend signoff avant 2026-05-10 | P2 | PLAN-21 | CV1-M21A-QUICKWINS-LOT0 | (none) | PREUVE_MANQUANTE | (none) | Human | deferred | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:431 |
| FK-093 | MEGA_PLAN_READINESS_GAP_ANALYSIS | Decision scope paiement V1 manquante | P0 | PLAN-03 | CV1-M03-GATES-DRAFT | (none) | PREUVE_MANQUANTE | GATE_PAYMENT_LEDGER_V1 | Human | planned | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:86 |
| FK-094 | MEGA_PLAN_READINESS_GAP_ANALYSIS | Decision cash kiosk manquante | P0 | PLAN-03 | CV1-M03-GATES-DRAFT | (none) | PREUVE_MANQUANTE | GATE_FISCAL_KIOSK_V1 | Human | planned | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:86 |
| FK-095 | MEGA_PLAN_READINESS_GAP_ANALYSIS | Decision CB/TPE kiosk autonome manquante | P0 | PLAN-03 | CV1-M03-GATES-DRAFT | (none) | PREUVE_MANQUANTE | GATE_OFFLINE_SCOPE_V1 | Human | planned | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:86 |
| FK-096 | MEGA_PLAN_READINESS_GAP_ANALYSIS | Decision fiscal Z kiosk manquante | P0 | PLAN-03 | CV1-M03-GATES-DRAFT | (none) | PREUVE_MANQUANTE | GATE_FISCAL_KIOSK_V1 | Human | planned | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:86 |
| FK-097 | MEGA_PLAN_READINESS_GAP_ANALYSIS | Decision web/table/Stripe actif ou off manquante | P0 | PLAN-03 | CV1-M03-GATES-DRAFT | (none) | PREUVE_MANQUANTE | GATE_WEB_PAYMENT_SCOPE_V1 | Human | planned | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:86 |
| FK-098 | MEGA_PLAN_READINESS_GAP_ANALYSIS | Decision KDS bump authority manquante | P0 | PLAN-03 | CV1-M03-GATES-DRAFT | (none) | PREUVE_MANQUANTE | GATE_KDS_BUMP_V1 | Human | planned | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:86 |
| FK-099 | MEGA_PLAN_READINESS_GAP_ANALYSIS | Decision offline scope POS/kiosk manquante | P0 | PLAN-03 | CV1-M03-GATES-DRAFT | (none) | PREUVE_MANQUANTE | GATE_OFFLINE_SCOPE_V1 | Human | planned | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:86 |
| FK-100 | MEGA_PLAN_READINESS_GAP_ANALYSIS;AUDIT_POS:T-010 | Preuves queue broadcast scheduler runtime manquantes | P0 | PLAN-14 | CV1-M14-OPS-PREFLIGHT | OpsPreflightCaisseV1Test | bash scripts/ops-preflight-caisse-v1.sh | (none) | DevOps | planned | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:120 |
| FK-101 | MEGA_PLAN_READINESS_GAP_ANALYSIS | Claude terminal live n a pas produit de sortie R2/R4 | INFO | PLAN-00 | CV1-M01-TRACEABILITY-MATRIX | (none) | PREUVE_MANQUANTE | (none) | QA | deferred | reports/audit/MEGA_PLAN_READINESS_GAP_ANALYSIS_CAISSE_POS_KIOSK_KDS_2026-04-25.md:144 |
| FK-102 | MASTER_REVIEW_POS_KDS_FINITIONS:FIND-07 | OS/FOS symmetry partiellement verifiee; revue complete requise avant modifications commande | P1 | PLAN-10 | CV1-M10-OS-FOS-SYMMETRY | (none) | php artisan test --filter=OrderServiceFrontendOrderServiceContract | GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 | BE | planned | reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md:197 |

## 3. Findings non mappés (escalation)

| FK-ID | Source | Description | Severity | Evidence |
|-------|--------|-------------|----------|----------|

Aucun P0 non mappe.

## 4. Couverture par Plan-ID

| Plan-ID | Count | FK-IDs |
|---------|-------|--------|
| PLAN-00 | 4 | FK-001, FK-002, FK-003, FK-101 |
| PLAN-01 | 1 | FK-013 |
| PLAN-02 | 1 | FK-009 |
| PLAN-03 | 9 | FK-004, FK-080, FK-093, FK-094, FK-095, FK-096, FK-097, FK-098, FK-099 |
| PLAN-04A | 5 | FK-019, FK-024, FK-028, FK-034, FK-074 |
| PLAN-04B | 0 | (none) |
| PLAN-05 | 5 | FK-015, FK-017, FK-032, FK-036, FK-055 |
| PLAN-06 | 5 | FK-018, FK-023, FK-029, FK-042, FK-073 |
| PLAN-07 | 7 | FK-037, FK-038, FK-039, FK-041, FK-043, FK-068, FK-069 |
| PLAN-08 | 2 | FK-010, FK-062 |
| PLAN-09 | 4 | FK-008, FK-021, FK-033, FK-040 |
| PLAN-10 | 4 | FK-016, FK-022, FK-071, FK-102 |
| PLAN-11 | 11 | FK-030, FK-031, FK-044, FK-051, FK-052, FK-053, FK-054, FK-058, FK-060, FK-063, FK-065 |
| PLAN-12 | 4 | FK-011, FK-067, FK-072, FK-077 |
| PLAN-13 | 3 | FK-020, FK-064, FK-088 |
| PLAN-14 | 6 | FK-006, FK-070, FK-075, FK-076, FK-087, FK-100 |
| PLAN-15 | 1 | FK-005 |
| PLAN-16 | 3 | FK-007, FK-025, FK-078 |
| PLAN-17 | 2 | FK-026, FK-027 |
| PLAN-18 | 5 | FK-047, FK-049, FK-061, FK-085, FK-090 |
| PLAN-19 | 1 | FK-012 |
| PLAN-20 | 1 | FK-059 |
| PLAN-21 | 14 | FK-035, FK-045, FK-046, FK-057, FK-066, FK-079, FK-081, FK-082, FK-083, FK-084, FK-086, FK-089, FK-091, FK-092 |
| PLAN-22 | 4 | FK-014, FK-048, FK-050, FK-056 |

## 5. Couverture par Gate

| Gate | Count | FK-IDs |
|------|-------|--------|
| GATE_FISCAL_KIOSK_V1 | 5 | FK-010, FK-058, FK-062, FK-094, FK-096 |
| GATE_FROZEN_ZONES_CAISSE_V1 | 8 | FK-008, FK-018, FK-021, FK-023, FK-029, FK-033, FK-040, FK-042 |
| GATE_KDS_BUMP_V1 | 7 | FK-037, FK-038, FK-039, FK-043, FK-068, FK-069, FK-098 |
| GATE_OFFLINE_SCOPE_V1 | 5 | FK-030, FK-044, FK-053, FK-095, FK-099 |
| GATE_PAYMENT_LEDGER_V1 | 8 | FK-004, FK-019, FK-024, FK-028, FK-034, FK-073, FK-074, FK-093 |
| GATE_PAYMENT_PROP_MUTATION_2026-04-26 | 2 | FK-081, FK-089 |
| GATE_SCHEMA_MIGRATIONS_V1 | 8 | FK-015, FK-017, FK-020, FK-032, FK-036, FK-055, FK-064, FK-088 |
| GATE_STRIPE_CENTS_ACTIVE | 1 | FK-027 |
| GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 | 3 | FK-016, FK-080, FK-102 |
| GATE_WEB_PAYMENT_SCOPE_V1 | 2 | FK-026, FK-097 |
| HG-W2-1 | 1 | FK-091 |

## 6. Procédure de mise à jour

1. Ajouter une finding dans la table principale avec un FK-ID sequentiel `FK-###`.
2. Renseigner toutes les colonnes du schema dans le meme ordre que le CSV.
3. Pour tout P0, renseigner un `Plan-ID` valide et un `Sentinel`, une commande test, ou `PREUVE_MANQUANTE`.
4. Ajouter la meme ligne dans le CSV avec tous les champs entre guillemets doubles RFC 4180.
5. Lancer `bash scripts/check-traceability.sh`; corriger jusqu a obtenir uniquement des lignes `OK`.
