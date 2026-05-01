import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const force = process.argv.includes("--force");

const commonPlanFiles = [
  "reports/audit/CLAUDE_CAISS_V1_FULLSTACK_ORCHESTRATION_PLAN_2026-04-26.md",
  "reports/audit/CLAUDE_DATA_CENTRAL_SYNC_GLOBAL_MASTER_2026-04-26.md",
  "reports/audit/CLAUDE_POS_ORDER_FLOW_MASTER_PLAN_2026-04-26.md",
  "reports/audit/CLAUDE_KIOSK_ORDER_FLOW_MASTER_PLAN_2026-04-26.md",
  "reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md",
  "plans/masterplay/MASTERPLAY_QUEUE.md",
  "AGENTS.md",
];

const optionBPolicy = [
  "Payment Ledger Option B restricted pilot is active.",
  "Do not execute CV1-M04A-PAYMENT-LEDGER-FULL.",
  "Do not implement full ledger, split tender, partial refund ledger, or payment-ledger migrations unless a new human gate explicitly changes the scope.",
  "One TASK_ID equals one run; never mix allowlists.",
];

const commonOffLimits = [
  ".cursor/**",
  "AGENTS.md",
  "plans/masterplay/MASTERPLAY_QUEUE.md",
  "missions/W2_LOT_CODEX_RUN_ORDER_OPTION_B_2026-04-26.md",
  "docs/gates/**",
  "reports/audit/CLAUDE_*",
];

const commonChecklist = [
  "Allowlist strictement respectée; aucun fichier hors scope.",
  "Aucune approbation de gate inventée.",
  "Pricing backend SSOT conservé; aucun total final imposé depuis le client.",
  "OrderStatus enum ou OrderStateMachine utilisé pour tout statut.",
  "branch_id strict et branch-scoped; aucune fuite cross-branch.",
  "Dispatch / events après commit DB.",
  "Si OrderService.php ou FrontendOrderService.php est modifié: SYMMETRY_NOTE rempli.",
  "mandatory_tests exécutés ou impossibilité documentée.",
];

const specs = [
  {
    n: 1,
    task: "CV1-LOT-D01-CLIENT-TOTAL-INVARIANT",
    lot: "D-01",
    family: "DATA",
    status: "READY",
    objective: "Garantir par garde statique et sentinel qu'aucune écriture de totaux finaux depuis JSON client ne contourne le backend SSOT.",
    allowlist: [
      "scripts/lint-fk-client-totals.sh",
      "tests/Feature/Sentinels/ClientTotalWriteForbiddenSentinelTest.php",
      "tests/Feature/Sentinels/PosSubtotalForgerySentinelTest.php",
      "tests/Feature/FrontendDiscountIntegrityTest.php",
      "reports/audit/GPT_SELF_AUDIT_CV1-LOT-D01-CLIENT-TOTAL-INVARIANT.md",
    ],
    tests: [
      "bash scripts/lint-fk-client-totals.sh",
      "php artisan test --filter='ClientTotalWriteForbiddenSentinelTest|PosSubtotalForgerySentinelTest|FrontendDiscountIntegrityTest'",
    ],
    invariants: ["pricing-ssot", "branch_id"],
  },
  {
    n: 2,
    task: "CV1-LOT-P01-QUOTE-BIND",
    lot: "P-01",
    family: "POS",
    status: "READY_WITH_FROZEN_GATE_CHECK",
    objective: "Forcer la consommation quote_token dans posOrderStore avec binding branch_id + actor_id + items_hash, rejet expiré/replayé.",
    allowlist: [
      "app/Services/OrderService.php",
      "app/Services/Order/OrderQuoteService.php",
      "app/Http/Requests/PosOrderRequest.php",
      "tests/Feature/Pos/QuoteBindingTest.php",
      "tests/Feature/QuoteReplayIdempotencyTest.php",
      "tests/Feature/QuoteTamperTest.php",
      "tests/Feature/QuoteExpirationTest.php",
    ],
    tests: [
      "php artisan test --filter='QuoteBindingTest|QuoteReplayIdempotencyTest|QuoteTamperTest|QuoteExpirationTest|PosDiscountForgeryTest'",
    ],
    gates: ["GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching OrderService.php"],
    invariants: ["pricing-ssot", "branch_id", "order_service_symmetry"],
    symmetry: true,
  },
  {
    n: 3,
    task: "CV1-LOT-K01-ROUTING-LEGACY",
    lot: "K-01",
    family: "KIOSK",
    status: "READY",
    objective: "Verrouiller redirect kiosk.products/:categoryId vers kiosk.categories?cat= et telemetry legacy_route_hit.",
    allowlist: [
      "resources/js/router/modules/kioskRoutes.js",
      "resources/js/helpers/kioskAnalytics.js",
      "tests/js/kioskLegacyRedirect.spec.js",
      "tests/Playwright/kiosk-legacy-redirect.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/kioskLegacyRedirect.spec.js tests/js/kioskAnalytics.spec.js",
      "npx playwright test tests/Playwright/kiosk-legacy-redirect.spec.js",
    ],
    invariants: ["branch_id"],
  },
  {
    n: 4,
    task: "CV1-LOT-D02-ORDER-EVENT-OUTBOX-MAP",
    lot: "D-02",
    family: "DATA",
    status: "READY",
    objective: "Cartographier OrderCreated / OrderStatusChanged vers canaux et ajouter un test de non-régression outbox after-commit.",
    allowlist: [
      "docs/EVENT_CONTRACT.md",
      "docs/OUTBOX_PATTERN.md",
      "docs/orchestration/ORDER_EVENT_OUTBOX_CHANNEL_MAP_2026-04-26.md",
      "app/Providers/EventServiceProvider.php",
      "app/Listeners/PersistOrderCreatedToOutbox.php",
      "app/Listeners/PersistOrderStatusChangedToOutbox.php",
      "tests/Feature/AfterCommitDispatchTest.php",
    ],
    tests: [
      "php artisan test --filter=AfterCommitDispatchTest",
    ],
    invariants: ["commit_before_dispatch", "branch_id"],
  },
  {
    n: 5,
    task: "CV1-LOT-P02-DISCOUNT-GUARD",
    lot: "P-02",
    family: "POS",
    status: "READY_WITH_FROZEN_GATE_CHECK",
    objective: "Server-authoritative discount caps, reason required server-side, and auditable discount log.",
    allowlist: [
      "app/Services/OrderService.php",
      "app/Models/OrderDiscountLog.php",
      "tests/Feature/PosManualDiscountAuditTest.php",
      "tests/Feature/Sentinels/PosSubtotalForgerySentinelTest.php",
      "tests/Feature/PosDiscountPermissionTest.php",
      "tests/Feature/PosDiscountForgeryTest.php",
    ],
    tests: [
      "php artisan test --filter='PosManualDiscountAuditTest|PosSubtotalForgerySentinelTest|PosDiscountPermissionTest|PosDiscountForgeryTest'",
    ],
    gates: ["GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching OrderService.php"],
    invariants: ["pricing-ssot", "branch_id"],
    symmetry: true,
  },
  {
    n: 6,
    task: "CV1-LOT-K02-ORDER-TYPE-EXPLICIT",
    lot: "K-02",
    family: "KIOSK",
    status: "READY",
    objective: "Exiger un choix order_type explicite avant catégories / wizard et bloquer submitOrder si absent.",
    allowlist: [
      "resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue",
      "resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue",
      "resources/js/store/modules/kioskCart.js",
      "tests/js/kioskOrderTypeExplicit.spec.js",
      "tests/Playwright/kiosk-order-type-required.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/kioskOrderTypeExplicit.spec.js",
      "npx playwright test tests/Playwright/kiosk-order-type-required.spec.js",
    ],
    depends: ["K-01"],
    invariants: ["pricing-ssot"],
  },
  {
    n: 7,
    task: "CV1-LOT-D03-BRANCH-FILTER-MATRIX",
    lot: "D-03",
    family: "DATA",
    status: "READY_WITH_FROZEN_GATE_CHECK",
    objective: "Matrice branch_id sur listes POS, admin order, KDS; ajouter un test par filtre critique.",
    allowlist: [
      "app/Services/OrderService.php",
      "app/Services/KitchenDisplaySystemOrderService.php",
      "app/Http/Controllers/Admin/PosOrderController.php",
      "docs/orchestration/BRANCH_FILTER_MATRIX_CAISSE_V1_2026-04-26.md",
      "tests/Feature/Sentinels/OrderListBranchExactnessSentinelTest.php",
      "tests/Feature/Sentinels/OrderShowBranchGuardSentinelTest.php",
      "tests/Feature/KdsBranchFilterExactTest.php",
    ],
    tests: [
      "php artisan test --filter='OrderListBranchExactnessSentinelTest|OrderShowBranchGuardSentinelTest|KdsBranchFilterExactTest|OrderBranchIsolationTest'",
      "bash scripts/lint-fk-branch-isolation.sh",
    ],
    gates: ["GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching services"],
    invariants: ["branch_id", "order_service_symmetry"],
    symmetry: true,
  },
  {
    n: 8,
    task: "CV1-LOT-P03-DISCOUNT-REASON-BIND",
    lot: "P-03",
    family: "POS",
    status: "READY",
    objective: "Fix binding UI S09 discountReason et sentinel FK-079.",
    allowlist: [
      "resources/js/components/admin/pos/PosComponent.vue",
      "tests/js/sentinels/PosDiscountReasonBindingSentinelTest.spec.js",
      "tests/js/quickwins/discountReasonBindingTest.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/sentinels/PosDiscountReasonBindingSentinelTest.spec.js tests/js/quickwins/discountReasonBindingTest.spec.js",
    ],
    invariants: ["pricing-ssot"],
  },
  {
    n: 9,
    task: "CV1-LOT-K03-QUOTE-PRICING-PIN",
    lot: "K-03",
    family: "KIOSK",
    status: "READY_WITH_FROZEN_READONLY_CHECK",
    objective: "Pin pricing kiosk par quote opposable; transmettre quote_token/signature à submitOrder sans calcul final client.",
    allowlist: [
      "resources/js/store/modules/kioskCart.js",
      "resources/js/components/frontend/kiosk/KioskCartComponent.vue",
      "tests/Feature/KioskQuoteIntegrityTest.php",
      "tests/Playwright/kiosk-quote-pin.spec.js",
    ],
    read_only: ["app/Services/Order/OrderQuoteService.php"],
    tests: [
      "php artisan test --filter='KioskQuoteIntegrityTest|QuoteTamperTest|QuoteReplayIdempotencyTest|QuoteCurrencyOriginTest'",
      "npx playwright test tests/Playwright/kiosk-quote-pin.spec.js",
    ],
    depends: ["K-02"],
    invariants: ["pricing-ssot", "branch_id"],
  },
  {
    n: 10,
    task: "CV1-LOT-D04-DELIVERY-API-CONTRACT",
    lot: "D-04",
    family: "DATA",
    status: "READY_NO_MIGRATION",
    objective: "Ajouter un flux API delivery création -> statut aligné docs sans migration DB.",
    allowlist: [
      "app/Http/Controllers/Admin/DeliveryBoyOrderController.php",
      "app/Http/Controllers/Frontend/DeliveryBoyOrderController.php",
      "app/Services/OrderService.php",
      "tests/Feature/DeliveryOrderContractTest.php",
      "docs/ORDER_FLOW.md",
    ],
    tests: [
      "php artisan test --filter='DeliveryOrderContractTest|DeliveryBoyOrderStatusOrderingTest'",
    ],
    gates: ["STOP if DB schema change is needed; require M-13/schema human gate before any migration"],
    invariants: ["order_status", "branch_id", "commit_before_dispatch"],
    symmetry: true,
  },
  {
    n: 11,
    task: "CV1-LOT-P04-PAYMENT-REFACTOR-PROPS",
    lot: "P-04",
    family: "POS",
    status: "READY_GATE_APPROVED",
    objective: "Refactor PaymentComponent pour éliminer mutations directes de props et conserver one-shot 401 retry.",
    allowlist: [
      "resources/js/components/admin/pos/PaymentComponent.vue",
      "resources/js/components/admin/pos/PosComponent.vue",
      "tests/js/sentinels/PaymentComponentPropMutationSentinelTest.spec.js",
      "tests/js/sentinels/paymentComponentPropMutation.spec.js",
      "tests/js/paymentComponentPropMutation.spec.js",
      "tests/js/paymentComponent401Retry.spec.js",
      "tests/js/posPaymentComponentContract.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/paymentComponentPropMutation.spec.js tests/js/paymentComponent401Retry.spec.js tests/js/posPaymentComponentContract.spec.js tests/js/sentinels/paymentComponentPropMutation.spec.js",
    ],
    gates: ["GATE_PAYMENT_PROP_MUTATION_2026-04-26: Approved Option A"],
    invariants: ["pricing-ssot"],
  },
  {
    n: 12,
    task: "CV1-LOT-K04-PAYMENT-UX-OFFLINE",
    lot: "K-04",
    family: "KIOSK",
    status: "READY_GATE_APPROVED",
    objective: "Clarifier UX cash/card/TR et désactiver CB/TR offline selon GATE_OFFLINE_SCOPE Option A.",
    allowlist: [
      "resources/js/components/frontend/kiosk/KioskPaymentComponent.vue",
      "resources/js/store/modules/kioskCart.js",
      "resources/js/helpers/kioskOfflineQueue.js",
      "tests/js/kioskCartOfflinePaymentScope.spec.js",
      "tests/Feature/KioskOfflinePaymentScopeTest.php",
      "tests/Playwright/sentinels/kioskCbTrOfflineRefused.spec.js",
    ],
    tests: [
      "php artisan test --filter=KioskOfflinePaymentScopeTest",
      "npx vitest run tests/js/kioskCartOfflinePaymentScope.spec.js",
      "npx playwright test tests/Playwright/sentinels/kioskCbTrOfflineRefused.spec.js",
    ],
    gates: ["GATE_OFFLINE_SCOPE_V1_2026-04-25: Approved Option A read-only menu, payment disabled"],
    depends: ["K-03"],
    invariants: ["pricing-ssot", "branch_id"],
  },
  {
    n: 13,
    task: "CV1-LOT-D05-CANCEL-AUDIT-TRAIL",
    lot: "D-05",
    family: "DATA",
    status: "READY_WITH_LEDGER_SCOPE_CHECK",
    objective: "Annulation CANCELED: audit trail + check paiement compatible Option B restricted pilot.",
    allowlist: [
      "app/Services/OrderService.php",
      "app/Services/PaymentService.php",
      "app/Models/ActionLog.php",
      "tests/Feature/CancelAuditTrailTest.php",
      "tests/Feature/Fiscal/VoidPreZTest.php",
      "tests/Feature/OrderStatusNoopSideEffectsTest.php",
    ],
    tests: [
      "php artisan test --filter='CancelAuditTrailTest|VoidPreZTest|OrderStatusNoopSideEffectsTest|PaymentNoopIdempotencyTest'",
    ],
    gates: ["Do not expand to full payment ledger; Option B restricted pilot only"],
    invariants: ["order_status", "branch_id", "pricing-ssot"],
    symmetry: true,
  },
  {
    n: 14,
    task: "CV1-LOT-P05-FLOORPLAN-RELEASE",
    lot: "P-05",
    family: "POS",
    status: "READY_WITH_FROZEN_GATE_CHECK",
    objective: "Release table after successful posOrderStore and pessimistic assign lock.",
    allowlist: [
      "app/Services/OrderService.php",
      "app/Http/Controllers/Admin/Pos/FloorplanController.php",
      "app/Services/DiningTableService.php",
      "tests/Feature/Pos/DiningTableReleaseAfterPosOrderTest.php",
      "tests/Feature/Pos/FloorplanControllerTest.php",
    ],
    tests: [
      "php artisan test --filter='DiningTableReleaseAfterPosOrderTest|FloorplanControllerTest'",
    ],
    depends: ["P-01"],
    gates: ["GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching OrderService.php"],
    invariants: ["branch_id", "commit_before_dispatch"],
    symmetry: true,
  },
  {
    n: 15,
    task: "CV1-LOT-K05-PAYMENT-CONFIRM-WS",
    lot: "K-05",
    family: "KIOSK",
    status: "BLOCKED_FROZEN_F21_GATE_UNTIL_VERIFIED",
    objective: "Rendre payment-confirm robuste, event WS après finalizePaidKioskOrder, collisions payload divergent loggées.",
    allowlist: [
      "app/Http/Controllers/Frontend/OrderController.php",
      "app/Services/FrontendOrderService.php",
      "app/Events/KioskPaymentConfirmed.php",
      "tests/Feature/PaymentConfirmIdempotencyTest.php",
      "tests/Feature/PaymentConfirmMachineResolverTest.php",
      "tests/Feature/PaymentConfirmCrossBranchTest.php",
    ],
    tests: [
      "php artisan test --filter='PaymentConfirmIdempotencyTest|PaymentConfirmMachineResolverTest|PaymentConfirmCrossBranchTest|CleanupVsConfirmRaceTest'",
    ],
    gates: ["GATE_FROZEN_F21_FINALIZE_PAID_KIOSK_2026-04-23 must be read and confirmed before touching finalizePaidKioskOrder"],
    depends: ["K-04"],
    invariants: ["branch_id", "commit_before_dispatch", "order_service_symmetry"],
    symmetry: true,
  },
  {
    n: 16,
    task: "CV1-LOT-D06-BROADCAST-FALLBACK-DOC",
    lot: "D-06",
    family: "DATA",
    status: "READY",
    objective: "Documenter fallback polling et ajouter config/UI hint quand broadcast est off.",
    allowlist: [
      "docs/REALTIME_SETUP.md",
      "docs/HANDOFF_NEW_CURSOR/03_SYNCHRONISATION_TEMPS_REEL.md",
      "config/broadcasting.php",
      "resources/js/store/modules/posOrder.js",
      "tests/js/realtimeBroadcastFallback.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/realtimeBroadcastFallback.spec.js",
    ],
    invariants: ["branch_id"],
  },
  {
    n: 17,
    task: "CV1-LOT-P06-PARK-TTL",
    lot: "P-06",
    family: "POS",
    status: "BLOCKED_SCHEMA_GATE_IF_MIGRATION",
    objective: "Ajouter expires_at sur pos_parked_orders, purge job, et branch scope reprise.",
    allowlist: [
      "database/migrations/*_add_expires_at_to_pos_parked_orders.php",
      "app/Models/PosParkedOrder.php",
      "app/Services/PosParkedOrderService.php",
      "app/Console/Commands/PosPurgeParkedOrders.php",
      "app/Http/Controllers/Admin/Pos/ParkedOrderController.php",
      "tests/Feature/Pos/ParkedOrderExpirationTest.php",
      "tests/Feature/Pos/ParkedOrderBranchScopeTest.php",
      "tests/Feature/Pos/PosPurgeParkedScheduleTest.php",
    ],
    tests: [
      "php artisan test --filter='ParkedOrderExpirationTest|ParkedOrderBranchScopeTest|PosPurgeParkedScheduleTest|PosParkedOrderTest'",
    ],
    gates: ["Any DB migration requires schema/M-13 human gate and rehearsal evidence before execute"],
    invariants: ["branch_id"],
  },
  {
    n: 18,
    task: "CV1-LOT-K06-OFFLINE-WAITING-UX",
    lot: "K-06",
    family: "KIOSK",
    status: "READY_GATE_APPROVED",
    objective: "Guard waiting offline_ IDs and show sync-pending UX without polling backend.",
    allowlist: [
      "resources/js/components/frontend/kiosk/KioskWaitingComponent.vue",
      "resources/js/router/modules/kioskRoutes.js",
      "resources/js/helpers/kioskOfflineQueue.js",
      "tests/js/sentinels/kioskOfflineIdPrefix.spec.js",
      "tests/Playwright/kiosk-offline-waiting.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/sentinels/kioskOfflineIdPrefix.spec.js",
      "npx playwright test tests/Playwright/kiosk-offline-waiting.spec.js",
    ],
    gates: ["GATE_OFFLINE_SCOPE_V1_2026-04-25: Approved Option A"],
    depends: ["K-04"],
    invariants: ["branch_id"],
  },
  {
    n: 19,
    task: "CV1-LOT-D07-FOS-SYMMETRY-CONTRACT",
    lot: "D-07",
    family: "DATA",
    status: "READY",
    objective: "Documenter et tester la cartographie symétrie OS/FOS, notamment absence FOS changePaymentStatus.",
    allowlist: [
      "docs/orchestration/OS_FOS_SYMMETRY_MATRIX_2026-04-25.md",
      "tests/Feature/Symmetry/OrderServicesContractTest.php",
      "reports/audit/GPT_SELF_AUDIT_CV1-LOT-D07-FOS-SYMMETRY-CONTRACT.md",
    ],
    tests: [
      "php artisan test --filter=OrderServicesContractTest",
    ],
    invariants: ["order_service_symmetry", "order_status"],
  },
  {
    n: 20,
    task: "CV1-LOT-P07-KIOSK-CASH-DECOUPLE",
    lot: "P-07",
    family: "POS",
    status: "READY_WITH_FROZEN_GATE_CHECK",
    objective: "Découpler encaissement cash kiosk de la transition cuisine; éviter double release.",
    allowlist: [
      "app/Services/OrderService.php",
      "app/Services/PaymentService.php",
      "routes/api.php",
      "tests/Feature/PosCashEndpointSentinelTest.php",
      "tests/Feature/PosCollectKioskCashRouteTest.php",
    ],
    tests: [
      "php artisan test --filter='PosCashEndpointSentinelTest|PosCollectKioskCashRouteTest|PaymentNoopIdempotencyTest'",
    ],
    depends: ["P-01"],
    gates: ["GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching services/routes"],
    invariants: ["pricing-ssot", "order_status", "commit_before_dispatch"],
    symmetry: true,
  },
  {
    n: 21,
    task: "CV1-LOT-K07-WIZARD-UNIFY",
    lot: "K-07",
    family: "KIOSK",
    status: "READY",
    objective: "Converger KioskWizardComponent et KioskPosWizardComponent pour éviter double implémentation pricing/wizard.",
    allowlist: [
      "resources/js/components/frontend/kiosk/KioskWizardComponent.vue",
      "resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue",
      "resources/js/components/frontend/kiosk/steps/**",
      "resources/js/helpers/kioskPricing.js",
      "resources/js/helpers/kioskPricingPreview.js",
      "tests/js/KioskWizard.spec.js",
      "tests/js/kioskWizardNavigation.spec.js",
      "tests/js/kioskSandwichSplit.spec.js",
      "tests/js/kioskTacosSize.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/KioskWizard.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/kioskSandwichSplit.spec.js tests/js/kioskTacosSize.spec.js",
    ],
    depends: ["K-03"],
    invariants: ["pricing-ssot"],
  },
  {
    n: 22,
    task: "CV1-LOT-P08-KDS-RELEASE-RULE",
    lot: "P-08",
    family: "POS",
    status: "READY_GATE_APPROVED",
    objective: "Règle release explicite KDS, expected_status et dedupe outbox.",
    allowlist: [
      "app/Domain/Kds/KitchenReleaseRule.php",
      "app/Listeners/DispatchKdsTicket.php",
      "app/Services/KitchenDisplaySystemOrderService.php",
      "tests/Feature/KdsTransitionWhitelistTest.php",
      "tests/Feature/KdsExpectedStatusConflictTest.php",
      "tests/Feature/KitchenReleaseRuleTest.php",
      "tests/Feature/KdsPaginationOverflowTest.php",
    ],
    tests: [
      "php artisan test --filter='KdsTransitionWhitelistTest|KdsExpectedStatusConflictTest|KitchenReleaseRuleTest|KdsPaginationOverflowTest'",
    ],
    depends: ["P-01"],
    gates: ["GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25: Approved Option B"],
    invariants: ["order_status", "branch_id", "commit_before_dispatch"],
  },
  {
    n: 23,
    task: "CV1-LOT-K08-GLOBAL-ERRORS",
    lot: "K-08",
    family: "KIOSK",
    status: "READY",
    objective: "Centraliser erreurs kiosk via goToKioskError(code,payload) et harmoniser CTAs.",
    allowlist: [
      "resources/js/components/frontend/kiosk/KioskErrorLayoutComponent.vue",
      "resources/js/components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue",
      "resources/js/components/frontend/kiosk/KioskErrorNetworkComponent.vue",
      "resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue",
      "resources/js/components/frontend/kiosk/KioskErrorProductRemovedComponent.vue",
      "resources/js/helpers/kioskAnalytics.js",
      "resources/js/store/modules/kioskCart.js",
      "tests/js/kioskGlobalErrors.spec.js",
      "tests/Playwright/kiosk-errors.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/kioskGlobalErrors.spec.js",
      "npx playwright test tests/Playwright/kiosk-errors.spec.js",
    ],
    depends: ["K-04"],
    invariants: ["branch_id"],
  },
  {
    n: 24,
    task: "CV1-LOT-P09-AFTER-COMMIT-DISPATCH",
    lot: "P-09",
    family: "POS",
    status: "READY_WITH_FROZEN_GATE_CHECK",
    objective: "Garantir tous events POS après commit et idempotence outbox.",
    allowlist: [
      "app/Services/OrderService.php",
      "app/Jobs/DispatchDomainEventsJob.php",
      "tests/Feature/AfterCommitDispatchTest.php",
      "tests/Feature/DispatchAfterCommitTest.php",
      "tests/Feature/OutboxRescueTest.php",
    ],
    tests: [
      "php artisan test --filter='AfterCommitDispatchTest|DispatchAfterCommitTest|OutboxRescueTest'",
    ],
    depends: ["P-01", "P-08"],
    gates: ["GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching OrderService.php"],
    invariants: ["commit_before_dispatch", "branch_id"],
    symmetry: true,
  },
  {
    n: 25,
    task: "CV1-LOT-K09-POS-REALTIME-KIOSK-VIS",
    lot: "K-09",
    family: "KIOSK",
    status: "READY_WITH_FROZEN_GATE_CHECK",
    objective: "Garantir visibilité POS temps réel des transitions kiosk avec origin, payment_method et queue_number.",
    allowlist: [
      "app/Events/OrderCreated.php",
      "app/Events/OrderStatusChanged.php",
      "app/Http/Resources/OrderResource.php",
      "resources/js/store/modules/posOrder.js",
      "tests/Feature/KioskRealtimeBroadcastTest.php",
      "tests/Playwright/pos-receives-kiosk-realtime.spec.js",
    ],
    tests: [
      "php artisan test --filter='KioskRealtimeBroadcastTest|AfterCommitDispatchTest'",
      "npx playwright test tests/Playwright/pos-receives-kiosk-realtime.spec.js",
    ],
    depends: ["K-05"],
    gates: ["Frozen event/service paths require GATE_LOG check before edit"],
    invariants: ["branch_id", "commit_before_dispatch"],
  },
  {
    n: 26,
    task: "CV1-LOT-P10-REFUND-LEDGER",
    lot: "P-10",
    family: "POS",
    status: "BLOCKED_OPTION_B_RESCOPING_REQUIRED",
    objective: "Refund partiel ledger + wallet idempotency est hors full-ledger sous Option B sauf rescope humain explicite.",
    allowlist: [
      "app/Services/PaymentService.php",
      "app/Services/OrderService.php",
      "tests/Feature/PartialRefundLedgerTest.php",
      "tests/Feature/CreditWalletIdempotencyTest.php",
      "tests/Feature/PaymentProviderReferenceUniqueTest.php",
    ],
    blocked_until_gate_allowlist: [
      "database/migrations/*payment_ledger*.php",
    ],
    tests: [
      "php artisan test --filter='PartialRefundLedgerTest|CreditWalletIdempotencyTest|PaymentProviderReferenceUniqueTest|RefundPreZTest|RefundPostZTest'",
    ],
    depends: ["P-01"],
    gates: ["BLOCKED under Option B unless human approves restricted refund-ledger pilot; never launch M-04A from this lot"],
    invariants: ["pricing-ssot", "branch_id", "order_status"],
    symmetry: true,
  },
  {
    n: 27,
    task: "CV1-LOT-K10-CLEANUP-IDEMPOTENCY",
    lot: "K-10",
    family: "KIOSK",
    status: "READY",
    objective: "CleanupStalePendingKioskOrders ignore ordres dont idempotency_key est active; délai >= 2x timeout TPE max.",
    allowlist: [
      "app/Jobs/CleanupStalePendingKioskOrders.php",
      "config/kiosk.php",
      "tests/Feature/CleanupStalePendingKioskOrdersTest.php",
      "tests/Feature/CleanupVsConfirmRaceTest.php",
    ],
    read_only: ["app/Models/FrontendOrder.php"],
    tests: [
      "php artisan test --filter='CleanupStalePendingKioskOrdersTest|CleanupVsConfirmRaceTest'",
    ],
    depends: ["K-05"],
    invariants: ["branch_id", "commit_before_dispatch"],
  },
  {
    n: 28,
    task: "CV1-LOT-P11-PRINT-AUDIT",
    lot: "P-11",
    family: "POS",
    status: "READY",
    objective: "Audit alert sur échec impression et duplicata explicite.",
    allowlist: [
      "app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php",
      "app/Services/Receipt/ReceiptAuditService.php",
      "app/Services/Receipt/ReceiptDataService.php",
      "tests/Feature/ReceiptAuditFailureAlertTest.php",
      "tests/Feature/ReceiptDuplicataIncrementTest.php",
      "tests/Feature/Admin/POS/ReceiptPrintControllerTest.php",
    ],
    tests: [
      "php artisan test --filter='ReceiptAuditFailureAlertTest|ReceiptDuplicataIncrementTest|ReceiptPrintControllerTest'",
    ],
    invariants: ["branch_id"],
  },
  {
    n: 29,
    task: "CV1-LOT-K11-PRINT-FALLBACK-IDLE",
    lot: "K-11",
    family: "KIOSK",
    status: "READY",
    objective: "Retry imprimante 1x si off et ne jamais bloquer retour idle.",
    allowlist: [
      "resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue",
      "resources/js/helpers/kioskPrinter.js",
      "resources/js/helpers/kioskReceiptPersistence.js",
      "tests/js/kioskPrinter.spec.js",
      "tests/js/kioskConfirmationFallback.spec.js",
      "tests/Playwright/kiosk-printer-off.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/kioskPrinter.spec.js tests/js/kioskConfirmationFallback.spec.js",
      "npx playwright test tests/Playwright/kiosk-printer-off.spec.js",
    ],
    depends: ["K-04"],
    invariants: ["branch_id"],
  },
  {
    n: 30,
    task: "CV1-LOT-P12-RT-RESYNC",
    lot: "P-12",
    family: "POS",
    status: "READY",
    objective: "Resync POS au focus tab et dedupe per-tab fiable.",
    allowlist: [
      "resources/js/services/realtime/PosFeed.js",
      "resources/js/store/modules/posOrder.js",
      "tests/js/realtime-dedupe.spec.js",
      "tests/Playwright/pos-realtime-multitab.spec.js",
    ],
    tests: [
      "npx vitest run tests/js/realtime-dedupe.spec.js",
      "npx playwright test tests/Playwright/pos-realtime-multitab.spec.js",
    ],
    invariants: ["branch_id"],
  },
  {
    n: 31,
    task: "CV1-LOT-K12-LOYALTY-REFUSAL",
    lot: "K-12",
    family: "KIOSK",
    status: "READY_WITH_FROZEN_READONLY_CHECK",
    objective: "Exposer loyalty_refusal_reason dans OrderDetailsResource et UI toast clair.",
    allowlist: [
      "app/Http/Resources/OrderDetailsResource.php",
      "resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue",
      "resources/js/components/frontend/kiosk/KioskCartComponent.vue",
      "tests/Feature/LoyaltyRefusalReasonTest.php",
      "tests/Playwright/loyalty-refused.spec.js",
    ],
    read_only: ["app/Services/FrontendOrderService.php"],
    tests: [
      "php artisan test --filter='LoyaltyRefusalReasonTest|LoyaltyApiTest'",
      "npx playwright test tests/Playwright/loyalty-refused.spec.js",
    ],
    depends: ["K-03"],
    invariants: ["branch_id"],
  },
  {
    n: 32,
    task: "CV1-LOT-P13-ZREPORT-HARDEN",
    lot: "P-13",
    family: "POS",
    status: "BLOCKED_SCHEMA_AND_FISCAL_GATE_IF_MIGRATION",
    objective: "Z idempotent par branch_id/business_date et alert sur gap ticket.",
    allowlist: [
      "app/Services/Fiscal/ZReportService.php",
      "database/migrations/*add_unique_z_per_branch_day*.php",
      "tests/Feature/Fiscal/ZIdempotencyPerDayTest.php",
      "tests/Feature/Fiscal/FiscalSealingHmacTest.php",
      "tests/Feature/Fiscal/ZAggregationKioskRoutingTest.php",
    ],
    tests: [
      "php artisan test --filter='ZIdempotencyPerDayTest|FiscalSealingHmacTest|ZAggregationKioskRoutingTest|ZReportCloseTest'",
    ],
    depends: ["P-09"],
    gates: ["GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25 and schema/M-13 human gate required before fiscal service/migration edits"],
    invariants: ["branch_id", "commit_before_dispatch"],
  },
  {
    n: 33,
    task: "CV1-LOT-K13-SENTINEL-IDEMPOTENCY",
    lot: "K-13",
    family: "KIOSK",
    status: "READY_WITH_FROZEN_GATE_CHECK",
    objective: "ActionLog idempotency_collision_divergent_payload pour même clé avec hash items différent.",
    allowlist: [
      "app/Services/FrontendOrderService.php",
      "app/Models/ActionLog.php",
      "tests/Feature/IdempotencyCollisionDivergentPayloadTest.php",
      "tests/Feature/Orders/IdempotencyBranchScopedTest.php",
    ],
    tests: [
      "php artisan test --filter='IdempotencyCollisionDivergentPayloadTest|IdempotencyBranchScopedTest'",
    ],
    depends: ["K-05"],
    gates: ["GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching FrontendOrderService.php"],
    invariants: ["branch_id", "pricing-ssot", "order_service_symmetry"],
    symmetry: true,
  },
  {
    n: 34,
    task: "CV1-LOT-P14-BRANCH-BADGE",
    lot: "P-14",
    family: "POS",
    status: "READY",
    objective: "Badge branche permanent et warning Admin sans choix explicite.",
    allowlist: [
      "resources/js/components/admin/pos/PosComponent.vue",
      "tests/Playwright/pos-branch-badge.spec.js",
      "tests/Feature/Sentinels/OrderListBranchExactnessSentinelTest.php",
    ],
    tests: [
      "php artisan test --filter=OrderListBranchExactnessSentinelTest",
      "npx playwright test tests/Playwright/pos-branch-badge.spec.js",
    ],
    invariants: ["branch_id"],
  },
  {
    n: 35,
    task: "CV1-LOT-K14-TELEMETRY-HOMOG",
    lot: "K-14",
    family: "KIOSK",
    status: "READY",
    objective: "Homogénéiser kiosk_event UX avec schéma stable.",
    allowlist: [
      "resources/js/helpers/kioskAnalytics.js",
      "app/Http/Controllers/Frontend/KioskEventController.php",
      "tests/js/kioskAnalytics.spec.js",
      "tests/Feature/KioskEventStoreSchemaTest.php",
      "tests/Feature/KioskEventTest.php",
    ],
    tests: [
      "npx vitest run tests/js/kioskAnalytics.spec.js",
      "php artisan test --filter='KioskEventStoreSchemaTest|KioskEventTest|KioskEventAbilityTest'",
    ],
    depends: ["K-08"],
    invariants: ["branch_id"],
  },
  {
    n: 36,
    task: "CV1-LOT-P15-E2E-MATRIX",
    lot: "P-15",
    family: "POS",
    status: "READY_AFTER_ALL_PRIOR_LOTS",
    objective: "Matrice E2E complète POS page-par-page jusqu'au KDS.",
    allowlist: [
      "tests/e2e/pos-full-journey/**",
      "tests/Playwright/pos-full-journey/**",
      "reports/baseline/POS_V4_E2E_BASELINE_2026-04-26.md",
    ],
    tests: [
      "npx playwright test tests/Playwright/pos-full-journey --reporter=line --workers=1",
    ],
    depends: ["D-01", "D-02", "D-03", "D-04", "D-05", "D-06", "D-07", "P-01", "P-02", "P-03", "P-04", "P-05", "P-06", "P-07", "P-08", "P-09", "P-10", "P-11", "P-12", "P-13", "P-14", "K-01", "K-02", "K-03", "K-04", "K-05", "K-06", "K-07", "K-08", "K-09", "K-10", "K-11", "K-12", "K-13", "K-14"],
    invariants: ["pricing-ssot", "order_status", "branch_id", "commit_before_dispatch"],
  },
];

function writeFileOnce(file, body) {
  if (!force && fs.existsSync(file)) {
    return false;
  }
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, body.endsWith("\n") ? body : `${body}\n`, "utf8");
  return true;
}

function inputFor(spec) {
  const migrationTouched = spec.allowlist.some((p) => p.startsWith("database/migrations/"));
  return {
    task_id: spec.task,
    wave: "CAISSE_V1_WAVE_2_OPTION_B",
    run_order: spec.n,
    lot: spec.lot,
    family: spec.family,
    status: spec.status,
    primary_model: "codex-extension",
    model: "gpt-5.5-pro",
    reasoning_effort: "xhigh",
    option_b_policy: optionBPolicy,
    plan_files: commonPlanFiles,
    objective: spec.objective,
    instruction: [
      "Execute exactly this one Wave 2 lot.",
      "Modify only allowlist files.",
      "If a required file is missing or the lot needs a file outside allowlist, stop with SCOPE_PRESSURE in output_codex.json.",
      "If a gate condition is unmet, mark BLOCKED and do not edit frozen/schema/payment-ledger scope.",
      "Produce output_codex.json and GPT_SELF_AUDIT report through the normal codex-extension wrapper.",
    ].join(" "),
    allowlist: spec.allowlist,
    read_only: spec.read_only || [],
    blocked_until_gate_allowlist: spec.blocked_until_gate_allowlist || [],
    off_limits: [
      ...commonOffLimits,
      ...(migrationTouched ? [] : ["database/migrations/**"]),
      ...(spec.family === "KIOSK" ? ["resources/js/components/admin/pos/**"] : []),
      ...(spec.family === "POS" ? ["resources/js/components/frontend/kiosk/**"] : []),
    ],
    depends_on_lots: spec.depends || [],
    gate_conditions: spec.gates || [],
    migration_policy: migrationTouched
      ? "STOP unless docs/gates/GATE_LOG.md contains the relevant human schema/M-13 approval and rehearsal evidence is referenced."
      : "No DB migration authorized in this lot.",
    invariants_at_risk: spec.invariants,
    symmetry_note_required: Boolean(spec.symmetry),
    mandatory_tests: spec.tests,
    self_audit_checklist: commonChecklist,
    rollback: {
      feature_flag: null,
      max_window_days: 7,
      predicates: ["mandatory_tests red", "scope or gate violation", "FoodKing invariant regression"],
    },
    graphiti_query: `FoodKing Caisse V1 Wave2 ${spec.lot} ${spec.family} ${spec.objective}`,
    memory_episode_to_write_on_close: "memory/episodes/caisse_v1_wave2_option_b_2026-04-26.jsonl",
    claude_audit_prompt_id: `audit-prompt-w2-${spec.lot.toLowerCase()}`,
  };
}

function briefFor(spec) {
  const gateLines = (spec.gates || ["No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits."]).map((g) => `- ${g}`).join("\n");
  const allowLines = spec.allowlist.map((p) => `- \`${p}\``).join("\n");
  const testLines = spec.tests.map((t) => `- \`${t}\``).join("\n");
  return `# Execute Brief — ${spec.task}

Wave: Caisse V1 Wave 2 Option B  
Run order: ${spec.n}/36  
Lot: ${spec.lot} (${spec.family})  
Status: \`${spec.status}\`

## Objective

${spec.objective}

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate \`CV1-M04A-PAYMENT-LEDGER-FULL\`. Do not expand to full ledger scope without a new human gate.

## Allowlist

${allowLines}

## Gates

${gateLines}

## Tests

${testLines}

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  \`bash scripts/agent-activity-log.sh start codex-extension ${spec.task} execute "allowlist from input.json" "W2 ${spec.lot}"\`
- If a required file outside allowlist is needed, return \`SCOPE_PRESSURE\`; do not edit it.
- If a gate is unmet, return \`BLOCKED_GATE\`; do not edit frozen/schema/payment-ledger scope.
- Trace \`EXECUTE_DELEGATION: codex-extension\`.
- If touching \`OrderService.php\` or \`FrontendOrderService.php\`, add \`SYMMETRY_NOTE\` to output/report.
`;
}

function planExcerptFor(spec) {
  return `# Plan Excerpt — ${spec.task}

Source run order: \`missions/W2_LOT_CODEX_RUN_ORDER_OPTION_B_2026-04-26.md\`  
Source plans:

${commonPlanFiles.map((p) => `- \`${p}\``).join("\n")}

Lot: \`${spec.lot}\`  
Family: \`${spec.family}\`  
Objective: ${spec.objective}

Invariants:

${spec.invariants.map((i) => `- ${i}`).join("\n")}
`;
}

function readmeFor(spec) {
  return `# Mission ${spec.task}

Prepared for Caisse V1 Wave 2 Option B.

Run:

\`\`\`bash
npm run codex:plan-review -- ${spec.task}
bash scripts/agent-activity-log.sh start codex-extension ${spec.task} execute "allowlist from input.json" "W2 ${spec.lot}"
npm run codex:complex -- ${spec.task}
\`\`\`

Do not run if \`input.json.status\` is blocked and the referenced human gate is not signed.
`;
}

let created = 0;
let kept = 0;
for (const spec of specs) {
  const dir = path.join(root, "missions", spec.task);
  const files = [
    ["input.json", `${JSON.stringify(inputFor(spec), null, 2)}\n`],
    ["execute_brief.md", briefFor(spec)],
    ["plan_excerpt.md", planExcerptFor(spec)],
    ["graphiti_context.md", `Wave 2 Option B ${spec.lot}: query Graphiti group foodking for ${spec.family}, ${spec.objective}\n`],
    ["README.md", readmeFor(spec)],
  ];
  for (const [name, body] of files) {
    if (writeFileOnce(path.join(dir, name), body)) created += 1;
    else kept += 1;
  }
}

if (specs.length !== 36) {
  console.error(`Expected 36 specs, got ${specs.length}`);
  process.exit(1);
}

console.log(`W2 Option B missions prepared: ${specs.length}`);
console.log(`Files created/updated: ${created}`);
console.log(`Files preserved: ${kept}`);
console.log(force ? "Mode: force" : "Mode: preserve existing files");
