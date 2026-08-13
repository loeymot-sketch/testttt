# GOAL — Admin Dashboard + Full Nav Bar: Breadth Convergence (Settings/Users/Notifications/Reports + drift recheck)

**Goal ID:** `GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13` · **Branch:** `pos/category-first-caisse-2026-06-23` · **HEAD:** `c0d0caab4`
**Owner /goal (paraphrased):** audit + fix + optimise TOUTE l'interface admin en partant du Dashboard puis toute la barre de navigation, page par page, bouton par bouton, jusqu'à fonctionnalité réelle (pas juste "la page s'ouvre") — boucle audit→plan→fix→dispute (agents adversaires + agents de logique) jusqu'à convergence — livraison uniquement après e2e réel navigateur.

---

## §0 — Preamble

### 0.1 This is NOT a fresh start — it is the missing half of prior art
`GOAL_MGMT_TESTPLAN_2026-06-01.md` (+ its `_APPENDIX_full-map.md`, 629 lines, 143 candidate tasks) already ran this exact mission once. Its **Tier 0 crucial spine** (A5 Historique + A6 Cash + A1 Dashboard/Nav) **converged 2026-06-03** — see `reports/test-e2e/mgmt-testplan-2026-06-03/CONVERGENCE_FINAL.md`: 25/25 sidebar+quick-access buttons proved to reach a real working page, dashboard KPIs verified, historique/cash data-recording invariants asserted, 2807 PHPUnit green, frozen 0, NF525 CHAIN OK. **Tier 1 breadth (Wave D: Settings/Users/Notifications, Wave E: Reports) was explicitly deferred** ("post-soak owner gate") and **never executed**. Those are exactly the areas flagged 🔴 "least-tested" in the June audit — and exactly where "the page opens but doesn't really do anything" lives. Re-deriving all this from scratch would be wasted work; this GOAL **inherits the June appendix's anchored task catalog** (real file:line, real bugs already found with reproduction steps) and adds: (a) a fast drift recheck since 163 admin-scope files changed in the 2+ months since convergence, (b) the genuinely new CENTRAL nav surface added since then, (c) execution of the deferred breadth.

### 0.2 Anchor-first verification performed this session (not assumed)
- `git diff --stat 59c95085a..HEAD` (spine-convergence commit → current HEAD) touching `app/Http/Controllers/Admin/**`, `resources/js/components/admin/**`, `BackendMenuComponent.vue`, `v1-hidden-modules.js`: **163 files, +16683/-922**. Sidebar component itself: **75 lines changed**. `v1-hidden-modules.js`: **10 lines changed** (loyalty-setup unhidden 2026-08-10).
- `ls app/Http/Controllers/Admin/*.php` (+subdirs): **100 top-level controllers now** (was 91 in June) — **15 new**: `Pilotage/InterrupteurController`, `Pos/{KitchenTicketQueue,PosCustomerDisplay,PosTicketBytes}Controller` (CAISSE-lane per SYSTEM_MAP §2, out of scope here), `PosStockOutflowController`, `PosSystemHealthController`, `PromoFlyerController` + `UberPhotoCaptureController` (both explicitly CAISSE-lane per `SYSTEM_MAP.md:43-44`, both already deep-audited per memory `ticket_promo_plateformes_2026-08-07` / `uber_photo_*_2026-08-12`, out of scope here), `PurchasingScanController`, `UnifiedStockViewController`, `Wheel/{WheelAccess,WheelCounter,WheelPrize,WheelSettings,WheelUnlock}Controller` (5 — extensively audited across ≥6 dedicated sessions per memory `roue_*`/`fidelite_*`/`deploy_fidelite_roue_live_2026-08-12`, out of scope here).
- `resources/js/components/layouts/backend/BackendMenuComponent.vue:85-160` (hardcoded `V1_PRIMARY_SIDEBAR_MENUS` array, read directly): confirms **3 genuinely new, CENTRAL-owned, zero-prior-audit nav entries** since June — `catalog-hub` (permission `items`), `stock/unified` (permission `items`, read-only per inline comment), `purchasing/scan` (permission `items_create`). These did not exist in the June `PAGE_INVENTORY_DASHBOARD_2026-06-08.md`.
- `find resources/js/components/admin/settings -maxdepth 1 -type d`: **26 settings sub-dirs**, matches June's count — cluster itself has NOT grown, only its test coverage is the gap.
- `find tests/Feature -iname "*Currency*" -o -iname "*Slider*" -o -iname "*Theme*" ...` etc.: confirms the June completeness-critic's claim still holds — **12 of 26 settings sub-pages have zero dedicated test file** (currencies, languages, pages/CMS, sliders, theme, social-media, site, mail, otp, license, cookies, time-slots). Only `LicenseKeyReadAuthzSentinelTest.php` exists in that set.
- `find tests/Feature -iname "*Administrator*Test*" -o -iname "*Employee*Test*" -o -iname "*Chef*Test*" ...`: confirms **no dedicated per-type CRUD test file** for Chef/Waiter/Customer/DeliveryBoy exists (only peripheral guard tests: `EmployeeRequestAuthorizeTest`, `EmployeePeerManagementGuardTest`, `AdministratorBranchZeroMintBypassSentinelTest`, `AddressSecurityTest`, `DeliveryBoyAddressPermissionSplitTest`). **Zero per-type Address CRUD test exists** for the 6 `*AddressController`s despite them being live, routed sub-resources.
- `find tests/Feature -iname "*Notification*Test*" -o ...`: confirms **zero CRUD test** for Push/Message/Subscriber/NotificationAlert beyond `PushNotificationTenantIsolationTest` (fan-out only, not the input-side spoof the June audit flagged) and `MessageIdorTest`.
- `find tests/Feature -iname "*SalesReport*Test*" -o -iname "*ItemsReport*Test*"`: confirms only sentinel-level parity/net-total/units-sold tests exist — the June-flagged **screen-vs-export divergence bugs (REP-03/04) have no regression test**.

### 0.3 Working-tree decision
`git status` shows **uncommitted work from a prior session** (kitchen-ticket-queue auto-print fix, per `PROJECT_BRAIN.md §2` dated 2026-08-13, already tested 20/20 green) plus untracked Uber/Wheel test files from the session before that. **None of these files overlap this GOAL's scope** (Settings/Users/Notifications/Reports/new-CENTRAL-nav vs. Pos/Uber/Wheel). Decision: **leave as-is, do not touch, do not commit on their behalf** — out-of-lane per `PARALLEL_PROTOCOL.md` (never commit another wave's uncommitted work). This GOAL's own commits will `git add` only the specific files it creates/edits.

### 0.4 Per-task pipeline
Each task below executes via the `ultra-audit-profond` 14-step pipeline (5 read-only specialists → implement-if-needed, TDD → RED adversarial dispute → test → visual where frontend → adversarial-visual dispute). This GOAL does not re-describe that pipeline. Frozen-zone touches (none expected in this scope — Settings/Users/Notifications/Reports/new-nav touch zero §7 frozen files) would require `lock-plan`.

### 0.5 Scope
**IN:** Dashboard/Nav drift recheck (Wave 0) · 3 new CENTRAL nav entries (catalog-hub, stock/unified, purchasing/scan) · Settings cluster 26 sub-pages (12 zero-coverage + re-verify the 14 partially-covered) · Users/RBAC 8 person-types + Roles/Permissions + 6 Address sub-resources · Notifications (Push/Messages/Subscribers/NotificationAlert) · Reports (Sales/Items/Analytics) · Pilotage/Interrupteur kill-switches screen (newly discovered, unclassified lane, defaults to CENTRAL per `SYSTEM_MAP.md:91` "any new admin/<dir> ≠ POS/KDS-lane defaults here").
**OUT (already proven elsewhere, do not duplicate):** POS/Kiosk/KDS/OSS operational screens (separate lanes, `SYSTEM_MAP.md §§1-4`) · Wheel/roue admin screens (≥6 dedicated audit sessions per memory, most recently 2026-08-12) · promo-flyer + uber-photo screens (CAISSE-lane, deep-audited 2026-08-07/08-10/08-12 per memory) · Catalogue/Stock/Coupons/Offers/Loyalty read-side (A2/A3/A4 — Wave C converged 2026-06-03, 403/0 fail; re-verify only if Wave 0 smoke flags regression) · frozen zones (§7) — untouched.

### 0.6 Convergence criteria (DONE =)
Two consecutive RED-dispute cycles per task-cluster with **P0+P1=0 AND identical findings sets**. Every task's acceptance test GREEN (existing test re-run, or TO-BE-CREATED test authored TDD-red-then-green). Every nav button in scope proven reachable live (Playwright, not static grep). Frozen-zone diff = 0 across the whole GOAL. Full PHPUnit suite green (baseline: re-capture at Wave 0, compare delta only). NF525 chain CHAIN OK if any wave touches fiscal-adjacent config (Tax settings). 0 console errors / 0 raw i18n labels on every captured screenshot. **"Almost works" is not DONE** — a screen-vs-export mismatch found in June and left unfixed is not converged, it's documented-and-open until healed or explicitly owner-deferred via §G.

---

## §1 — System anchor: CENTRAL (management)

Per `SYSTEM_MAP.md §5`: Frontend owned = `resources/js/components/admin/**` except POS/KDS-lane dirs; includes `settings, administrators, employees, chefs, waiters, customers, coupons, offers, ingredients, stock, salesReport, itemsReport, transactions, messages, pushNotification, subscribers, orderHistory, ...`. Backend owned = `app/Http/Controllers/Admin/**` (100 controllers) except the 7 explicitly-named POS/KDS ones. Sidebar SSOT = `BackendMenuComponent.vue` + DB. Bundles: `admin-shell.js` (117 chunks), `admin-reports.js`. Frozen zones touched by this scope: **none** (Settings/Users/Notifications/Reports are not in the §7 list).

---

## §2 — Wave 0: Drift recheck (fast, read-mostly, de-risks everything downstream)

**Why:** 163 files changed in CENTRAL scope since the last proven-green baseline (2026-06-03). Trusting a 2-month-old convergence report without re-running it would violate CLAUDE.md §12 anti-drift.

| Task | Kind | Acceptance |
|---|---|---|
| W0-01 Re-run the 14 crucial-spine tests from Wave B (HIST-04/05/08/10/13, ENC-13, DASH-T02/10/11/12/13, HIST-11/12) | regression | existing paths per `CONVERGENCE_FINAL.md` — all must still be GREEN on current HEAD |
| W0-02 Full nav-button reachability re-sweep (sidebar changed 75 lines since June — 25/25 is not guaranteed to still hold) | nav-button | `tests/e2e/dashboard-nav-buttons-reachability.spec.js` if it still exists on disk, else re-author at same path |
| W0-03 Full PHPUnit baseline capture (count + green/red) for later wave-delta comparison | baseline | `php artisan test` full run, record count (June baseline was 2807; a later session logged 4690/4686 on 2026-08-12 — use THAT as the live baseline, not June's) |
| W0-04 Frozen-zone + NF525 chain baseline | baseline | `git diff --stat HEAD -- <13 §7 files>` = 0 (nothing pending); `php artisan fiscal:verify-chain --all` = CHAIN OK |
| W0-05 Confirm the 3 new nav entries (catalog-hub, stock/unified, purchasing/scan) actually render live and are NOT already covered by an existing spec | nav-button | Playwright navigate + screenshot each; `grep -rl "catalog-hub\|stock/unified\|purchasing/scan" tests/e2e/` to confirm zero existing coverage (expected: none found) |

**Checkpoint:** if W0-01 or W0-02 finds a regression, that regression becomes a P0/P1 task inserted at the top of Wave 1 before any breadth work starts (a broken spine outranks new breadth).

---

## §3 — Wave 1: New CENTRAL nav surface (zero prior audit)

**Anchors (verified via `BackendMenuComponent.vue:85-118` read directly):**
- `catalog-hub` — permission `items`, wraps two existing screens (catalogue + item-attributes per inline comment `[CATALOG-HUB 2026-07-21]`)
- `stock/unified` — permission `items` (read-only per inline comment `[PHASE 3d-UI 2026-07-24]` "écran ADDITIF lecture seule, hors NF525") — component likely `resources/js/components/admin/stock/` (verify exact file at task time)
- `purchasing/scan` — permission `items_create` per inline comment `[ARCH_STOCK_INTELLIGENT_BOM P3c]` "Scan facture → entrée en stock" — controller `Admin/PurchasingScanController.php` (verified via `ls`)
- `Pilotage/Interrupteur` — controller `app/Http/Controllers/Admin/Pilotage/InterrupteurController.php` (verified via `ls`), router entry lives under `resources/js/router/modules/observabilityRoutes.js` (verified via grep) — matches memory `pilotage_sans_developpeur_2026-08-09` ("interrupteurs sans déploiement"; **⚠️ known constraint from that memory: `idempotency.enabled` must stay OUT of this screen — NF525, do not "fix" that as a gap**).

| Task | Kind | Acceptance |
|---|---|---|
| NAV1-01 catalog-hub renders both wrapped screens (catalogue tab + stock tab via `?tab=stock` query) without duplicating or losing either screen's functionality | happy | (TO BE CREATED at `tests/Feature/Admin/CatalogHubRenderTest.php`) |
| NAV1-02 stock/unified is genuinely read-only (no mutating action exposed) and reconciles with the source stock/ingredient tables it aggregates (matières + boissons + "à acheter") | data | (TO BE CREATED at `tests/Feature/Admin/UnifiedStockViewReadOnlyTest.php`) |
| NAV1-03 purchasing/scan CRUD: scanned invoice → stock entry persists, gated by `items_create`, rejects malformed/oversized upload | happy+adversarial | (TO BE CREATED at `tests/Feature/Admin/PurchasingScanControllerTest.php`) |
| NAV1-04 Pilotage/Interrupteur: each kill-switch toggle persists + propagates (Settings-outbox pattern per SET-T03), `idempotency.enabled` confirmed absent from the screen (regression lock per memory) | data+adversarial | (TO BE CREATED at `tests/Feature/Admin/Pilotage/InterrupteurTogglePersistenceTest.php`) |
| NAV1-05 visual: all 4 screens render without raw i18n labels, empty-state honest, no console error | visual | (TO BE CREATED at `tests/e2e/new-central-nav-visual.spec.js`) |

---

## §4 — Wave 2: Settings cluster (26 sub-pages, 🔴 least-tested, the deferred Wave D)

**Anchors (verified):** `resources/js/router/modules/settingRoutes.js` · `resources/js/components/admin/settings/{Company,PaymentGateway,SmsGateway,Tax,Branch,OrderSetup,KioskSetup,Currency,Language,Page,Slider,Theme,SocialMedia,Site,Mail,Otp,License,Cookies,TimeSlot,PaymentTerminals,Printers,Role,LoyaltySetup,NotificationAlert,Notification,Fiscal,KioskMachine,ItemAttribute,ItemCategory,analytics}/` (29 dirs found; 26 distinct settings controllers per §0.2) · `SettingsComponent.vue` (shell) · `MenuComponent.vue` (tab nav + `isSettingHidden()`).

**Carried forward from June (real bugs, need RE-VERIFY on current HEAD before healing — do not assume still broken):**

| Task | Kind | Acceptance | June status |
|---|---|---|---|
| SET-T01 Company settings round-trips (save→reload persists) | happy | (TO BE CREATED `tests/Feature/Settings/CompanySettingsRoundTripTest.php`) | never run |
| **SET-T02 (SET-01, P1) payment-gateway `index` leaks secret `value` to any authenticated non-settings user** — `GatewayOptionsResource` no encrypted cast, route group only `auth:sanctum` | adversarial | (TO BE CREATED `tests/Feature/Settings/PaymentGatewaySecretAuthzTest.php`) | flagged, never fixed — RE-VERIFY FIRST |
| SET-T03 Tax CRUD propagates via outbox (DomainEvent per branch), idempotent | data | ✓ `tests/Feature/Settings/SettingsUpdatedBroadcastTest.php` (exists — re-run) | exists |
| SET-T04 OrderSetup rejects negative thresholds | edge | ✓ `tests/Feature/OrderSetupRequestNegativeValuesTest.php` (exists — re-run) | exists |
| SET-T05 Company-name `.env`-injection guard (newline/quote/`=`) | adversarial | (TO BE CREATED `tests/Feature/Settings/CompanyEnvInjectionGuardTest.php`) | flagged, never fixed |
| SET-T06 Orphan-page: payment-terminals + dining-tables have no settings-sidebar nav entry (direct-URL-only) | nav-button | (TO BE CREATED `tests/Feature/Settings/SettingsNavReachabilityTest.php`) | flagged — confirm still true or already wired |
| SET-T07 Branch store/update preserves BranchScope isolation invariant | data | (TO BE CREATED `tests/Feature/Settings/BranchSettingsScopeIntegrityTest.php`) | never run |
| SET-T08 Payment-terminal fee CRUD round-trip | happy | ✓ `tests/Feature/Admin/PaymentTerminalControllerTest.php` (exists — re-run) | exists |
| SET-T09 Printer test-print branch-scoped + gated | happy | ✓ `tests/Feature/PrinterControllerTest.php` (exists — re-run) | exists |
| SET-T10 Mail config bad-SMTP doesn't crash queue | edge | ✓ `tests/Feature/Mail/EmailQueueResilienceSentinelTest.php` (exists — re-run) | exists |
| SET-T11 visual: each sub-page persists+reloads, no raw label | visual | (TO BE CREATED Playwright spec `tests/e2e/settings-persist-visual.spec.js`) | never run |
| SET-T12 Currency delete/edit guards against orphaning active quotes | adversarial | (TO BE CREATED `tests/Feature/Settings/CurrencyDeleteGuardTest.php`) | flagged, never fixed |
| SET-T13 Settings index-authz matrix (which GETs are intentionally open vs must be closed) | adversarial | (TO BE CREATED `tests/Feature/Settings/SettingsIndexAuthzMatrixTest.php`) | never run |

**New — the 12 zero-coverage sub-pages (June completeness-critic gap, never even started):**

| Task | Kind | Acceptance |
|---|---|---|
| SET-N01 Currencies CRUD (`/admin/settings/currencies`, `CurrencyController`) persists + reloads, delete-guard vs active quotes (dup-check with SET-T12) | happy+data | (TO BE CREATED `tests/Feature/Settings/CurrenciesCrudTest.php`) |
| SET-N02 Languages CRUD + lang-file editor (`LanguageController`) — cross-check against memory finding "92% of FR i18n = literal English copy" (`backoffice_export_blob_permission_inerte_2026-08-12`, PIÈGE n°4) — is this still true on current HEAD? | happy+data | (TO BE CREATED `tests/Feature/Settings/LanguagesEditorTest.php`) |
| SET-N03 Pages/CMS CRUD (`PageController`) persists rich content | happy | (TO BE CREATED `tests/Feature/Settings/PagesCmsCrudTest.php`) |
| SET-N04 Sliders CRUD + image upload (dangerous-extension guard, mirror NC-10 pattern) | happy+adversarial | (TO BE CREATED `tests/Feature/Settings/SlidersCrudTest.php`) |
| SET-N05 Theme index/update persists + actually changes rendered storefront theme (not a no-op setting) | happy+data | (TO BE CREATED `tests/Feature/Settings/ThemeUpdateEffectTest.php`) |
| SET-N06 Social Media index/update persists | happy | (TO BE CREATED `tests/Feature/Settings/SocialMediaUpdateTest.php`) |
| SET-N07 Site index/update persists | happy | (TO BE CREATED `tests/Feature/Settings/SiteUpdateTest.php`) |
| SET-N08 Mail (gateway config, distinct from SET-T10 queue-resilience) index/update persists, secret fields not leaked (mirror SET-T02 pattern) | happy+adversarial | (TO BE CREATED `tests/Feature/Settings/MailGatewaySettingsTest.php`) |
| SET-N09 OTP index/update persists, does not weaken `OtpBruteForceLockoutTest` invariant | happy+data | (TO BE CREATED `tests/Feature/Settings/OtpSettingsTest.php`) |
| SET-N10 License index/update — dormant V1-LOCAL, smoke-only (persist-integrity, no deep test) | smoke | (TO BE CREATED `tests/Feature/Settings/LicenseSettingsSmokeTest.php`) |
| SET-N11 Cookies banner index/update persists, renders on storefront | happy | (TO BE CREATED `tests/Feature/Settings/CookiesSettingsTest.php`) |
| SET-N12 Time Slots index/store/destroy — reject overlapping/empty slots (silently breaks frontend availability per June note) | happy+adversarial | (TO BE CREATED `tests/Feature/Settings/TimeSlotOverlapGuardTest.php`) |

**Address sub-resources (6 controllers, zero dedicated CRUD test — data-recording gap):**

| Task | Kind | Acceptance |
|---|---|---|
| SET-A01 Administrator address CRUD (add/edit/delete) persists + cascade-deletes with parent | data | (TO BE CREATED `tests/Feature/Administrator/AdministratorAddressCrudTest.php`) |
| SET-A02 Employee address CRUD | data | (TO BE CREATED `tests/Feature/Employee/EmployeeAddressCrudTest.php`) |
| SET-A03 Chef address CRUD | data | (TO BE CREATED `tests/Feature/Chef/ChefAddressCrudTest.php`) |
| SET-A04 Waiter address CRUD | data | (TO BE CREATED `tests/Feature/Waiter/WaiterAddressCrudTest.php`) |
| SET-A05 Customer address CRUD (V1-hidden module — smoke only, code/routes intact) | smoke | (TO BE CREATED `tests/Feature/Customer/CustomerAddressCrudTest.php`) |
| SET-A06 DeliveryBoy address CRUD — existing `DeliveryBoyAddressPermissionSplitTest.php` covers permission split only, not full CRUD round-trip | data | ✓ partial `tests/Feature/Delivery/DeliveryBoyAddressPermissionSplitTest.php` + (TO BE CREATED CRUD round-trip in same file or new `DeliveryBoyAddressCrudTest.php`) |

---

## §5 — Wave 3: Users + RBAC (8 person-types, the deferred Wave D continuation)

**Anchors (verified):** `AdministratorController/EmployeeController/ChefController/WaiterController/CustomerController/DeliveryBoyController/RoleController/PermissionController/SimpleUserController.php` · services `AdministratorService/EmployeeService/PermissionService/RoleService.php` (file:line cited below were verified in the June audit against that HEAD — **RE-VERIFY line numbers on current HEAD before citing them in fixes**, per Axis 1).

Carried forward, RE-VERIFY-then-heal (full task prose already in `plans/GOAL_MGMT_TESTPLAN_2026-06-01_APPENDIX_full-map.md:403-461`, IDs UR-01…UR-15 — not re-copied here to keep this doc under budget; each cites a real anchor + acceptance path). Highest-value re-verify targets (real bugs found in June, unknown current status):

| Task | Kind | Acceptance | June status |
|---|---|---|---|
| UR-02 password unchanged when update omits it, re-hashed when present | edge | (TO BE CREATED `tests/Feature/Admin/EmployeePasswordHashPreservationTest.php`) | never run |
| UR-05 admin cannot self-escalate via permission-sync on own role | adversarial | (TO BE CREATED `tests/Feature/Admin/PermissionSelfEscalationGuardTest.php`) | never run |
| UR-06 `PermissionService::syncPermissions` is destructive-WRITE (empty payload wipes role) — document/lock intended semantics | data | (TO BE CREATED `tests/Feature/Admin/PermissionSyncDestructiveSemanticsTest.php`) | never run |
| UR-07 cannot delete a protected system role | adversarial | (TO BE CREATED `tests/Feature/Admin/RoleProtectedDeletionGuardTest.php`) | never run |
| UR-08 cannot delete self or root admin (id=1) | adversarial | (TO BE CREATED `tests/Feature/Admin/AdministratorSelfAndRootDeletionGuardTest.php`) | never run |
| UR-13 mass-assignment guard (role_id/branch_id/is_guest/email_verified_at cannot be spoofed via extra POST fields) | adversarial | (TO BE CREATED `tests/Feature/Admin/UserMassAssignmentGuardTest.php`) | never run |
| UR-14 every Users/RBAC sidebar entry + row View + Role-show reaches a rendered page | nav-button | (TO BE CREATED `tests/e2e/users-rbac-nav.spec.js`) | never run |

Remaining UR-01/03/04/09/10/11/12/15 already have existing acceptance test files per the appendix (`AdminCrudComprehensiveTest`, `EmployeeRequestAuthorizeTest`, `AdministratorBranchZeroMintBypassSentinelTest`, `UserMgmtRoleTargetSentinelTest`, `DeliveryBoyHardeningSentinelTest`, `FormRequestAuthzDriftSentinelTest`) — **re-run only, no new authorship needed** unless red.

---

## §6 — Wave 4: Notifications + Communications (the deferred Wave D continuation)

**Anchors (verified):** `NotificationController/NotificationAlertController/PushNotificationController/MessageController/SubscriberController.php` + services. Full task prose in appendix lines 518-572, IDs NC-01…NC-14. Highest-value re-verify (real bugs found in June):

| Task | Kind | Acceptance | June status |
|---|---|---|---|
| NC-03 branch-scoped user cannot spoof `branch_id=0` to force a global broadcast (input-side, distinct from the already-proven fan-out-side isolation) | adversarial | (TO BE CREATED `tests/Feature/Admin/PushNotificationBranchIdSpoofTest.php`) | never run |
| NC-06 subscriber mass-mail: admin-entered subject must land in the actual `Subject:` header (currently hardcoded English `'Subscriber Notification'`, violates ADR-007 FR-lock) | data | (TO BE CREATED `tests/Feature/Admin/SubscriberMailSubjectTest.php`) | flagged bug, never fixed |
| NC-07 `changeStatus` (mark-as-read) route has no `permission:messages` gate at route level | adversarial | (TO BE CREATED `tests/Feature/Admin/MessageChangeStatusAuthzTest.php`) | flagged bug, never fixed |
| **NC-09 `PUT/PATCH /admin/message/{message}` routes to a controller method that does not exist — latent 500** | adversarial | (TO BE CREATED `tests/Feature/Admin/MessageUpdateRouteSentinelTest.php`) | flagged bug (verified via route:list + controller method list June), never fixed — RE-VERIFY route table first, this is the highest-severity single finding in this whole GOAL if still true |
| NC-11 notification-alert bulk-update maps payload rows to alert IDs positionally — shuffled/sparse indices could misassign a message to the wrong order-status template | data | (TO BE CREATED `tests/Feature/Admin/NotificationAlertUpdateMappingTest.php`) | never run |

Remaining NC-01/02/04/05/08/10/12/13/14 per appendix — several already have acceptance paths (`PushNotificationTenantIsolationTest`) or are net-new TDD tasks; execute per appendix prose.

---

## §7 — Wave 5: Reports (the deferred Wave E)

**Anchors (verified):** `SalesReportController/ItemsReportController/AnalyticController.php` + `OrderService::salesReportOverview/list`, `ItemService::itemReport/list`, `SalesReportExport/ItemsReportExport.php`. Full task prose in appendix lines 350-401, IDs REP-01…REP-13. Highest-value re-verify (documented query-divergence bugs, June-found, never fixed):

| Task | Kind | Acceptance | June status |
|---|---|---|---|
| REP-02 sales-report KPI cards (PAID-only) vs. visible row table (ALL orders) — documented mismatch, owner-facing confusion risk | data | (TO BE CREATED `tests/Feature/Admin/SalesReportReconciliationTest.php`) | flagged, never fixed |
| REP-03 sales-report Excel export sums ≠ screen overview (different underlying query) | data | (TO BE CREATED `tests/Feature/Admin/SalesReportExportReconciliationTest.php`) | flagged, never fixed |
| **REP-04 items-report screen count ≠ Excel export count for the same item (two different queries: `itemReport()` date-filtered-by-item-creation vs `list()` unfiltered lazy-count)** | data | (TO BE CREATED `tests/Feature/Admin/ItemsReportExportReconciliationTest.php`) | flagged, never fixed — this is a real "the button does something, but the wrong thing" case matching the owner's stated complaint pattern exactly |
| REP-05 items-report date filter applies to item-creation date, not order date ("items sold between X/Y" actually means "items created between X/Y") | adversarial | (TO BE CREATED `tests/Feature/Admin/ItemsReportControllerTest.php`) | flagged, never fixed |
| REP-09 permission gate covers `index` AND `export`/`pdf` (export routes commonly forgotten) | adversarial | (TO BE CREATED `tests/Feature/Admin/ReportPermissionGateTest.php`) | never run |
| REP-11 Reports sidebar nav (Sales Report / Items Report / Settings›Analytics) reach real pages | nav-button | (TO BE CREATED `tests/e2e/reports-nav.spec.js`) | never run |

Remaining REP-01/06/07/08/10/12/13 per appendix — REP-07 already has an acceptance path (`OrderBranchIsolationTest`), REP-08 too (`SisterServicesTzAwareV2Test`).

---

## §A — Agent army + fan-out (per `ultra-architect-planify` Axis 4/`superpower-gstack`)

| Role | Fires on | Notes |
|---|---|---|
| Architect | every task | contract + anchor re-verification (line numbers may have drifted since June) |
| Security/RED | all `adversarial` + `data` kind tasks | this is the majority of the task list in this GOAL |
| DBA | all `data` kind tasks | reconciliation/persistence correctness |
| Implementer | only when RED confirms a real gap needing a fix or a new test | TDD-red-first, never parallel with another implementer |
| QA-Visual ∥ RED-Visual | all `visual`/`nav-button` tasks | parallel, anti-confirmation-bias |
| **Logic/Dispute agent** (owner's explicit ask — "agents de logique pour disputer notre structure") | every cluster close-out, before declaring a wave converged | reviews the cluster as a whole for business-logic coherence (not just per-task correctness) — e.g. "does Settings' permission model make sense as a system", not just "does each endpoint 200" |

**Dispatch discipline:** 5 read-only specialists = single-message parallel per task-cluster. RED dispute always fires after any fix, before the task is marked done. Reports persist to `reports/test-e2e/admin-nav-breadth-2026-08-13/<wave>/<task-id>.json` (schema per Axis 4) so synthesis survives an interrupt.

---

## §X — Convergence waves (execution order + checkpoints)

| Wave | Scope | Parallelism | Checkpoint |
|---|---|---|---|
| 0 | Drift recheck (§2) | sequential (cheap, ~5 tasks) | spine still green + baseline captured, else insert regression fix before Wave 1 |
| 1 | New CENTRAL nav (§3, 5 tasks) | sequential | 4 screens proven functional + visual clean |
| 2 | Settings cluster (§4, 13+12+6=31 tasks) | 2 sub-waves parallel-safe (zero-coverage sub-pages have disjoint controllers) — Implementer stays sequential | 0 P0/P1 ×2 cycles, every settings sub-page persists+reloads |
| 3 | Users/RBAC (§5, ~15 tasks) | sequential (shared `User`/Spatie tables — write-conflict risk) | 0 P0/P1 ×2, every person-type CRUD + address sub-resource proven |
| 4 | Notifications (§6, ~14 tasks) | sequential (shares no state with Wave 3, could run parallel to it if wall-clock matters — default sequential per skill default) | 0 P0/P1 ×2, NC-09 dead-route finding resolved either way (fix or confirmed-already-fine) |
| 5 | Reports (§7, ~13 tasks) | sequential | 0 P0/P1 ×2, REP-02/03/04 divergences resolved (fixed or pinned-as-documented-contract) |
| 6 | Final convergence | — | full PHPUnit delta vs W0-03 baseline, frozen diff 0, NF525 CHAIN OK, cross-wave RED re-dispute, BRAIN update, tag if owner confirms |

**Interrupt-resume:** at any wave boundary, commit `wip(admin-nav-wave-N): checkpoint`, write `reports/test-e2e/admin-nav-breadth-2026-08-13/INTERRUPT_<wave>.md` (last task, next task, last green commit), update `PROJECT_BRAIN.md §2`. On resume: read the manifest, re-run the last task as a smoke check, continue.

**Convergence-failure protocol:** if any wave hits 3 heal loops on the same finding without resolving, STOP, write `STUCK_<wave>.md`, surface to owner with A) accept-with-doc, B) pivot, C) defer, D) human gate — do not loop a 4th time silently (per skill Axis 3).

---

## §G — Owner gates

| Gate | What | WHO | WHAT (artifact) | WHERE |
|---|---|---|---|---|
| G1 | REP-02/REP-03 sales-report card-vs-row PAID-only-vs-all-orders contract — is the mismatch a bug to fix (filter rows to PAID) or a labeling fix (badge cards "paid-only")? | Owner | one-line decision | this doc §7, then `PROJECT_BRAIN.md §6` |
| G2 | SET-T02 payment-gateway secret exposure — confirm severity/priority before a fix touches auth middleware on a settings route group | Owner (or Claude proceeds if RE-VERIFY confirms it's still open — this is a security fix, default-safe to just fix) | sign-off only if fix scope grows beyond adding `permission:settings` to the route group | commit message |
| G3 | NC-09 dead-route 500 — if RE-VERIFY confirms still broken, default fix = remove the dead route (safer than inventing an `update()` method with unknown intended behavior) | Owner | confirm remove-vs-implement | before Wave 4 close |
| G4 | Wave 2/3/4/5 any confirmed orphan page (nav-reachable-but-non-functional, or functional-but-not-nav-reachable) | Owner | wire-to-nav vs remove vs accept-with-doc | per-wave checkpoint |

---

## §F — Final rule

DONE = Wave 0 confirms the spine still holds (or the regression is healed first) · every task in §3-§7 is GREEN via its named acceptance test (existing, re-run; or TO-BE-CREATED, authored TDD-red-then-green) · every settings/users/notifications/reports nav button proven reachable live via Playwright · every documented June bug (SET-T02, NC-06/07/09, REP-02/03/04/05) is either fixed-and-regression-locked or explicitly owner-deferred via §G with a written reason in `PROJECT_BRAIN.md §6` · two consecutive RED-dispute cycles per wave with zero new P0/P1 · frozen-zone diff = 0 across the whole GOAL · full PHPUnit delta vs the W0-03 baseline is net-positive (more green, zero new red) · NF525 chain CHAIN OK. **Not** "the page opens" — CRUD persists, reloads show the persisted value, exports match screens, permissions actually gate, and nothing about this surface can be called "a button that does nothing" by the end.
