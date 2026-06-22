# GOAL — "Génie" Masterplan (production-perfect V1 + SaaS-prep path)
**Supervisor**: Claude · **Date**: 2026-06-16 · **Branch** `goal/wizard-wysiwyg-builder-2026-06-14` HEAD `45e1a1674` (LOCAL, push=owner gate).
**Mandate**: harden V1 LOCAL Le Cayenne to production-perfect + structure the gradual-SaaS path, **sans rien casser** (frozen §7 intouchable, 0 DB schema, no behavior change to validated flows, scope-minimal, TDD + adversarial RED per wave, commit+checkpoint per wave).
**Provenance**: built from 6 parallel adversarial/specialist audit agents (2026-06-16, run-trail in `reports/test-e2e/genie-masterplan-2026-06-16/audit/`) over the weak-map (`reports/test-e2e/abuse-e2e-2026-06-16/weakmap/`). Every anchor below is grep/Read or live-reproduced confirmed.

---

## §0 — Headline verdict & convergence criteria
**The max-adversarial audit BROKE the "SOLID core" claim: 1 CONFIRMED LIVE NF525 P0 (FISCAL-CPS-01).** The pricing/SSOT lane is genuinely clean (0 authoritative money outside PricingService, verified), the audit-chain holds (UNIQUE(branch_id,prev_hash) + Cache::lock + verify-chain OK), idempotency holds — but a reachable admin path marks orders PAID without a fiscal sequence, escaping every Z. Plus 2 residual P1 fiscal/ops gaps + the deferred weak-map backlog.

**Convergence = two consecutive cycles P0+P1=0 with identical findings sets, frozen-file diff 0, NF525 chain attested (`fiscal:verify-chain --all` CHAIN OK), full Vitest+PHPUnit green.** No "almost". Production-perfect or block.

**Per-task pipeline** = `ultra-audit-profond` (14-step). **Frozen list** = `memory/reference_frozen_zones.md` / CLAUDE.md §7. **NF525** = CLAUDE.md §8.

---

## §1 — Systems map (anchored, verified — SYSTEM_MAP.md)
| System | Maturity | Anchor (verified) | Frozen inside |
|---|---|---|---|
| BORNE kiosk | production-grade | `components/frontend/kiosk/**`, `store/modules/kioskCart.js`, `FrontendOrderService.php` | KioskWizard/App/Upsell .vue |
| CAISSE POS | low-risk core | `components/admin/pos/**`, `PaymentService.php`, `CashDrawerService.php`, `OrderService.php` | pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php, PaymentComponent.vue, PosV5TrancheRow.vue |
| KDS+OSS | hardened | `KitchenDisplaySystemOrderService.php`, `KdsSyncService.php`, `OssSyncService.js` | none |
| WEB+APP | standalone (no V1 wireup) | `/Downloads/web/**`, `mobile/**`, `components/frontend/** (≠kiosk)` | none |
| CENTRAL | broad | `app/Http/Controllers/Admin/** (100 ctrl)`, `DashboardService.php`, `OrderService.php` | none specific |
| §6 SHARED | lock+gate | PricingService(F), Fiscal chain(F), BranchScope(F), IdempotencyKeyMiddleware(F), OrderStateMachine(F), sync bus | per-zone |

---

## §2 — WAVE 0 (🔴 P0 — TOP PRIORITY, OWNER GATE: NF525)
### FISCAL-CPS-01 — `changePaymentStatus` → PAID allocates NO fiscal sequence → escapes the Z
**Anchor (verified)**: `app/Services/OrderService.php:2292-2471` `changePaymentStatus()` — saves `payment_status=PAID` (PaymentStateMachine `app/Domain/Order/PaymentStateMachine.php:10-16` allows UNPAID→PAID / PENDING_COUNTER→PAID), writes ActionLog+AuditLog+event, but contains **no `FiscalSequenceService::next()`** (only reads `$locked->fiscal_sequence_no`). The 3 real alloc sites are `OrderService.php:1117` (create), `PaymentService.php:322` (counter), `FrontendOrderService.php:1188` (kiosk) — this path reaches none.
**Reachability**: admin endpoints `online-order`/`table-order`/`pos-order` change-payment-status, gated only by `permission:online-orders|table-orders|pos-orders` (a non-admin Branch Manager holds these). **Live-reproduced** (HTTP 200, order→PAID, `fiscal_sequence_no=NULL`, `fiscal_alloc_error_at=NULL`).
**Permanence**: `ZReportService.php:340` `->whereNotNull('fiscal_sequence_no')` excludes them from every Z. `RetryFiscalAllocCommand.php:65-69` salvages only `FrontendOrder` rows with `fiscal_alloc_error_at NOT NULL` → admin-flipped `Order` rows (NULL flag) are **cron-unreachable**. **52 such orphans confirmed in the clone** (supervisor-verified count).
**Sibling**: the delivery-COD hole healed 2026-06-14 (same class: PAID without seq). 
**Fix (owner-gated, NF525)**: in `changePaymentStatus`, when transitioning into PAID and `fiscal_sequence_no IS NULL`, allocate via `FiscalSequenceService::next($branchId)` inside the existing lockForUpdate tx (mirror the COD heal); on alloc fail set `fiscal_alloc_error_at` so the retry cron can salvage (and widen the cron to cover `Order` rows, not just `FrontendOrder`). Does NOT modify any frozen file (calls the frozen service, same as COD).
**Acceptance**: `(test TO BE CREATED at tests/Feature/Fiscal/ChangePaymentStatusFiscalAllocTest.php)` — UNPAID→PAID via each of the 3 endpoints allocates a gap-free seq; the order now enters the Z aggregate; alloc-fail flags + retry salvages; a backfill plan for the existing 52 orphans (one-off command, owner-gated). `fiscal:verify-chain --all` CHAIN OK after.
**Owner gate G-FISC-CPS**: NF525 correctness change to the order payment path → owner sign-off + LOCK ceremony (no `--no-verify`).

---

## §3 — WAVE 1 (🟠 P1 — fiscal/ops residuals, NON-frozen, no owner gate)
- **T1.1 lock-acquire-fail degrade** — `AuditLogService.php:103`, `FiscalSequenceService.php:69`, `ZReportService.php:82`: `$lock->block()==false` throws bare RuntimeException, no degrade. **Mock-test only, 0 frozen edit**: `(test TO BE CREATED at tests/Feature/Fiscal/LockAcquireFailureTest.php)` — assert flag+retry, no silent loss.
- **T1.2 alloc-orphan escalation** — `RetryFiscalAllocCommand.php:112-124` always returns SUCCESS; add age-based escalation (calque `MonitorOutboxStaleness.php:94-116`). `(test TO BE CREATED at tests/Feature/Fiscal/AllocOrphanEscalationTest.php)`.
- **T1.3 SubscriberController sync BCC blast** — `SubscriberController.php:57`→`SubscriberService.php:110-118`: `Mail::bcc(all)->send()` in-request, no queue, no exec test. Queue it (`ShouldQueue`) + `(test TO BE CREATED at tests/Feature/Subscriber/SendEmailQueuedTest.php)`.
- **T1.4 BranchStatusChanged cascade order (P2)** — `EventServiceProvider.php:303-312`: `RevokeTokensOnBranchDeactivated` (no try/catch) runs BEFORE outbox-persist → a throw kills the INACTIVE broadcast. Reorder outbox-first + wrap listener; `(test TO BE CREATED at tests/Feature/Branch/BranchStatusCascadeIsolationTest.php)`.
> Note (verify-before-report): the "Z window ~2-min cron dead-zone" (CC-2 / FISCAL-ZGAP-01) was **REFUTED as a NEW defect** by the core-refutation agent — it is the *known, documented, owner-gated, mitigated* `PROPOSAL-Z-LOOP-GAP` (Kernel.php:396-421, Path B deferred V1.0.X); the inflated order count was a test-harness artifact. It gets a TEST (T1.5, business_date-based `aggregate()` + fix the lying ~10s comment) but is NOT a fresh P0/P1.

---

## §4 — WAVE 2 (🟢 A1 jsonError trait — NON-frozen, the #1 systemic debt)
**Scope (verified)**: 404 single-line `catch → 422 $e->getMessage()` across 113 controllers, 97 with no `Log::`.
**Design**: `protected jsonError(\Throwable $e, int $status=422, ?string $ctx=null)` on base `app/Http/Controllers/Controller.php` (zero per-file `use` churn). Generic key `all.message.something_wrong` **already exists** (fr/en/ar/de). **Discriminator (load-bearing)**: pass `getMessage()` through when `getCode()===422 || $e instanceof HttpException || ValidationException` (76 services throw intentional `trans(...,422)` user messages); genericize + `Log::error` only the unexpected. 
**Sub-waves (highest-risk-first, ~20 files)**: W2.0 verify-only (Fiscal/Pos/Obs, 0 leak — sentinel) · W2.1 Order/Payment/Coupon/Loyalty/Transaction/Branch/Token/Auth/Table (74) · W2.2 Frontend (94) · W2.3-2.5 admin CRUD (236). **Per-wave sentinel** `(tests/Feature/Security/JsonErrorTrait/Wave<N>*Test.php)`: mock service `throw RuntimeException('SECRET')` → 422 preserved + `message===generic` + no SECRET + `Log::error` fired; AND `throw Exception(trans,422)` → message passes through. **A1-bis** (follow-up): `Handler.php:133/143` leak the same way (out of controller scope).
**Frozen/NF525**: none touched (Fiscal/Pos-cash controllers are W2.0 verify-only).

---

## §5 — WAVE 3 (🟢 B9 a11y — NON-frozen, shared-component win)
- **T3.1** extract `SmModalCloseComponent.vue` (in `components/admin/components/buttons/`) → replaces **47** copy-pasted close buttons (2 families: `modal-close` 35 + `close-btn` 12). `button.close="Fermer"` exists. All 47 are outside `<form>` → risk-free. `(test TO BE CREATED at tests/js/smModalCloseA11y.spec.js)` (pattern = `smIconEditA11y.spec.js`).
- **T3.2** fix `for="confirm_password"` in the **6 REAL** dangling-label files: `Administrator/Chef/Customer/DeliveryBoy/Employee/WaiterShowComponent.vue` (~:124-130). `(test TO BE CREATED at tests/js/passwordLabelForIdResolutionA11y.spec.js)`.
- **T3.3** non-POS icon buttons (info/map/qty/submit, ~11) + shared `SmTimeSloteDeleteComponent`.
- **T3.4 (visual gate)** POS-adjacent icon buttons (~15) — Playwright capture + mount tests, no `@click` submit reliance.
**Frozen**: none (PaymentComponent has 0 unlabeled closes).

---

## §6 — WAVE 4 (🟢 perf safe — NON-frozen)
- **T4.1 B6** drop the proven-unused `orderItems` eager-load at `OrderService.php:2730` (now-safe, 0 numeric change) + (optional) selectRaw aggregate via `scopeRealizedRevenue` with **cent-equality** `(test TO BE CREATED at tests/Feature/Reports/SalesReportOverviewAggregateEqualityTest.php)` (assertSame formatted strings, decimal-boundary case).
- **T4.2 B7** `DashboardService.php:314` `->get()->count()` → `->count()` (SQL). `(tests/Feature/Dashboard/CustomerStatesCountEqualityTest.php)`.
- **T4.3 B8** settings cache (currently OFF — latent): `Cache::remember('frontend.settings.payload')` in `SettingService.php` + `ForgetFrontendSettingsCache` listener on the existing `SettingsUpdated` event (`EventServiceProvider.php:300`) + 5-min TTL floor for un-evented `->set()` groups; keep `SETTINGS_CACHE_ENABLED=false`. `(tests/Feature/Settings/FrontendSettingsCacheInvalidationTest.php)`.
**Frozen/NF525**: none (read-report/config paths).

---

## §7 — WAVE 5 (🟡 bundles) + WAVE 6 (🔒 GATED)
**W5 (SAFE)**: `npm run production` (the 7MB is a DEV artifact; minify+treeshake already wired `webpack.mix.js:11-19`), confirm `pos-wizard.js` still absent from `mix-manifest.json` (it's outside Mix), tune `webpack.mix.js` vendor `extract([])` (non-frozen). **GATED**: pos-app Vue-dedup (global-extract fragility) + any `admin-pos-v4.blade.php` edit (FROZEN §7).
**W6 (OWNER LOCK)**: 
- **B3 KDS dual-poll dedup** — `KitchenDisplaySystemComponent.vue:1900-1919` (5s full-board) + `:1557` (cached delta) both armed; OSS is single-loop (reference). Fix Option A (don't arm 5s full-board when WS-down; delta sole fallback + 60s drift safety). **GATE G-SYNC-LOCK** (SYNC_CONTRACT §6, 5s kitchen-staleness budget). `(tests/js/sentinels/kdsPollCadenceSentinel.spec.js)`.
- **G-PAYMENT-DISPLAY** — A2 made the frozen PaymentComponent's *displayed* money FR (file untouched, math intact); owner validates.

---

## §8 — WAVE 7 (🌐 SaaS-prep — PLAN-ONLY, gate per step; additive scoping + ops, never weaken isolation/NF525)
- **Step 1 pré-multi-tenant (hard blocker)**: scope the 10 BranchScope-exempt models (`BranchScopeCoverageSentinelTest.php:48-67` — **ZReport + AuditLog first**, then OrderDiscountLog/Message/DiningTableAuditLog/KioskPromo/UpsellRule/ActionLog/DomainEvent/FrontendDiningTable; Branch+Customer stay architectural) · also fix BRANCH-MESSAGE-01 (`MessageService.php:30` trusts client `branch_id`, `authorizeBranchScope` unwired — P2, becomes reachable at tenant #2) · FormRequest 66→chip + lower sentinel · Sanctum TTL 8h→1h.
- **Step 2 pré-multi-instance (ALB)**: UNI-03 cache-guard widen to require redis/memcached (`AppServiceProvider.php:294-296` — `file`/`database` PASS today) · `config/database.php` explicit `mysql.timezone` (KdsSync `:60-95` assumes Paris-local SYSTEM TZ) · soketi + non-sync queue + single-leader scheduler wiring · audit-chain ≥3-way collision.
- **Step 3 polish**: TTL, bundles, perf.

---

## §A — Agent army (fan-out matrix per Axis 4)
Architect/Security/DBA/SRE/Implementer/RED/QA-Visual/RED-Visual. Fiscal-adjacent waves (0,1) fire Architect+Security+DBA+Implementer+RED. Frontend waves (3) fire +UX/A11y+QA-Vis+RED-Vis. 5 read-only specialists = single-message parallel; implementer never parallel-with-implementer; RED dispute always before DONE. Reports persist to `reports/test-e2e/genie-masterplan-2026-06-16/round-<N>/wave-<W>-<role>.json`.

## §G — Owner gates
| Gate | Description | WHO | WHAT | WHERE |
|---|---|---|---|---|
| G-FISC-CPS | Wave 0 NF525 fix (changePaymentStatus alloc) + 52-orphan backfill | Physical owner | LOCK ceremony + sign-off | commit tag + LOCK doc |
| G-SYNC-LOCK | Wave 6 KDS poll-dedup (sync contract §6) | owner | LOCK doc countersign | LOCK §10 |
| G-PAYMENT-DISPLAY | frozen payment screen now FR (A2) | owner | accept/reject | BRAIN §2 |
| G-PUSH | push the branch | owner | explicit go | commit |
| G-SaaS-<step> | each SaaS-prep step | owner | per-step sign-off | per-step LOCK |

## §F — DONE
Waves 1-4 = no-gate, exec immediately (Wave 0 P0 FIRST but owner-gated). 5-6 gated. 7 plan-only. Per-wave: checkpoint (6-point Axis 3) + frozen diff 0 + NF525 attest + BRAIN update. Final: full smoke + cross-surface E2E (Kiosk→KDS→OSS→Caisse) + 2 clean convergence cycles. Production-perfect or block.
