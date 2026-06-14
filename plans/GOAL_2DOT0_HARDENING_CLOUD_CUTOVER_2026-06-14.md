# GOAL 2.0 — HARDENING + CLOUD-CUTOVER

> Branch `heal/massive-2dot0-2026-06-14` @ HEAD `a9c7aed0b`. Master plan synthesizing 6 domain sub-plans (NF525 exhaustivity, reactive class-hardening, security/cloud-prep, design/UX, OVH cutover, owner-decisions). All file:line refs verified on HEAD. The 23 campaign heals are DONE — referenced as `[done]` only, never re-planned.

---

## 1. North Star

Take the integration branch from "all known forward leaks closed, 23 heals shipped" to "provably fiscally-exhaustive, config:cache-safe, class-complete on the outbox cascade, and cleanly live on OVH" — by closing the *remaining* gaps in strict causal order: forward NF525 leaks and the env→config migration first (non-frozen, no gate), then the single frozen `ZReportService` late-salvage block and the legacy backfill (owner-LOCK + data-mutation gates), then the OVH cutover that *consumes* those fixes (config:cache safety, fiscal smoke gates, controlled frozen-gate execution), and finally the design/UX polish that can ship anytime. Every fiscal-mutating or frozen step is preceded by a verified DB backup, an owner sign-off, and a `verify-chain` + `verify-z-membership` proof; nothing is pushed without the owner; the lane never leaves `heal/massive-2dot0-2026-06-14`.

---

## 2. Phased Breakdown

Severity `P0–P3` · Effort `S/M/L` · **FROZEN** = §7 file (needs LOCK) · **GATE** = owner sign-off · all file:line on HEAD `a9c7aed0b`.

### PHASE 0 — Owner decisions & gate sign-offs (BLOCKS the gated work; non-blocking items proceed in parallel)

This phase is **decisions, not code**. Its outputs unblock Phases 2 & 4. Present the package, get recorded decisions in `PROJECT_BRAIN.md §6 DECISIONS LOG`, then execute.

| ID | Decision | Severity | Recommended option | Unblocks |
|----|----------|----------|--------------------|----------|
| **OD-1** | G-DELIV-CASH: silent-skip vs auto-open driver session at float 0 on COD doorstep collection | P2 | **Option B — record-anyway WITHOUT auto-open**; upgrade the `Log::error` drift into a visible "unattributed COD collection" ops counter. Auto-open writes a human-attributed control action no human performed into the immutable audit chain. If owner insists on A, ship behind OFF-by-default `config('cash.delivery.auto_open_on_collect')` + `auto_opened_on_collect=true` audit payload. | P1-DELIV |
| **OD-2** | G-DELIV-REFUND: route compensating cash-OUT to driver session vs POS drawer on COD return | P2 | Route OUT to the **driver session ONLY when that driver has an OPEN session AND the order was driver-collected**; else fall back to POS-drawer OUT (current). Never force a movement onto a closed/reconciled session (refused by `recordMovement` I4). Tie no-session fallback to the OD-1 choice. | P1-DELIV |
| **OD-3** | FISC-EXH-01 LOCK: add a late-salvage positive block to FROZEN `ZReportService` — reverses the 2026-05-29 detect-only decision | P1 | **APPROVE the LOCK** + ship with the new `fiscal_seq_allocated_at` column; pair with cron-timing so most allocations land same-day, salvage is the cross-midnight safety net. Alternative if declined: tighten cron + block Z-close on pending allocations (no frozen edit, can't fix already-late rows). | P2 (NF-1), P4 (DEP-3) |
| **OD-4** | DELIV-NF525-01: retroactive fiscal-seq backfill of legacy `PAID+seq=NULL+no-flag` rows | P2 | **Run read-only census FIRST** (likely 0 on single-box V1), patch the leak source, then supervised one-shot backfill **only after OD-3 ships**. Do NOT widen the standing cron predicate. | P2 (NF-3), P4 (DEP-4) |

**Frozen-LOCK docs to draft (via `lock-plan` skill, owner-signed before any edit):** `LOCK_FISC-EXH-01.md` (scope = `ZReportService::aggregate()` late-salvage block only) and, deferred/separate, `LOCK_G-WCAG-BRAND.md` (brand hex / frozen kiosk text) and the `AuditLogService:273` per-branch-secret LOCK (deferred, V1 single-branch safe).

**Phase 0 DoD:** OD-1…OD-4 each have a recorded decision in `PROJECT_BRAIN §6`; `LOCK_FISC-EXH-01.md` drafted + owner-signed (or OD-3 declined → alternative path recorded); OD-4 read-only census number is in the owner's hands.

---

### PHASE 1 — Safe non-frozen heals (NO gate, land first, fully parallel)

These close **forward** leaks and install the detector *before* any fiscal mutation. Four independent workstreams.

#### 1A — NF525 forward-leak closure + detector wiring

| ID | Item | Sev/Eff | File:line | Acceptance |
|----|------|---------|-----------|------------|
| **NF-2** | Kiosk `finalizePaidKioskOrder` false-return → flag-or-allocate. Before `return false` for non-(KIOSK\|TAKEAWAY)/non-(CARD\|TR) rows, if realized-paid + `seq IS NULL` + non-terminal, set `fiscal_alloc_error_at=now()` via the same outside-tx raw `DB::table` update as the `catch` block (so generic salvage picks it up). Keep early-return for non-realized rows. **GATE** (§7-adjacent, multiple prior LOCKed heals — scope-minimal guard only, flag owner). | P2/S | `app/Services/FrontendOrderService.php:1287-1288` (early return) → mirror `catch` flag write; predicate `RetryFiscalAllocCommand.php:66-68` | New `FinalizePaidUnflaggedPathTest`: a non-KIOSK realized-paid seq=NULL row gets `fiscal_alloc_error_at` set → retry-cron now matches it. Early-return unchanged for UNPAID/terminal. |
| **NF-4** | Wire the **existing** read-only `fiscal:verify-z-membership` into CI gate + deploy smoke + confirm the daily 06:05 cron pager. **SCOPE REFUTATION: detector already exists + is scheduled + has a sentinel** — this is wiring, not build. | P2/S | `.github/workflows/phpunit.yml` (post-migrate step, non-zero exit fails, benign allowlist for post-Z status flips); `deploy-production.yml:~69` (NF525 smoke); `Kernel.php:91-103` (confirm 06:05 + `onFailure` pager); strengthen `tests/Feature/Sentinels/FiscalZMembershipScheduledSentinelTest.php` to assert `onFailure` attached | CI fails on a seeded cross-Z orphan; passes on empty/clean DB; sentinel asserts schedule + failure-handler. |

#### 1B — Reactive class-completion (outbox cascade guard)

> **GROUNDED CORRECTION (verified on this branch): there are 13, NOT 14, `Persist*ToOutbox` listeners — `PersistLoyaltyBalanceChangedToOutbox` does NOT exist here (stale cross-branch index). 3 already guarded → 10 remain.** The sentinel's hardcoded list is **13**.

| ID | Item | Sev/Eff | File:line | Acceptance |
|----|------|---------|-----------|------------|
| **RCH-01** | Guard the **3 with REAL downstream-skip exposure**: `PersistItemExtraAvailabilityChangedToOutbox:23`, `PersistItemVariationAvailabilityChangedToOutbox:22`, `PersistCatalogChangedToOutbox:23`. Mechanical `handle()`→`private project()` verbatim body-move + `runOutboxPersistenceGuarded(self::class, fn()=>$this->project($event))`. A throw here today skips `InvalidateKioskMenuCacheOnCatalogChange` (stale kiosk 86, 60s TTL) / `NotifyStockLowOnStockLevelChanged` (lost low-stock alert). | P1/S | listeners above; registration proof `EventServiceProvider.php:229-250, 288-292` | Each body byte-identical to original; existing outbox/sync feature tests + Vitest green. |
| **RCH-02** | Guard the remaining **7** (uniformity, no current victim): `PersistItemAvailabilityChangedToOutbox:17`, `PersistBranchStatusChangedToOutbox:40` (wrap whole body incl. forensic `Log::channel('security')` + early no-op guard + `DB::afterCommit`), `PersistCouponChangedToOutbox:23`, `PersistSettingsUpdatedToOutbox:27`, `PersistOrderPaymentStatusChangedToOutbox:23`, `PersistOrderTableChangedToOutbox:29`, `PersistKdsOrderRecalledToOutbox:33`. | P2/M | listeners above; `EventServiceProvider.php:154,169,172,195,228,237-238,260,309,315,324` | All 13 route through the guard; full PHPUnit listener/event suite green. |
| **RCH-03** | **Sentinel** `tests/Feature/Sentinels/OutboxPersistenceGuardSentinelTest.php`: hardcoded 13-file list (ratchet — a new `Persist*ToOutbox` forces a deliberate update); per-file assert `use GuardsOutboxPersistence` + `handle()` calls `runOutboxPersistenceGuarded(self::class` + persistence in `private project()`; reflection `class_uses_recursive()` + `project` `ReflectionMethod`. Plus ONE **behavioral** test: stub `DomainEvent::firstOrCreate` to throw for `ItemAvailabilityChanged`, dispatch, assert no propagation AND downstream `InvalidateKioskMenuCache…` still ran. Write LAST (writing earlier red-bars mid-sweep). | P1/M | NEW file; convention from `FormRequestAuthzDriftSentinelTest` + `BranchScopeCoverageSentinelTest` | Sentinel GREEN; proven to FAIL when the guard is removed from any one listener or an unguarded `Persist*ToOutbox` is added. |

#### 1C — Verify-before-heal grounding (refute false findings, prove reachability)

| ID | Item | Sev/Eff | File:line | Acceptance |
|----|------|---------|-----------|------------|
| **RCH-04** | **REFUTED — no code change.** `CleanupStalePendingKioskOrders` filters `where('source_surface','kiosk')` (line **59**) which MATCHES creation tagging; the prompt's `FrontendOrderService:547` is a stale line ref (now an IDOR address comment). Record REFUTED with proof; optional 1 regression test locking the `source_surface` contract. | P3/S | `app/Jobs/CleanupStalePendingKioskOrders.php:59-60` | Refutation recorded in convergence report w/ line-59 proof; optional contract test green. |
| **RCH-05** | **Stock double-credit** — verify-before-heal. (A) **Reachability:** `OrderStateMachine.php:33-90` makes CANCELED escapable only by `hasRole('Admin')`; `PaymentService::cashBack:100-188` has NO status precondition → cancel-then-refund CAN fire both `OrderCanceled`('order_canceled') + `RefundCreated`('refund'). Build a deterministic test driving both on one order. (B) Today's safety is **transitive/fragile**: `movementKey()` includes `$reason` (`StockService.php:327`) so the two reasons don't collapse; double-credit is prevented ONLY because `AvailabilityService:755` increments `order_items.released_qty` first. FIX (prefer ii): make `StockService` self-participate in the `released_qty` ledger under its existing `lockForUpdate` so its guard no longer depends on Availability running first; OR drop `$reason` from `movementKey` for release reasons. **If empirically unreachable in V1 → downgrade to defensive sentinel + comment (no behavior change).** | P2/M | `StockService.php:327, 381, 410`; `AvailabilityService.php:755`; `EventServiceProvider.php:185-187, 201-203`; `PaymentService.php:182-187` | Tests: full-cancel→full-refund net = 1×; partial-refund→cancel-remainder net = exact order qty; Availability-throws-on-first-event → 2nd still no double-credit. Release idempotent independent of `$reason`. |

#### 1D — Security residual + env→config scaffolding prerequisites

> The env→config swap is the **headline cloud-prep**; it MUST land as ONE atomic PR with its validation harness (it's the only proof the NF525 ticket money render survives `config:cache`). Items 1D-env are the Phase-1 portion; the **OVH execution** is Phase 4.

| ID | Item | Sev/Eff | File:line | Acceptance |
|----|------|---------|-----------|------------|
| **UNI-03-A** | Create `config/format.php` mapping `CURRENCY_SYMBOL/CURRENCY/CURRENCY_DECIMAL_POINT/CURRENCY_POSITION/DATE_FORMAT/TIME_FORMAT`. Defaults **bit-for-bit** match current inline `env()` defaults. Reuse existing `config/app.php:97` `app.demo_mode` for DEMO (do NOT duplicate). `env()` inside a config file is the only correct place to read env. | P2/S | NEW `config/format.php`; mirrors `AppLibrary.php:24,32,40,48,56,289,298,299,308,313,427,455` + `OrderItemResource.php:52` | Additive scaffolding; zero behavior change until B/C swap call sites. |
| **UNI-03-B** | Swap `AppLibrary` 6 currency + 6 date/time `env()`→`config('format.*')`. Keep the `NumberFormatter fr_FR` primary branch untouched (only the ext-intl-absent fallback + decimal reads change). | P2/S | `AppLibrary.php:24,32,40,48,56,289,298,299,308,313,427,455` | Cached==uncached output proven by UNI-03-E. |
| **UNI-03-C** | Swap remaining request-time reads: `OrderItemResource:52` `env('CURRENCY')`→`config('format.currency')`; 6 `env('DEMO')`→`config('app.demo_mode')` (`SettingResource:95`, `SignupController:62`, `ItemController:137`, `SiteController:34`, `LanguageService:112`, `OtpManagerService:76` — fixes the latent `env DEMO===false is truthy` bug). | P2/S | files above | All request-time `env()` outside `config/` gone except the documented ledger. |
| **UNI-03-D** | **Exclusion ledger** (written, not migrated): `AuditLogService:273` (FROZEN, dynamic per-branch key → separate owner LOCK, V1 single-branch safe), `AppServiceProvider:331/441/464` (boot-phase, env available), `Nexmo:43`/`InstallerController:29,135` (pre-config-cache, cosmetic), `WizardPerItemDemo:30` (E2E-header-gated). Output = a provably-complete ledger so the sweep has no silent miss. | P3/S | files above | Written ledger; consumed by UNI-03-E sentinel baseline. |
| **UNI-03-E** | (a) **Byte-equivalence harness**: render `OrderItemResource` money + `AppLibrary::currencyAmountFormat`/date/time with config cached vs not — assert byte-identical (the real NF525-ticket no-regression proof). (b) **Drift sentinel** `NoRequestTimeEnvSentinelTest`: grep `app/` (excluding `config/`, `app/Console/Commands`, `AppServiceProvider` boot allowlist) for `env(`, baseline = the UNI-03-D ledger. Mirrors `FormRequestAuthzDriftSentinel`. **Same PR as A–D.** | P2/M | NEW `tests/Feature/Sentinels/NoRequestTimeEnvSentinelTest.php` | `config:cache && config:clear` round-trip leaves a fixture order's NF525 ticket money strings unchanged; sentinel locks baseline. |
| **SEC-APIKEY-01** | `ApiKeyMiddleware:24`: replace `===` with `hash_equals((string)$valid, (string)$header)`; fail-closed if `$valid` null/empty (return 400 before compare). | P2/S | `app/Http/Middleware/ApiKeyMiddleware.php:24` | Unit: empty configured key + empty header → 400; valid → pass; wrong → 400. |
| **SEC-LOGIN-TIMING-01** | **Measure-first.** `LoginController:68` `Auth::attempt` skips `Hash::check` on unknown email → ~10× faster, leaks existence. On failed attempt with no user, burn equivalent CPU via `Hash::check($pw, '<static-dummy-bcrypt>')`. Route already has `throttle:login-lockout` (`routes/api.php:161`) — **if measured delta is sub-threshold under throttle, downgrade to documented-accepted (NO-OP-avoidance).** | P2/M | `LoginController.php:68` | Timing histogram unknown-email vs known-email-bad-pass within noise, OR documented-accepted with throttle rationale. |
| **SEC-AUTHZ-RATCHET-01** | **CORRECTION: count is ALREADY 66 = baseline 66 (zero slack).** Ratchet REQUIRES work: refactor ≥1 security-sensitive `return-true` FormRequest *with a real authz gap* (not route-middleware-covered) to `$this->user()?->can('<perm>')`, add regression test, THEN lower `RETURN_TRUE_BASELINE` 66→new count. Per wave −1…−3. Never lower the constant ahead of the count. | P2/L | `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php:65` | Per wave: refactored FormRequest has passing authz regression test AND baseline == re-run exact count. |

**Phase 1 DoD:** NF-2 + NF-4 green (forward leak flagged, detector wired into CI/deploy/cron); all 13 `Persist*ToOutbox` guarded + `OutboxPersistenceGuardSentinelTest` green and proven-to-fail-on-removal + behavioral cascade test green; RCH-04 refuted (doc), RCH-05 reachability proven + release reason-independent-idempotent (or documented-unreachable); env→config PR atomic with UNI-03-E byte-equivalence + drift sentinel green; `ApiKeyMiddleware` constant-time; login-timing equalized-or-documented; ≥1 FormRequest ratcheted. Full PHPUnit + Vitest green vs baseline **2513/0**; `git diff --stat` on §7 files = **0**; `fiscal:verify-chain --all` OK. Checkpoint-commit per item, explicit paths (never `git add -A`).

---

### PHASE 2 — Frozen / NF525-gated (ONLY after Phase 0 sign-off + Phase 1 green)

Strict internal order: **non-frozen migration prerequisite → frozen LOCK edit → owner data-mutation backfill.**

| ID | Item | Sev/Eff | Frozen/Gate | File:line | Acceptance |
|----|------|---------|-------------|-----------|------------|
| **NF-1-prereq** | **NON-FROZEN migration (lands BEFORE the frozen edit):** add nullable `orders.fiscal_seq_allocated_at`; stamp it at EVERY seq-write site — `FrontendOrderService:1351` (happy path), `RetryFiscalAllocCommand:124-130` (generic salvage), the DV-T1/CPS-01 write points `[done]`. Backfill existing `seq!=NULL` rows with `COALESCE(fiscal_seq_allocated_at, updated_at)`. **Enumerate EVERY `FiscalSequenceService::next()` caller before this lands** (late-salvage misses any unstamped site). | P1/M | non-frozen | migration + `FrontendOrderService.php:1351`, `RetryFiscalAllocCommand.php:124-130` | Column present + stamped at all enumerated call-sites; backfill correct; suite green. **MUST be green before NF-1.** |
| **NF-1** | **FROZEN one-block edit** in `ZReportService::aggregate()`: add a 4th `lateSalvage` sub-query = rows `seq NOT NULL` AND `payment_status != UNPAID` AND `status NOT IN terminal` AND `created_at <= $from` (prior window) AND `fiscal_seq_allocated_at ∈ ($from,$to]` (allocated this window). Apply `+1` into totals / `byMethod` / `taxBreakdownForOrders` / `order_count`. **Three disjoint keys** (window=`created_at`, post-Z=`updated_at`, late-salvage=`fiscal_seq_allocated_at`) prove no double-count. Forward-only (`verifyChain` reads STORED totals → historical Z immutable). | P1/L | **FROZEN §7 — owner LOCK `LOCK_FISC-EXH-01.md`** (OD-3); frozen-diff MUST show ONLY this block | `app/Services/Fiscal/ZReportService.php:337-347` (window) symmetric to `:404-420` (post-Z negative block); trigger `RetryFiscalAllocCommand.php:122-131`, `Kernel.php:266-270` vs `401-454` | New `ZReportLateSalvageTest` (cross-midnight delivery seq lands in next signed Z); **no-double-count** regression; `verifyChain()` GREEN all branches; full `tests/Feature/Fiscal` green; frozen-diff = only this block. |
| **NF-3** | **Legacy backfill command** (owner data-mutation gate, OD-4) — NEW `app/Console/Commands/BackfillLegacyFiscalAllocCommand.php`, separate from the everyMinute cron so it NEVER auto-runs. Predicate: `payment_status=PAID AND seq IS NULL AND status NOT IN [CANCELED,REJECTED,RETURNED] AND deleted_at IS NULL AND parent_order_id IS NULL AND created_at < last-closed-Z.closed_at AND total>0`. `--dry-run` DEFAULT TRUE (prints candidate table + per-branch counts + TTC, writes nothing); `--apply` needs `--confirm` token + `Log::channel('fiscal')` per order + `AuditLogService` append-only entry; allocates via `FiscalSequenceService::next()` per-row tx, sets `fiscal_seq_allocated_at=now()` so NF-1 sweeps it into the current open Z. Never touches a row inside an already-OPEN Z. | P2/M | **owner GATE — `--apply` after dry-run review; gated on NF-1 shipped** | `RetryFiscalAllocCommand.php:66-68` (predicate legacy rows escape) → NEW command | `BackfillLegacyFiscalAllocTest` green; post-apply on clone: `verify-z-membership` 0 orphans + `verify-chain` OK. Default dry-run makes accidental mutation impossible. |

**Ordering inside Phase 2:** `NF-1-prereq` (column + stamping, green) → `NF-1` (frozen LOCK edit, green) → `NF-3` (backfill, gated on NF-1). **NF-3 before NF-1 would manufacture cross-Z orphans** — hard dependency.

**Phase 2 DoD:** every realized PAID sale (kiosk card/TR, kiosk cash-at-counter, POS inline, COD delivery, non-COD delivery) provably receives a gap-free `fiscal_sequence_no` AND lands in exactly one signed Z — including late-allocated (NF-1) and legacy (NF-3) rows; `verify-z-membership` = 0 orphans on a representative clone; `verifyChain()` GREEN all branches; new fiscal tests green; **frozen-diff = ONLY the NF-1 late-salvage block behind a signed LOCK**; owner gates for NF-1 (LOCK) + NF-3 (`--apply`) explicitly obtained; nothing pushed.

---

### PHASE 3 — Cloud-prep finalization (env→config consumed; precedes OVH)

This phase has **no new code** beyond Phase 1's env→config — it is the **gate that certifies Phase 1's UNI-03 work is `config:cache`-safe** before Phase 4 runs `config:cache` on prod. (Listed as a distinct phase to make the ordering dependency explicit and auditable.)

| ID | Item | Sev/Eff | File:line | Acceptance |
|----|------|---------|-----------|------------|
| **CP-1** | Confirm `grep app/` for request-time `env(` (excluding `config/`, `app/Console/Commands`, `AppServiceProvider` boot allowlist) returns ONLY the UNI-03-D ledger. | P1/S | sweep over `app/` | Clean grep == ledger; `NoRequestTimeEnvSentinel` green. |
| **CP-2** | `config:cache && config:clear` round-trip on a clone leaves a known fixture order's NF525 ticket money strings + dates unchanged (the live failure DEP-1 guards). | P1/S | UNI-03-E harness | Byte-identical money/date strings cached vs uncached. |
| **CP-3** | Confirm OVH `.env` has **no** `FISCAL_AUDIT_SECRET_BRANCH_1` (single-branch V1 uses base secret → `AuditLogService:273` dynamic env never hit → config:cache safe without the deferred AuditLogService LOCK). | P1/S | `AuditLogService.php:273`; OVH `.env` | Absence confirmed → G-OVH clear on this axis; else defer to the AuditLogService LOCK. |

**Phase 3 DoD:** request-time `env()` sweep == ledger; cached==uncached NF525-ticket proof green; OVH `.env` confirmed free of per-branch fiscal secret. **This phase MUST pass before Phase 4 step DEP-1.**

---

### PHASE 4 — OVH cutover / deploy (consumes Phases 1–3; strict gate order)

Live box `vps-418872ac.vps.ovh.net` / `51.210.111.124`, repo `loeymot-sketch/testttt`, branch `production`. **P0 LATENT-LIVE WARNING:** `config:cache` is ALREADY in `deploy.sh [9/12]` and the live box already runs cached config → the 11 request-time `env()` reads likely return null on prod TODAY → money/date on live tickets may be mis-rendering right now. The env→config migration is a **live-correctness fix**, not just deploy-prep.

| ID | Item | Sev/Eff | Gate | File:line | Acceptance |
|----|------|---------|------|-----------|------------|
| **DEP-0** | Pre-deploy GO/NO-GO: confirm AUTHZ-CFG (env→config) merged (grep shows 0 request-time currency/DEMO env reads outside `config/`), config keys resolve at cache-build, FISC-EXH-01 either LOCK-merged or explicitly deferred (no half-applied frozen gate). One-page GO/NO-GO listing each prerequisite SHA. | P0/S | **owner GATE** | `deploy.sh:181` | NO-GO blocks the entire lane. |
| **DEP-1** | Harden `deploy.sh [4/12]` env-guard: assert `CURRENCY/CURRENCY_SYMBOL/DATE_FORMAT` present in `.env`; add a NEW **post-`config:cache` currency smoke** after `:291` (tiny artisan asserts `currencyFormat(50)`='50,00 €' + a date renders non-empty). Regressed migration → hard abort at [9/12] **before** supervisor restart (old release stays live). | P0/M | gated by DEP-0 | `deploy.sh:181-231` (REQUIRED map) + new check after `:291` | Deploy aborts if env→config coverage regresses; converts silent null-currency into a hard abort. |
| **DEP-2** | Add `fiscal:verify-z-membership` to post-deploy fiscal gate (today only `verify-chain` runs). **Owner decides WARN vs ABORT** — recommend **WARN-and-surface** first deploy (heuristic has benign post-Z-status-flip false positives), escalate to ABORT once candidate list reviewed empty. | P1/S | **owner GATE** (threshold) | `deploy.sh:297-314` ([10/12]) + `VerifyZMembershipCommand.php:36` | Non-fatal step prints candidate count+serials to deploy log + report artifact. |
| **DEP-5** | Confirm worker/supervisor/soketi survive deploy + the outbox cascade is queue-backed: `[12/12] supervisorctl restart` cycles BOTH `queue:work redis --queue=high,default` + `lecayenne-soketi` to RUNNING (not FATAL); a probe job round-trips the `high` queue (`dispatched_at`→processed); `soketi.json` key == `.env PUSHER_*` (regenerated post-reset per `8579f7eae`). **Heed the pgrep worker-collision footgun** — do not gate worker start behind a pgrep matching another box's worker. | P1/M | operational | `deploy.sh:324` ([12/12]) + `/etc/supervisor/conf.d/lecayenne.conf` + soketi regen | All `lecayenne-*` RUNNING; probe job round-trips; soketi key matches. |
| **DEP-6** | Post-deploy §6 visual smoke checklist on live HTTPS: (1) `verify-chain --all`=CHAIN OK + `verify-z-membership` 0 + boot guards active; (2) **currency/date**: `/admin/dashboard`+`/admin/pos`+a ticket render '50,00 €' + non-empty FR dates (live proof env→config survived config:cache); (3) **real-time**: branch-1 staff login → `pusher.connection.state='connected'` AND `private-branch.1` `subscribed:true` (connected≠subscribed); (4) 6-surface sweep 0 console errors; (5) caisse-propre integrity. **Read each screenshot** (analyze, don't just capture). | P1/M | **owner GATE** (branch-1 staff credential) | `PRODUCTION_GO_LIVE_CHECKLIST.md:22-68` + `deploy.sh:324` | Checklist GREEN with analyzed screenshots; appended to `reports/cloud-readiness/`. |
| **DEP-3** | **Controlled prod execution of the frozen `ZReportService` late-salvage gate** (NOT the code — that's NF-1). After LOCK-signed + merged: pre/post `verify-chain --all`; `verify-z-membership`; **snapshot most-recent signed Z totals before, re-aggregate (read-only) after → prove already-closed Z payloads byte-identical** (patch affects only FUTURE aggregation, never re-signs a past Z). Verify cron timing (`RetryFiscalAllocCommand` runs BEFORE daily Z close). DB backup to `/root/lecayenne-backups/` before deploy. | P0/L | **FROZEN + owner human-gate** (§10); gated on NF-1 LOCK-merged | `ZReportService.php:343-440` + `RetryFiscalAllocCommand.php:144-160` + `Kernel.php` cron | Pre+post chain unbroken; historical-Z byte-identity proven; verified pre-deploy snapshot exists. |
| **DEP-4** | **Legacy fiscal-seq backfill on PROD** (DELIV-NF525-01, NF-3 execution): (1) read-only detector/census on `lecayenne_prod` (likely 0 — caisse-propre cutover); (2) owner reviews + signs; (3) verified restorable backup (gzip + test-restore to throwaway DB + `verify-chain` on restore); (4) supervised `--apply` in tx; (5) re-run `verify-chain --all` + `verify-z-membership`. **Runs LAST** — after DEP-3 (salvage windowing lets backfilled historical seq land in a Z). | P1/L | **owner human-gate** (prod data mutation); gated on DEP-3 | `RetryFiscalAllocCommand.php:60-68` + `FrontendOrderService.php:1287-1288` | Detector 0 OR owner-signed backfill leaves every row monotonic gap-free in a signed Z; chain OK; backup verified. |

**Phase 4 internal order:** DEP-0 (pre-gate) → DEP-1 + DEP-2 (self-checking first deploy) → DEP-5 + DEP-6 (standard verification, EVERY deploy) → **DEP-3** (frozen gate, own controlled deploy, pre/post chain proof + backup) → **DEP-4** (legacy backfill, last, gated on DEP-3).

**Phase 4 DoD:** deploy aborts on env→config regression (no null-currency reaches prod); post-deploy fiscal gate runs BOTH `verify-chain` + `verify-z-membership`; if FISC-EXH-01 shipped, every already-closed Z re-aggregates byte-identical (zero historical drift); legacy backfill detector ran (0 or owner-signed+backup-protected+transactional, every row in a signed Z); all `lecayenne-*` workers+soketi RUNNING + probe job round-trips; §6 visual smoke GREEN (live ticket '50,00 €' + FR dates, branch-1 `subscribed:true`, 6-surface 0 errors). Every fiscal/frozen step preceded by a verified test-restored backup + owner sign-off; evidence under `reports/cloud-readiness/`.

---

### PHASE 5 — Design / UX elevation (ships anytime; does NOT block cutover)

Two real data-correctness bugs first (crisp tests, highest operator-trust impact), then contained visual fixes, then the gated brand work + open-ended sweep.

| ID | Item | Sev/Eff | Frozen/Gate | File:line | Acceptance |
|----|------|---------|-------------|-----------|------------|
| **DUX-1** | DASH-AVGTICKET-01: average-ticket denominator nets refunds symmetric to the numerator. Numerator `daily_sales:403-407` uses `realizedRevenue()` (nets mirrors); denominator `daily_paid_orders:420-424` counts the refunded parent. Rebuild denominator from the SAME `realizedRevenue()`-scoped query + `->whereNull('parent_order_id')` (siblings `:411,:439,:555`); guard a ~0-net day from a misleading micro-ticket. **TDD failing-test first.** | P3/S | non-frozen, no gate | `app/Services/DashboardService.php:420-427` | Refund-day unit test: avg = net-realized/net-count; full dashboard PHPUnit filter green; verified on a real screenshot. |
| **DUX-2** | Rapport Articles excludes internal `technique-interne-upsell` category from screen/PDF/Excel. Add `->whereHas('category', fn($c)=>$c->where('slug','!=', HideUpsellVehicleItemsFromGridSeeder::INTERNAL_CATEGORY_SLUG))` (handle null-category edge) to the ONE `itemReport()` method (all 3 consumers inherit). Reference the constant; keep `units_sold` realized-scope intact. **TDD.** | P3/S | non-frozen, no gate | `app/Services/ItemService.php:619`; slug `HideUpsellVehicleItemsFromGridSeeder.php:65`; consumers `ItemsReportController.php:34,44,52` | Failing-first test passes; internal category absent from all 3 outputs; SKUs still orderable-by-ID. |
| **DUX-3** | OSS empty-state: replace bare `'—'` with centered i18n per-column message (`label.oss_no_preparing`/`label.oss_no_ready`, ≥40px muted `#A0A3BD`) for the 3m TV; add keys to all locales (FR canonical). Confirm + document the 2-col channel-scope contract (branch-scoped, channel-agnostic by design — do NOT add/remove columns). **Visual mandate.** | P2/S | non-frozen (OSS NOT §7); 2-col layout owner-attested — only add text | `PreparingAndReadyComponent.vue:40` (+symmetric ready col); grid `OrderStatusScreenComponent.vue:23` | Empty board shows readable FR messages (no '—', no raw `label.*`); contract documented; Read-the-screenshot proof. |
| **DUX-4** | KDS card time overflow at **1200px/4-col** (~285px/card; prior Wave-T tuned for 1280px). Prefer (A): lower `clamp()` floors — queue `36→32px`, elapsed `22→20px`, `.kds-card__main` gap `8→6px`/padding — keep vw-scaled middle + ≥1600px max for 2m readability. `overflow:visible`+`flex-shrink:0` already prevent clipping (KDS-R1 heals). **Verify at BOTH 1200px (fit) and 1600px (readability) with real captures.** | P2/S | non-frozen (KDS NOT §7) | `KdsOrderCard.vue:564-618` | Full timer+ATTENTE no clipping at 1200px/4-col AND 52px-scale readable at 1600px — both Read-screenshot proven. |
| **DUX-5** | G-WCAG **FREE half** (non-frozen, no brand-hex change): `#F4501E`@3.49:1 passes AA-large (≥3:1) + non-text UI, FAILS AA normal body text (4.5:1). Where orange is small body text on light in NON-frozen components (`KioskOrderSummaryComponent:503,619,632`, `KioskConfirmationComponent:541`, `KioskWaitingComponent:687`, `KioskInactivityOverlayComponent:202,221`) → bump to large-text threshold (≥24px/≥18.66px bold) OR swap that label to `#1A1A1A`. Produce a **per-occurrence contrast table**. | P2/M | non-frozen FREE half; frozen occurrences → DUX-6 | tokens `tokens.css:17`, `tokens-bold.css:52-55`, `pos-v5-tokens.css:41,57`, `pos-v4.css:30`, `app.css:666-667` + components above | Per-occurrence table as evidence; `git diff --stat` ZERO frozen files; brand hex unchanged. |
| **DUX-6** | G-WCAG **OWNER-BRAND GATE half**: any darken of `#F4501E`, or touching frozen `KioskWizardComponent:2635,2927` / `KioskCartComponent:934,1093,1204,1217,1256` orange-as-text → **STOP**. Prepare (do not apply) `LOCK_G-WCAG-BRAND.md` + 3 options (A keep hex+enlarge frozen labels [LOCK+triple-green]; B add `--kiosk-primary-text` darker AA token used only for small body text; C accept AA-large posture as brand-intentional) with contrast math + before/after mockups. **Does NOT block DUX-1…5.** | P2/M | **OWNER-BRAND + §7 FROZEN GATE** | frozen files above; brand token `tokens.css:17`/`tokens-bold.css:55` | Decision package delivered + explicitly NOT applied; no frozen/brand edit. |
| **DUX-7** | Bounded visual-polish sweep (NOT open-ended): (1) grep built admin bundles/templates for raw i18n (`label.`, `message.`, `0undefined`, `NaN€`) → fix missing translations; (2) money via `currencyAmountFormat` (FR '8,50 €') + FR time consistency on dashboard/reports; (3) admin report empty-state 'Aucune donnée'; (4) axe-core quick wins (focus-visible, icon-button names) on `/admin/dashboard`,`/admin/items`,`/admin/items-report`,`/kds`,`/admin/order-status-screen`. Fix only unambiguous non-frozen cosmetics; defer frozen/NF525/brand to a gate. | P3/M | non-frozen sweep; any §7 hit → route to gate | `resources/js/components/admin/**` + `resources/css/**` | ONE consolidated finding list (file:line+severity); each fix screenshot-verified; axe-core no NEW serious/critical on the 5 surfaces. |

**Phase 5 DoD:** DUX-1/DUX-2 each have a failing-first TDD test now green + screenshot-verified; DUX-3/DUX-4 pass §6 visual mandate at the exact viewports; DUX-5 ships only non-frozen WCAG fixes with a contrast table + zero frozen diff + unchanged brand hex; DUX-6 delivered as an owner decision package, NOT applied, not blocking; DUX-7 a consolidated polish table with unambiguous non-frozen fixes + axe-core no-new-serious. Global: Vitest + PHPUnit green, frozen diff = 0, `fiscal:verify-chain --all` OK (all read-side/UI), explicit-path commits, `PROJECT_BRAIN §2/§3` updated.

---

## 3. Owner Gates — sign-off table

| Gate | Item(s) | What needs sign-off | Recommended | Blocks |
|------|---------|---------------------|-------------|--------|
| **G-DELIV-CASH** | OD-1 | Auto-open driver session at float 0 on COD collection? | **NO auto-open** (Option B) — preserve audit-chain honesty; visible ops counter instead | Phase-1 delivery cash-drawer pass |
| **G-DELIV-REFUND** | OD-2 | Route refund cash-OUT to driver session vs POS drawer? | Driver session **only if open + driver-collected**, else POS fallback; never a closed session | Phase-1 delivery cash-drawer pass |
| **G-FISC-EXH-01 (LOCK)** | OD-3 / NF-1 / DEP-3 | Edit FROZEN `ZReportService` late-salvage block (reverses 2026-05-29 detect-only) | **APPROVE LOCK** + `fiscal_seq_allocated_at` column + cron-timing; alt = cron-tighten only | Phase 2 frozen edit; Phase 4 DEP-3 |
| **G-DELIV-NF525 (backfill)** | OD-4 / NF-3 / DEP-4 | Retroactive fiscal-seq on legacy PAID+seq=NULL prod rows | **Census first** (likely 0), `--apply` after OD-3 ships, supervised + backup | Phase 2 NF-3; Phase 4 DEP-4 |
| **G-DEP-0** | DEP-0 | Prerequisites merged before any VPS action | GO only with env→config merged + FISC-EXH-01 merged-or-deferred | All of Phase 4 |
| **G-ZMEMBER-THRESHOLD** | DEP-2 | `verify-z-membership` WARN vs ABORT in deploy gate | **WARN** first deploy, escalate to ABORT once candidate list empty | Deploy-gate strictness |
| **G-RT-CRED** | DEP-6 | Branch-1 staff credential to prove `subscribed:true` | Owner provides `chef@lecayenne.fr` (or equiv) — classifier won't reset staff pw autonomously | Real-time smoke proof |
| **G-WCAG-BRAND (LOCK)** | DUX-6 | Darken `#F4501E` or edit frozen kiosk orange-as-text | **Option B** (`--kiosk-primary-text` darker token for small body text only; brand untouched) | DUX-6 only (not DUX-1…5) |
| **G-AUDITLOG-SECRET (LOCK, deferred)** | UNI-03-D | Migrate frozen `AuditLogService:273` per-branch env→config | **Defer** — V1 single-branch never hits the dynamic path; revisit at multi-branch | None for V1 (CP-3 confirms) |
| **G-PUSH** | all | Push branch / open PR to protected `production` | Owner-explicit only; lane stays on `heal/massive-2dot0-2026-06-14` until then | Any remote push |

---

## 4. Ordering Dependencies (hard edges)

```
PHASE 0 (decisions) ─┬─> OD-1,OD-2 ──> Phase-1 delivery cash-drawer pass (NF-2 area)
                     ├─> OD-3 (LOCK signed) ─────────────> NF-1 (frozen) ──> DEP-3 (prod frozen exec)
                     └─> OD-4 (census in hand) ──────────> NF-3 ──> DEP-4 (prod backfill)

env→config  UNI-03-A ──> UNI-03-B + UNI-03-C ──> UNI-03-D (ledger) ──> UNI-03-E (harness+sentinel, SAME PR)
            └────────────────────────────────────────────────> CP-1/CP-2/CP-3 (Phase 3 certify)
                                                                 └──> DEP-0 ──> DEP-1 (config:cache safe on prod)

NF-4 (detector wired) ──MUST precede──> any fiscal mutation (NF-1, NF-3, DEP-3, DEP-4)   [detect-before-mutate]

NF-1-prereq (fiscal_seq_allocated_at column + stamp ALL next() sites) ──MUST precede──> NF-1 (late-salvage keys on it)

NF-1 (salvage exists) ──MUST precede──> NF-3 / DEP-4   [backfill before salvage = manufactures cross-Z orphans]
DEP-3 (prod salvage live) ──MUST precede──> DEP-4 (prod backfill)   [same reason, on prod]

RCH-01 ──> RCH-02 ──> RCH-03 (sentinel LAST; writing it mid-sweep red-bars)

DEP-0 ──> DEP-1+DEP-2 ──> DEP-5+DEP-6 ──> DEP-3 ──> DEP-4   (within Phase 4)
```

**Critical hard edges (violation = NF525 incident or shipped bug):**
1. **env→config (UNI-03-*) MUST fully precede `config:cache` on prod (DEP-1/Phase 4).** It is already armed live — highest-priority forward fix.
2. **`fiscal_seq_allocated_at` column + all-call-site stamping (NF-1-prereq) MUST precede the frozen NF-1 edit** — the salvage sub-query keys on that column; an unstamped `next()` caller = a missed row.
3. **NF-1 salvage MUST precede NF-3/DEP-4 backfill** — backfilling first numbers legacy rows into already-closed windows = manufactured cross-Z orphans.
4. **DEP-3 (prod salvage) MUST precede DEP-4 (prod backfill)** — same, on prod.
5. **NF-4 (detector wired) before any fiscal mutation** — install the standing alarm before mutating, so every later step is provable.
6. **OD-3 LOCK signed before NF-1**; **DEP-0 GO before any VPS action**; **owner `--apply` before NF-3/DEP-4 writes.**

---

## 5. Definition of Done — per phase (summary)

- **Phase 0:** OD-1…OD-4 recorded in `PROJECT_BRAIN §6`; `LOCK_FISC-EXH-01.md` owner-signed (or OD-3 alternative recorded); OD-4 census number delivered.
- **Phase 1:** forward leak flagged (NF-2) + detector wired (NF-4); all 13 `Persist*ToOutbox` guarded + sentinel proven-fail-on-removal + behavioral cascade test; RCH-04 refuted, RCH-05 reachability-proven-or-documented; env→config atomic PR with byte-equivalence + drift sentinel; `ApiKeyMiddleware` constant-time; login-timing equalized/documented; ≥1 FormRequest ratcheted. PHPUnit+Vitest green vs **2513/0**; frozen diff = 0; `verify-chain --all` OK.
- **Phase 2:** every realized-PAID path provably gap-free seq + in exactly one signed Z (incl. late + legacy); `verify-z-membership` 0 on clone; `verifyChain()` green all branches; frozen diff = ONLY the NF-1 block behind signed LOCK; owner gates obtained.
- **Phase 3:** request-time `env()` sweep == ledger; cached==uncached NF525-ticket proof; OVH `.env` free of per-branch fiscal secret. (Gates Phase 4.)
- **Phase 4:** deploy self-aborts on env→config regression; post-deploy runs `verify-chain` + `verify-z-membership`; historical-Z byte-identity if FISC-EXH-01 shipped; legacy backfill 0-or-signed+backup; workers+soketi RUNNING + probe round-trip; §6 visual smoke GREEN (live '50,00 €' + FR dates + branch-1 `subscribed:true` + 6-surface 0 errors); evidence under `reports/cloud-readiness/`.
- **Phase 5:** DUX-1/2 TDD-green + screenshot-verified; DUX-3/4 §6 visual at exact viewports; DUX-5 non-frozen WCAG + contrast table + zero frozen diff + unchanged hex; DUX-6 decision package NOT applied; DUX-7 consolidated polish + axe-core no-new-serious. Vitest+PHPUnit green, frozen diff 0, `verify-chain --all` OK, `PROJECT_BRAIN §2/§3` updated.

---

**Global invariants (all phases):** scope stays on `heal/massive-2dot0-2026-06-14`; no `git add -A` (shared-worktree footgun — explicit paths only); no push without G-PUSH; every frozen/fiscal-mutating step preceded by verified test-restored backup + owner sign-off; PHPUnit verification on the disposable `:8766/foodking_e2e` clone (never the op DB — DEVDB-GUARD); evidence per CLAUDE.md §13 (tests green + frozen diff + `verify-chain` + `verify-z-membership` + analyzed screenshots).