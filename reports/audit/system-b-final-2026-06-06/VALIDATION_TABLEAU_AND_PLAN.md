# System B (POS/Backend) — FINAL VALIDATION TABLEAU + CORRECTION PLAN
**2026-06-06 · Supervisor audit · MISSION 1** · scope = POS/backend + sync + System-A connectivity (the site & app are System A, already validated separately & kept SEPARATE).

> Method: fresh evidence spine (ran the real apparatus this run, split by DB driver) + a 6-dimension read-only audit with **adversarial refute** (refute-by-default to kill hallucinated P0s on mature code) → 9 agents. Every cell is `validated (this-run evidence)` / `weak-point` / `documented boundary`.

---

## 0. VERDICT
**System B is production-grade and V1-ready at the validation ceiling.** **2969 product tests green** (was 2860; **+109 after WP-06 closure** wired 17 previously-orphaned guard sentinels into CI), fiscal chain intact, sync/tenant/order-lifecycle all validated. **2 issues FIXED + verified this session: 1 P1 (loyalty caisse-accrual) and WP-06 (CI-integrity — see below, severity raised).** The 4 remaining red tests are the WP-08 traceability sentinels (P3 test-infra: they `assertFileExists` a plan path in a *sibling* worktree; green from main-repo root). 15 residual weak points are all P2/P3 (hardening / test-integrity / doc-drift / documented boundaries) — none block V1; they form the Mission-2 correction plan. Honesty: hardware TPE, real PSP charges, real-money, MySQL-prod-concurrency are **documented boundaries** (simulated/not-exercised by design), not failures.

---

## 1. EVIDENCE SPINE (fresh, this run — split by driver)
| Apparatus | Driver | Result | Evidence |
|---|---|---|---|
| PHPUnit Feature+Unit (~3005 tests) | sqlite `:memory:` | **2969 passed**, 4 failed*, 1 risky, 2 incomplete, 29 skipped (464s) | `php artisan test` — post-WP-06 (was 2860 passed; +109 = the 17 newly-collected guard sentinels) |
| └ 4 "failures" | — | **P3 test-infra, NOT product**: F001/F006/F009/F013 traceability sentinels `assertFileExists` a plan path that is `base_path`-relative to a *sibling* worktree (`blissful-mclean-c915c2`); green from main-repo root, fail only when run from this worktree. Plan files exist one level up. Unchanged by WP-06 (exactly these 4, no new failures → no in-suite state/order pollution from collection). | log: `tests/Sentinels/F0*SentinelTest` |
| Vitest (frontend) | node | **1900 passed / 0 failed** (3 skipped, 281 files) | `npx vitest run` |
| NF525 fiscal chain | MySQL (live) | **CHAIN OK** (branch 1) — identical before==after the loyalty fix | `php artisan fiscal:verify-chain --all` |
| Env integrity | — | vendor not shadowed, app/ real, DEVDB-GUARD active, `.env.testing`→`foodking_test` (operating DB safe) | — |

\* The 4 are the same class as weak-point WP-08 below; product/fiscal logic in those sentinels passes.

---

## 2. THE TABLEAU — validated capabilities by subsystem
Legend: ✅ validated (this-run evidence) · ⚠️ weak point (see register) · 🔒 documented boundary (by design).

### 2.1 Fiscal / NF525  — 8 validated, 0 P0/P1
| Capability | Status | Evidence |
|---|---|---|
| Audit-log HMAC chain (prev→current) detects tamper/forge; UNIQUE(branch_id,prev_hash) rejects forks even with cache down | ✅ | AuditLogHashChainTest + AuditLogConcurrencyTest:47 |
| Z-report split-payment per-tranche bucketing (30cash+20card → 30/20, Σ==total_ttc to the cent) | ✅ | ZReportSplitPaymentBucketingTest:63,103 |
| Fiscal sequence monotonic, gap-free, per-branch, continues from MAX, withTrashed reuse-proof | ✅ | FiscalSequenceTest (5) + OrderFiscalSequenceSchemaTest:33 |
| z_reports + order_payments append-only (MySQL BEFORE-DELETE SIGNAL 45000 + SQLite parity trigger) | ✅ | ZReportDeleteTriggerMysqlOnlyTest (3) |
| Z close window half-open, PAID+sequenced only, refund-netted, HMAC re-verified on open/close | ✅ | ZReportCloseTest + BoundaryTest + NF525ComplianceE2ETest (1-8) |
| Discount-netted TVA (total_ttc==total_ht+total_tva exactly in signed payload) | ✅ | ZReportDiscountNettingTest + Vat10ZReconciliationTest |
| Kiosk alloc-failure flagged (`fiscal_alloc_error_at`) + everyMinute retry cron, no silent gap | ✅ | FiscalAllocOrphanRetryTest (4) |
| Dual-chain verify CLI (distinct exit codes) | ✅ | FiscalVerifyChainCommandTest |
| MySQL row-lock concurrency (sequence/Z-close) under real contention | ⚠️ WP-01 | lockForUpdate is a sqlite no-op; not exercised |
| Signed-Z total_tva vs order totals reconciliation | ⚠️ WP-02 | no sentinel guards divergence |

### 2.2 Sync / real-time (Outbox → soketi/Pusher → Echo)  — 7 validated, 0 P0/P1
| Capability | Status | Evidence |
|---|---|---|
| Outbox dedupe + exactly-once (idempotency_key + atomic lockForUpdate claim) | ✅ | OutboxConcurrentWorkerDedupeTest, OutboxConcurrentRetryLockTest |
| Best-effort broadcast NEVER fails the HTTP request (degradation invariant) | ✅ | OutboxProductionLikeSimulationTest:117 + OutboxDeliveryTest (503 probes) |
| Channel-auth: kiosk token → own branch via un-spoofable token-NAME (immune to `*`), cross-branch = Admin role only | ✅ | PusherChannelAuthWildcardSentinelTest + KioskTokenAdminBlockSentinelTest |
| Event ordering self-heals (Echo handlers re-fetch authoritative REST state) | ✅ | KitchenDisplaySystemComponent.vue:1925-1953 |
| Retry idempotency under Cache::lock + crash-claimed orphan two-lane rescue | ✅ | OutboxRescueCommand + MonitorOutboxStaleness |
| Worker-down detectability (Log::error + non-zero exit on stale threshold) | ✅ | MonitorOutboxStaleness |
| Hard-crash orphan with attempts≥5 AND last_error=NULL | ⚠️ WP-03 | monitor blind spot (backstopped by polling = no data loss) |
| SYNC_CONTRACT.md §5/§6/§7 stale vs shipped (KDS banner opt-out, OSS 5s wall) | ⚠️ WP-04/05 | doc drift only |

### 2.3 Multi-tenant isolation + auth  — 9 validated, 0 P0/P1
| Capability | Status | Evidence |
|---|---|---|
| BranchScope staff isolation holds across orWhere boolean-precedence; hides branch_id=0 from staff; exact-match (1 ≠ 10/100) | ✅ | BranchScope sentinels |
| 20 branch_id models declare BranchScope (drift-locked at baseline) | ✅ | BranchScopeCoverageSentinelTest |
| Kiosk tokens can't subscribe to arbitrary WS channels nor hit admin API (defense-in-depth) | ✅ | channel + admin-block sentinels |
| Token sprawl closed (prior tokens revoked on relogin; kiosk:order name-scoped) | ✅ | sentinel |
| FormRequest return-true drift locked at baseline (no creep) | ✅ | FormRequestAuthzDriftSentinel |
| Idempotency dual-layer ((branch,user,sha256) + DB UNIQUE) cross-user/branch leak-tested | ✅ | idempotency sentinels |
| Loyalty-redeem IDOR hardened (bare kiosk:order insufficient) | ✅ | redeem tests |
| **Authored guard sentinels never collected by CI (filename suffix ≠ `Test.php`)** | ✅ **WP-06 FIXED this session (severity RAISED)** | Scope was understated: **17** orphans tree-wide, not 7 (7 Security + 10 across Fiscal/Migration/Refund/Stripe/Orders/Database/Deploy), all extending TestCase with real assertions, silently uncollected. **Severity raised: this wasn't just "CI blind to passing tests" — 2 of the 17 (ZOpen/ZClose safety-net cron) had ALREADY silently DRIFTED FALSE** (asserted the pre-2026-05-25 23:55/00:05 schedule; Kernel was deliberately tightened to 23:59/00:01 to shrink the NF525 cross-midnight orphan window 10min→~2min). Fix: phpunit `<directory suffix="Sentinel.php"/"Parity.php">` matcher (root-cause: future sentinels can't re-orphan) + corrected the 2 stale Z-pair expectations to the shipped Kernel. Full suite re-run: **2969 passed (+109), 0 new failures**. Cross-checked: **no `✅` cell in this tableau rested solely on an orphan**, none cite the Z-pair. |
| /check + /balance leak name+points+loyalty_code by arbitrary phone (auth'd, not ownership-scoped) | ⚠️ WP-07 | LoyaltyController:60-104 |

### 2.4 Loyalty (owner's V1 phone-keyed model)  — 6 validated, 1 P1 (FIXED)
| Capability | Status | Evidence |
|---|---|---|
| Phone-keyed enrollment (name optional, email/password optional) | ✅ | LoyaltyController::register:114-177 |
| NOT printed on the receipt (owner intent) | ✅ | pos-wizard.js: 0 loyalty refs |
| Redeem owner-gated, atomic, idempotent-ledgered, IDOR-hardened | ✅ | LoyaltyController:258-371 |
| Redeem (discount) intentionally OFF in V1 while manual discounts off (NF525 safety, not a bug) | 🔒 | LoyaltyController:267-272 |
| Phone-tolerant lookup (code OR phone); POS auto-fetches balance by phone | ✅ | check:75-80 + PosComponent:4097 |
| Refund-on-cancel + clawback-on-refund idempotent (no double-credit/debit) | ✅ | LoyaltyService:21-199 |
| **Caisse accrual for POS-created customers (status=5)** | ✅ **FIXED this session (was P1)** | `check()/balance()` gated mint on `status==1`; POS persists ACTIVE=5 → 404 → no mint → dead accrual. Fixed → `isCustomerActive()` (1 OR 5). Test `PosCustomerActiveStatus5LoyaltyTest` RED→GREEN (mint + earn-row). Commit `d2b244df5`. |

### 2.5 Order lifecycle / state machine / encaissement  — 9 validated, 0 P0/P1
| Capability | Status | Evidence |
|---|---|---|
| State-machine SSOT, no rule drift; illegal transitions unreachable; terminal states reject moves | ✅ | OrderStateMachine + ValidStatus sentinels |
| Concurrent-transition race safety (lock+read+guard in apply/changeStatus/confirmCounterPayment) | ✅ | tests/Feature/Pos |
| Drawer not over-counted by change (split CASH writes net amount, not tendered) | ✅ | cash_movement tests |
| Z per-method bucketing exact for split tenders | ✅ | (cross-ref 2.1) |
| Refund counter-entry can't double-book (sealed parent + UNIQUE) | ✅ | RefundWithCounterEntry tests |
| Loyalty clawback/refund idempotent + failure-isolated | ✅ | LoyaltyService |
| Post-Z immutability on destroy (409) + RETURNED | ✅ | tests |
| cancelCounterPayment voids un-collected deferred order (no sequence allocated) | ✅ | tests |
| OrderDetailsResource emits NEGATIVE cash_back_amount for non-cash counter pay | ⚠️ WP-09 | OrderDetailsResource:106 |
| No upper bound on cash `received` (fat-finger inflates change display) | ⚠️ WP-10 | routes/api.php:844 |
| Counter-collect CASH drawer-enforcement softer than strict POS path | 🔒 WP-11 | documented boundary (single-operator V1) |

### 2.6 Connectivity / API surface / System-A↔B intersection  — 5 validated, 0 P0/P1
| Capability | Status | Evidence |
|---|---|---|
| POST /api/frontend/order = live intake (auth:sanctum, idempotent, throttled, item_variations[]/item_extras[]) | ✅ | OrderController + FrontendOrderService:132 |
| Customer realtime order-ready channel genuinely `(to be created)` | ✅ | matches POS_INTEGRATION_GUIDE |
| Menu endpoint genuinely kiosk-only (tokenCan + KioskMachine row) | ✅ | MenuController::kiosk |
| Channel-auth wildcard + Guest-Echo bypass closed (token-NAME check) | ✅ | channels.php |
| No unauthenticated order-creation / no missing-controller 500 on frontend surface | ✅ | route audit |
| Channel-auth customer-denial validated only by static source-scan (no behavioral test) | ⚠️ WP-12 | add Broadcast::channel closure test |
| Contract drift: System-A README `/api/v1/frontend/...` vs live `/api/frontend/...` | ⚠️ WP-13 | owner-gate G5 (in POS guide) |
| Customer intake rides `*` wildcard via kiosk:order ability | 🔒 WP-14 | documented; tighten at wireup (G5) |

---

## 3. WEAK-POINT REGISTER (17 found · 2 FIXED this session [1 P1 loyalty + WP-06 CI-integrity] · 0 open P0/P1)
| # | Sev | Dimension | Title | Disposition |
|---|---|---|---|---|
| — | P1 | Loyalty | Caisse accrual dead for status=5 customers | ✅ **FIXED** `d2b244df5` |
| WP-06 | P2 → **FIXED** | Tenant/sec | **17 guard sentinels tree-wide never collected by CI** (suffix≠Test.php); **2 had already silently drifted false** (ZOpen/ZClose cron) | ✅ **FIXED** `phpunit.xml` suffix matcher + Z-pair expectations corrected to shipped Kernel; full suite **+109 → 2969 passed, 0 new fail** |
| WP-07 | P2 | Tenant/sec | /check + /balance PII-by-phone (auth'd, not owner-scoped) | Plan #2 (apply redeem() discriminator) |
| WP-01 | P2 | Fiscal | MySQL row-lock concurrency not exercised | Plan #3 (MysqlOnly concurrency test) |
| WP-02 | P2 | Fiscal | No signed-Z TVA reconciliation sentinel | Plan #4 |
| WP-09 | P2 | Order | Negative cash_back_amount non-cash counter | Plan #5 (clamp max(0,…)) |
| WP-12 | P2 | Connect | Channel-auth denial only static-scan tested | Plan #6 (behavioral auth-closure test) |
| WP-13 | P2 | Connect | `/v1/` contract drift System-A README | Owner gate G5 |
| WP-03 | P2 | Sync | Crash-orphan monitor blind spot (NULL last_error) | Plan #7 (widen predicate) |
| WP-08 | P3 | Test-infra | 4 traceability sentinels base_path-relative to sibling worktree | Plan #8 (worktree-robust path) |
| WP-04/05 | P3 | Sync | SYNC_CONTRACT.md §5/§6/§7 doc drift | Plan #9 (doc update) |
| WP-10 | P3 | Order | No upper bound on cash received | Plan #10 (route rule ceiling) |
| WP-11 | 🔒 | Order | Counter-collect CASH drawer softer | Boundary (single-operator V1) |
| WP-14 | 🔒 | Connect | `*` wildcard token intake | Boundary (tighten at wireup G5) |
| WP-15/16 | P3 | Tenant | Dead `App\Models\Customer` exemption in coverage sentinel | Plan #11 |

---

## 4. DOCUMENTED BOUNDARIES (honesty — "100%" = in-scope-testable, NOT these)
- **Hardware TPE** simulated (`POS_SIMULATION_HARDWARE` dev-only; boot-guard forbids in prod).
- **Real PSP charge / real-money** flows not exercised (SumUp manual ref pattern; encaissement card/TR = ref-only).
- **MySQL-prod concurrency** (row locks, InnoDB) not exercised on sqlite test runner (WP-01).
- **Single branch_id=1, file cache driver** = V1 LOCAL Le Cayenne envelope (UNI-03 widens cache list at cloud cutover).
- These are owner-accepted V1 boundaries, surfaced not green-washed.

---

## 5. CORRECTION PLAN (the "abusive testing" plan — executed in MISSION 2 by the agent army)
Prioritized; each = `decompose-planify` → implement → `test-e2e`/PHPUnit → adversarial verify → re-run. **No frozen-zone touches** unless owner-gated (LOCK).
1. ✅ **WP-06 (CI integrity, TOP) — DONE this session.** Chose the root-cause path: phpunit `<directory suffix="Sentinel.php"/"Parity.php">` matcher (vs renaming 17 files) so future sentinels can't silently re-orphan. Enumerated the complete orphan set (17, verified no non-test file matches the suffix), found+corrected 2 that had drifted false (ZOpen/ZClose cron schedule), re-ran the full suite: **2969 passed (+109), exactly the 4 pre-existing WP-08 infra fails, 0 new**. Green bar is now honest. *Mission-2 follow-up (optional): a meta-sentinel asserting "no uncollected `extends TestCase` file under tests/" to lint the invariant itself.*
2. **WP-07:** apply the redeem() kiosk-machine-or-owner discriminator to check()/balance() to stop arbitrary-phone PII leak.
3. **WP-01 + WP-02:** MysqlOnly concurrency test for sequence/Z-close + a signed-Z TVA reconciliation sentinel.
4. **WP-09 + WP-10:** clamp cash_back_amount ≥ 0; add a cash-received ceiling rule.
5. **WP-12:** behavioral Broadcast::channel auth-closure test (not just static scan).
6. **WP-03:** widen crash-orphan monitor predicate.
7. **WP-08 + WP-04/05 + WP-15/16:** worktree-robust traceability paths; SYNC_CONTRACT.md doc refresh; remove dead Customer exemption.
8. **Mission-2 NEW coverage (owner brief):** KDS per-station routing + bump + delay alerts E2E; dashboards real-time stats E2E; **offline-mode queue + auto-resync** (the caisse-never-stops invariant) E2E; touch-hardware ergonomics visual pass (large targets, kitchen-distance contrast); roles/PIN/open-close/Z full E2E.

---

## 6. OWNER GATES (decisions, not guessed)
- **G1** loyalty points-per-€ ratio: backend default `loyalty_points_per_euro=10`; System-A apps compute 1/€. Identification (phone) is now solved; the *value* is your call. (Untouched by this session's fix.)
- **G5** System-A↔B wireup activation + `/v1/` path decision (POS_INTEGRATION_GUIDE).
- **G6** "Reçu fiscal NF525" on the customer app surface (System A) — keep/remove.

## 7. ARTIFACTS
Audit raw: workflow `wf_3f7b3282-91e`. Loyalty fix: commit `d2b244df5` + `tests/Feature/Loyalty/PosCustomerActiveStatus5LoyaltyTest.php`. Mission-2 brief: `plans/GOAL_POS_SYSTEM_ORCHESTRATION_2026-06-06.md`.
