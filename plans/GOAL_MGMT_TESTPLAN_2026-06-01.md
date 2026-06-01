# GOAL — Crucial Test Plan: Management / Dashboard / Historique / Data-Recording (V1 Le Cayenne)

**Goal ID:** `GOAL_MGMT_TESTPLAN_2026-06-01` · **Author:** Claude (supervisor/orchestrator) · branch `heal/cms-pr1-quickwins-2026-05-18`
**Status:** PLAN — awaiting owner GO to execute. **Full 143-task map:** `plans/GOAL_MGMT_TESTPLAN_2026-06-01_APPENDIX_full-map.md`.

---

## §0 — Preamble

### 0.1 Mission
We built a large management surface and called V1 "done" — but **never page-by-page audited it**. This plan tests, page by page, button by button: every dashboard page + sub-page, every nav button (does it lead to a working page?), every management/CRUD function, and above all **the historique + data-recording integrity** ("is data well recorded, no bad organization?"). Methodology = E2E (Playwright capture+analyze) + GStack + Superpowers + Adversarial, looped to convergence.

### 0.2 Verified surface (anchor-first, discovery workflow `wqmnhj0k1`, 11 agents)
- **185 admin Vue-SPA routes** · **91 admin controllers** (`app/Http/Controllers/Admin/`) · **620 Feature tests** (acceptance pool).
- Decomposed into **10 management areas → 143 candidate test tasks** (full map in the APPENDIX).
- Runtime flags confirmed: `pos.manual_discount_enabled=true` (discounts LIVE), `features.offers_enabled=false` (Offers mutations 403).

### 0.3 Scope
- **IN:** all `/admin/*` management pages, nav/button reachability, CRUD correctness, historique + data-recording integrity, dashboard KPIs, cash/transactions reconciliation, reports, users/RBAC, settings cluster, notifications.
- **OUT (already proven this session, not "management"):** POS caisse order flow + Kiosk borne + KDS bump + OSS (proven in `real-sim/` + `massive-systems`); NF525 fiscal chain endurance (the running 10h soak). The critic flagged KDS/POS controllers as "uncovered by mgmt areas" — correct: they are operational, out of this management plan's scope, and already exercised.

### 0.4 Per-task pipeline + composition
Each task executes via `ultra-audit-profond` (5 read-only specialists → implement-if-needed → RED → test → visual → adversarial-visual). Single-page visual loops use `test-e2e`. Frozen-zone touches require `lock-plan`. This GOAL does not re-describe those pipelines.

### 0.5 ⚠️ Concurrency constraint — the 10h soak
A `foodking:e2e:soak --hours=10 --fail-fast` is running (NF525/memory/outbox endurance). Until it finishes:
- Execution is **read/capture-heavy only** (navigate + screenshot + Read + SELECT-only DB asserts + run the existing PHPUnit pool on :memory: sqlite — which never touches the live MySQL/soak).
- **Destructive admin writes** (create/update/delete/toggle on live data) that could trip the soak's `--fail-fast` are **deferred to a post-soak wave** OR done on items the soak never touches (it orders item 1/52/58; it toggles availability via S5). 86-toggle sync tests use a non-soak item, reverted immediately.

### 0.6 Convergence criteria (DONE =)
Two consecutive cycles with **P0+P1 = 0 AND identical findings sets**, every crucial-spine task GREEN, every nav button proven to reach a working page (or documented orphan), every data-recording invariant asserted, frozen-zone diff = 0, NF525 chain CHAIN OK, 0 console errors / 0 raw labels on every captured page.

---

## §1 — The 10 management areas (verified anchors + maturity)

| # | Area | Pages | Controllers (anchor) | Existing tests | Maturity |
|---|---|---|---|---|---|
| A1 | Dashboard + Navigation | 12 | DashboardController, AnalyticController | DashboardBranchScopeMatrixTest, EodPdfRecapSentinelTest | 🟡 KPI semantics (DASH-01) |
| A2 | Catalogue/Products/Categories/Composer | 12 | Item*, ItemCategory, MenuController, Composer* | Catalog/*Test (12+) | 🟢 well-tested |
| A3 | Ingredients + Stock + Availability | 3 | Ingredient, StockRupture, Availability | StockRuptureDashboardEndpointsTest | 🟢 |
| A4 | Coupons + Offers + Loyalty | 6 | Coupon, Offer, PosLoyalty, LoyaltySetup | (sparse) | 🟡 COUPON-CAP-01 |
| **A5** | **Historique + Orders data** ⭐ | 7 | OrderHistory, PosOrder, OnlineOrder, TableOrder, OSS | OrderHistoryUnifiedTest, RefundCounterEntry* | 🟡 owner #1 fear |
| **A6** | **Encaissement + Cash + Transactions** ⭐ | 6 | CashOverview, CashSessionReport, Transaction, CreditBalance | PosCashTrailTest, CashOverviewControllerTest | 🟡 3-store recon |
| A7 | Reports | 3 | SalesReport, ItemsReport, Analytic | (sparse) | 🟡 |
| A8 | Users + RBAC + Addresses | 15 | Administrator, Employee, Chef, Waiter, Customer, DeliveryBoy, Role, Permission | UserMassAssignmentTest, EmployeeRequestAuthorizeTest | 🟡 authz breadth |
| A9 | Settings / Config (cluster) | 26 | 26 controllers (Company…DiningTable) | (very sparse) | 🔴 least-tested |
| A10 | Notifications + Communications | 6 | Notification, PushNotification, Message, Subscriber | (sparse) | 🟡 |

---

## §2 — Tiering (crucial first — this is what "turn it until done" runs)

> 143 tasks fully-run = unexecutable shelf-ware. We run a **CRUCIAL P0 spine** to convergence first, then breadth in waves. The owner stated the fear twice ("will data be well recorded?") → the spine is data-recording + money + reachability.

- **TIER 0 — CRUCIAL SPINE (Wave B):** A5 (historique/data-recording) + A6 (cash 3-store reconciliation) + A1 nav-button reachability + A1 dashboard KPI semantics. ~40 tasks. **Loop to convergence before breadth.**
- **TIER 1 — BREADTH (Waves C–E):** A2, A3, A4 (read-side now / write-side post-soak), then A7, A8, A9, A10. ~103 tasks.

---

## §3 — CRUCIAL SPINE (detailed, grounded)

### §3.A5 — Historique + Orders data-recording ⭐ (owner's #1 fear)
**Anchors:** `OrderHistoryController.php`, `PosOrderController.php`, `OnlineOrderController.php`, `TableOrderController.php`, `OrderStatusScreenController.php`; `historiqueRoutes.js` (`/admin/historique`).
**Data-recording invariants to assert:** every order recorded **once** (no dup/leak), correct **origin badge**, correct **fiscal_sequence_no** (or "—" if unpaid), correct **payment status**, immutable **composition_snapshot**, **no cross-branch leak**.
| Task | Kind | Acceptance (verified ✓ / to-create) |
|---|---|---|
| HIST-01 unified table lists every origin once | happy | ✓ `OrderHistoryUnifiedTest::test_admin_lists_every_origin` |
| HIST-02 payload carries NF525 fields (fiscal_seq, parent_order_id, source_surface) | data | ✓ `OrderHistoryUnifiedTest::test_payload_exposes_nf525_traceability` |
| HIST-03 origin filter scopes correctly | happy | ✓ `OrderHistoryUnifiedTest::test_source_surface_filter_scopes_origin` |
| HIST-04 'En ligne' filter coverage (source_surface=app) | adversarial | TO BE CREATED `OrderHistory/OrderHistoryOnlineFilterAppCoverageTest.php` |
| HIST-05 NULL/dirty source_surface mis-badge fallback | data | TO BE CREATED `OrderHistory/OrderHistoryOriginBadgeFallbackTest.php` |
| HIST-06 refund mirror is its own refund-tagged row | data | ✓ `Fiscal/RefundCounterEntryNettedInZTest.php` |
| HIST-07 duplicate counter-entry mirror blocked (recorded once) | adversarial | ✓ `Refund/RefundCounterEntryUniqueParentTest.php` |
| HIST-08 cross-branch order detail → 403 | adversarial | TO BE CREATED `OrderHistory/OrderHistoryShowCrossBranch403Test.php` |
| HIST-09 no pos/pos-orders permission → forbidden | adversarial | ✓ `OrderHistoryUnifiedTest::test_user_without_order_permissions_is_forbidden` |
| HIST-10 detail composition_snapshot integrity (receipt matches) | data | TO BE CREATED `OrderHistory/OrderHistoryShowSnapshotIntegrityTest.php` |
| HIST-11 visual: unified table, badges/chips, no raw label | visual | TO BE CREATED `tests/e2e/historique-unified.spec.js` |
| HIST-12 nav-button: sidebar Historique + dashboard tile + row "Voir" all reach working pages | nav-button | TO BE CREATED `tests/e2e/historique-nav.spec.js` |
| HIST-13 OSS public wall ships no PII | data | TO BE CREATED `OrderStatusScreen/OssPublicNoPiiTest.php` |

### §3.A6 — Encaissement + Cash + Transactions ⭐ (3-store money reconciliation)
**Anchors:** `CashOverviewController.php`, `CashSessionReportController.php`, `TransactionController.php`, `CreditBalanceReportController.php`, `DeliveryBoyCashSessionController.php`; `PaymentService.php`, `SplitPaymentService.php`, `CashDrawerService.php`.
**Invariant:** zero mismatch across `order_payments` / `transactions` / `cash_movements`; direct POS sale = cash_movement only; counter-collect = cash_movement + transaction(counter_cash); fiscal allocated atomically at counter-PAID.
| Task | Kind | Acceptance |
|---|---|---|
| ENC-01 Cash Overview aggregates by_source (today window) | happy | ✓ `Admin/CashOverviewControllerTest::test_aggregates_summary_by_source` |
| ENC-02 counter-collect CASH writes exactly 1 Transaction(counter_cash) | data | ✓ `Pos/PosCashTrailTest::test_regression_kiosk_counter_collect_*` |
| ENC-03 direct POS CASH w/o open drawer session behavior | edge | ✓ `Pos/PosCashTrailTest::test_pos_cash_without_open_session_*` |
| ENC-04 two cashiers collect same pending-counter order concurrently | adversarial | ✓ `Payment/PosCounterCollectRaceProtectionSentinelTest.php` |
| ENC-05 fiscal seq allocated atomically at counter PAID | data | ✓ `Sentinels/F001KioskFiscalSequenceInvariantSentinelTest.php` |
| ENC-06 reconciliation card (expected_cash) integrity | adversarial | ✓ `Admin/CashOverviewControllerTest::test_cash_session_reconciliation` |
| ENC-07 cash_back/refund rows excluded from cash aggregate | data | ✓ `Admin/CashOverviewControllerTest::test_excludes_cash_back_rows` |
| ENC-08 branch isolation — manager sees own-branch only | adversarial | ✓ `Admin/CashOverviewControllerTest::test_branch_manager_only_sees` |
| ENC-09 cash session report variance + reason | happy | ✓ `Admin/CashSessionReportControllerTest.php` |
| ENC-10/11 delivery-boy cash session idempotent open + over-threshold reconcile | edge | ✓ `Admin/DeliveryBoyCashSessionControllerTest.php` + `Sentinels/DeliveryBoyCashSessionLifecycleTest.php` |
| ENC-12 split payment (1 CASH + 1 CARD) persists 2 order_payments | data | ✓ `Pos/PosCashTrailTest::test_split_payment_one_cash_one_card` |
| ENC-13 visual: Cash Overview cards/filters/totals, no NaN | visual | TO BE CREATED `tests/e2e/cash-overview.spec.js` |

### §3.A1 — Dashboard + Navigation (button→page reachability + KPI semantics)
**Anchors:** `DashboardController.php`, `DashboardService.php`, `DashboardComponent.vue`, `BackendMenuComponent.vue`, `v1-hidden-modules.js`.
| Task | Kind | Acceptance |
|---|---|---|
| DASH-T01 dashboard loads, all KPI cards populated | happy | ✓ `Dashboard/DashboardBranchScopeMatrixTest::test_admin_dashboard` |
| **DASH-T02 "Total commandes" KPI not DELIVERED-only (DASH-01 fix)** | data | TO BE CREATED `Dashboard/TotalOrdersCountSemanticsTest.php` |
| DASH-T03 branch manager sees own-branch aggregates only | adversarial | ✓ `Dashboard/DashboardBranchScopeMatrixTest::test_branch_dashboard` |
| DASH-T04 dashboard requires `permission:dashboard` | adversarial | ✓ `Dashboard/DashboardBranchScopeMatrixTest::test_dashboard_permission` |
| DASH-T05 EOD PDF returns valid PDF, gated | data | ✓ `Dashboard/EodPdfRecapSentinelTest.php` |
| DASH-T07 channelStatistics buckets kiosk as Kiosk/App | data | ✓ `Analytics/KioskSourceMiscountSentinelTest.php` |
| DASH-T08 Paris-day boundary inclusion | edge | ✓ `TimeZone/DashboardSalesReportParisBoundsSentinelTest.php` |
| DASH-T09 auditTrail widget reads hash-chained AuditLog | data | ✓ `Dashboard/AuditTrailUsesAuditLogSentinelTest.php` |
| **DASH-T10 EVERY sidebar + quick-access button leads to a working page** ⭐ | nav-button | TO BE CREATED `tests/e2e/dashboard-nav-buttons-reachability.spec.js` |
| DASH-T11 V1-hidden modules absent from sidebar | edge | TO BE CREATED `tests/e2e/dashboard-hidden-modules-not-in-sidebar.spec.js` |
| DASH-T12 sidebar permission-filtering (POS-operator sees subset) | adversarial | TO BE CREATED `tests/e2e/dashboard-sidebar-permission-filtering.spec.js` |
| DASH-T13 visual integrity 1920/2560 (no overflow) | visual | TO BE CREATED `tests/e2e/dashboard-visual-integrity.spec.js` |

---

## §4 — BREADTH (Tier 1, summarized — full tasks in APPENDIX)
| Area | Anchors (verified) | Key crucial tasks | Tasks | Existing tests to lean on |
|---|---|---|---|---|
| A2 Catalogue | Item*, ItemCategory, Menu, Composer* | category-rename sync, item CRUD + kiosk-cache invalidation, photo pipeline, composer profile immutability | 15 | `Catalog/CategoryRenameSyncTest`, `Catalog/ItemUpdateInvalidatesKioskCacheSentinelTest`, `Composer/ItemWizardStepVersion*` |
| A3 Ingredients/Stock | Ingredient, StockRupture, Availability | 86-toggle sync (proven live this session), stock ledger, low-alert N+1 | 15 | `Admin/StockRuptureDashboardEndpointsTest`, `Admin/StockRuptureDashboardLowAlertsN1Test`, `Availability/StockReleaseTest` |
| A4 Coupons/Offers/Loyalty | Coupon, Offer, PosLoyalty, LoyaltySetup | **COUPON-CAP-01 (max_uses_global unenforced) P1**, Offers-disabled 403, loyalty redeem | 15 | (TO BE CREATED: `Coupon/CouponMaxUsesGlobalEnforcementTest.php`) |
| A7 Reports | SalesReport, ItemsReport, Analytic | revenue paid-only reconciliation, export PDF/Excel | 13 | (TO BE CREATED: `Reports/SalesReportReconciliationTest.php`) |
| A8 Users/RBAC | Administrator, Employee, Chef, Waiter, Customer, DeliveryBoy, Role, Permission, *Address | per-type CRUD, **per-type Address sub-resource CRUD** (critic gap), mass-assignment, cross-branch | 15 | `Auth/UserMassAssignmentTest`, `Admin/EmployeeRequestAuthorizeTest` |
| A9 Settings (🔴 least-tested) | 26 controllers | each sub-page saves+reloads; **SET-01 payment-gateway secret exposure**; device cluster (terminals/printers/kiosk-machines/dining-tables) | 13 + the **critic-gap sub-pages**: Currency, Language, Pages(CMS), Slider, Theme, Site, Mail, OTP, License, Cookies, TimeSlot | (very sparse — most TO BE CREATED) |
| A10 Notifications | Notification, PushNotification, Message, Subscriber, NotificationAlert | **SUB-1 mass-mail gating**, push send+log, FCM/Alert settings pages | 14 | (TO BE CREATED) |

---

## §5 — Findings already surfaced by discovery (verify live, then fix/document)
1. **Nav-reachability candidates (verify live via DASH-T10, NOT yet confirmed defects)** — pages flagged by mappers as possibly not nav-reachable: Credit Balance Report, Cash Sessions Report, Payment Terminals, Notification (FCM), Notification Alert, item-composer-by-URL, wizard-launcher, refund-tag. (My static grep was unreliable; each must be confirmed by navigating live + checking the rendered sidebar/menu-table. Dining Tables = reachable via separate nav, not an orphan.)
2. **DASH-01 (P2)** — "Total commandes" KPI counts DELIVERED-only (`DashboardService::totalOrders():344`) → misleading. → DASH-T02.
3. **COUPON-CAP-01 (P1)** — `max_uses_global` unenforced (usage_count never incremented). → A4 task.
4. **Critic GAPS resolution:** KDS/POS controllers = OUT of management scope (operational, already exercised this session). Settings sub-pages (Currency/Language/Pages/Slider/Theme/Site/Mail/OTP/License/Cookies/TimeSlot) + per-type Address CRUD = genuinely uncovered → **folded into A9 / A8** above. DefaultAccess/CountryCode = lookup helpers (no page, minor).

---

## §A — Agent army + fan-out matrix (E2E + GStack + Superpowers + Adversarial)
| Role | Subagent | Tools | Fires on |
|---|---|---|---|
| Architect | Plan/general | read-only | every task (anchor/contract) |
| Security/RED | general | read-only | adversarial + authz + data-integrity tasks |
| DBA | general | read | data-recording + reconciliation tasks |
| QA-Visual | general | read + Playwright | visual + nav-button tasks (capture) |
| RED-Visual | general | read | dispute QA captures (parallel, anti-confirmation-bias) |
| Implementer | general | edit+write+bash | only when a fix/new-test is needed (TDD, never parallel with another implementer) |

**Dispatch:** read-only specialists = single-message parallel fan-out; Implementer sequential; QA-Visual ∥ RED-Visual; RED dispute ALWAYS before declaring a task DONE. Per-task = `ultra-audit-profond`.

---

## §X — Convergence waves (soak-aware)
- **Wave A — Pre-flight** (read-only): confirm soak alive + baselines (PHP suite count, chain OK, frozen 0). Run the **existing crucial-spine PHPUnit pool** (OrderHistoryUnifiedTest, PosCashTrailTest, CashOverviewControllerTest, DashboardBranchScopeMatrixTest, F001 sentinel) on :memory: sqlite — **safe during soak** (never touches live MySQL). Capture each crucial page live (read-only) + Read.
- **Wave B — CRUCIAL SPINE to convergence** (A5+A6+A1): run existing tests + author the TO-BE-CREATED tests (TDD) + live nav-button reachability sweep + visual. Loop until P0+P1=0 ×2.
- **Wave C — Catalogue/Stock/Coupons read-side** (A2/A3/A4 read + COUPON-CAP-01 test). Destructive CRUD writes **deferred to post-soak**.
- **Wave D (POST-SOAK) — Settings/Users/Notifications write-side** (A9/A8/A10): create/update/delete CRUD tests + the 26 settings sub-pages + Address CRUD. Run only after the soak's terminal verdict (destructive admin writes could trip `--fail-fast`).
- **Wave E — Reports + final convergence** (A7) + round-2 re-run of B+C + adversarial supervisor + convergence book.
- **Checkpoint each wave:** all PASS-or-documented, frozen diff 0, chain OK, visual gate fired, RED dispute done, BRAIN updated. Interrupt-resume per skill Axis 3.

---

## §G — Owner gates
| Gate | What | WHO | Trigger |
|---|---|---|---|
| G1 | Authorize post-soak destructive wave (Wave D) — confirm soak finished + DB acceptable to mutate | Owner | before Wave D |
| G2 | COUPON-CAP-01 (P1) fix — enforce max_uses_global or accept-with-doc | Owner | A4 |
| G3 | DASH-01 relabel — "Commandes livrées" vs count-all | Owner | A1 |
| G4 | Any confirmed orphan page — wire to nav vs remove vs accept | Owner | DASH-T10 result |

## §F — Final rule
DONE = crucial spine (data-recording + money + reachability) GREEN to convergence (P0+P1=0 ×2), every nav button proven to reach a working page or documented orphan, every data-recording invariant asserted (no dup/leak/mis-badge/mismatch), breadth waves GREEN, frozen 0, NF525 CHAIN OK, 0 console errors / 0 raw labels. Production-perfect, not "almost there."
