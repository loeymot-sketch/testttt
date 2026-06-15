Twin-class is now fully resolved with file:line evidence: ZReportService is the only frozen code touch (+37 lines, hash f8f229…, lock_history cites the 2026-06-15 LOCK); PosSync has no subscriber fan-out (its only forEach iterates unsubscribers); WebSocketService._emit:196 is already guarded (but logs at console.error, not warn — the auditor's false-positive trap is real). I have enough grounding to write the master plan.

```markdown
# MASTER VALIDATION PLAN — heal/massive-2dot0-2026-06-14 @ f2c12bed7
## FoodKing V1 LOCAL "Le Cayenne" — ~31-heal integration campaign
### Maximal, abusive, looping validation: technique + UI + UX + synchronisation, ZERO fault

---

## 1. NORTH-STAR + CONVERGENCE CONTRACT

### 1.1 North-star
Prove, with zero residual fault and concrete reproducible evidence, that **every one of the ~31 heals** integrated on `heal/massive-2dot0-2026-06-14 @ f2c12bed7` (50 code files, +989/-83 vs base `2477ff9fc`) is simultaneously:
- **(a) CORRECT** — the heal does what it promises, proven by an exact repro (curl / tinker / Playwright + file:line);
- **(b) REGRESSION-FREE** — the full PHPUnit (3333/0) + Vitest (2515/0) suites + NF525 `fiscal:verify-chain` stay GREEN, and no happy-path (sync, stock, loyalty, receipt) is broken by a guard;
- **(c) TWIN-COMPLETE** — no sibling of the same bug class was missed (unguarded `_emit`/`forEach` fan-out, un-netted PAID-filtered money/qty surface, un-allocated realized-PAID fiscal path, request-time `env()`), the lesson being **"the twin-systemic lens catches the CLASS, not the instance"** (KdsSyncService._emit was a near-miss twin of OSS #8).

The validation is **read-only on code** — no edits during the campaign. All DB-mutating repros run **only** against the disposable clone `foodking_2dot0 @ http://127.0.0.1:8780` (soketi :6001 + redis queue UP = real realtime sync). If a heal is found defective, the campaign **STOPS and escalates** (heal is a separate gated step), it does not silently patch — least of all frozen code.

**Visual is the gate (CLAUDE.md §6, §11):** a green PHPUnit/Vitest/chain does NOT close any UI/UX workstream. Every UI claim needs a screenshot that is **Read** (image analysed) — truncation/overlap/palette-drift/contrast/raw-i18n-key/`0undefined`/`NaN`/empty-state — plus an ARIA-tree snapshot for a11y (screenshots can't see a11y).

### 1.2 The convergence contract (binding)
The campaign **CONVERGES** only when **TWO CONSECUTIVE rounds** satisfy ALL of:
1. The **deduped finding ledger** (§6.3) has **P0 = 0 AND P1 = 0**;
2. The ledger is **IDENTICAL set** round-over-round — not merely identical *count*. A finding is keyed `(heal-id, file:line, repro-hash)`; a changed set (a new entry, a dropped entry, a re-derived twin) **RESETS the 2-round counter**;
3. **No heal (code change) was applied between the two rounds** — applying any heal forces the next *two* rounds to be fresh clean rounds (the reset trigger is *any code change*, heal or finding);
4. Every WS-0 gate is GREEN in **both** rounds: suites 3333/0 + 2515/0, `fiscal:verify-chain --all` = CHAIN OK, `verify-z-membership` == baseline B0, frozen-diff fence (only ZReportService at the LOCKed hash, the other 13 frozen byte-identical to base), harness-health, bundle-freshness.

**Convergence-honesty anchor (campaign's own scar — MEMORY "2nd cycle changed the set → convergence NON revendiquée"):** the loop **logs the ledger diff each round**. A silently-changed set cannot be hand-waved into "converged"; two trivially-empty sets across a heal do NOT count.

---

## 2. ARCHITECTURE — how the work splits

The single dominant architecture risk is **contention**: one Playwright MCP, one clone DB, one fiscal HMAC chain. Four lanes each wanting a "full cross-system live journey" = four near-identical journeys that corrupt each other's seq/Z assertions. The plan resolves this with a **two-phase split + one shared integration spine + a deduped ledger**.

### 2.1 Two execution phases

**PHASE 1 — CODE-LEVEL adversarial lanes (PARALLEL, read-only, NO browser, refute-by-default).**
Zero Playwright dependency → parallelizes freely across sub-agents. Contains:
- all PHPUnit / Vitest suites (full + targeted filters);
- all `grep` twin-sweeps;
- all **fault-injection Feature tests** (model-event throw on the clone connection — §3 WS-2);
- the **canonical netting fixture seeder** + per-surface **service-layer** assertions (DashboardService / ZReportService / ItemService called directly via tinker);
- the **concurrency race** (two backgrounded artisan processes);
- the **controlled late-salvage Z-window** proof (time-bound `close($from,$to)` via tinker).

Default verdict on any candidate finding = **REFUTED** until its exact repro reproduces on the clone with file:line. An inferred-but-unreproduced finding is **DROPPED, not surfaced** (anti-hallucination; EMPIRICAL > inference — G-RUPTURE was code-true-but-empirically-false).

**PHASE 2 — LIVE-BROWSER validation (SEQUENTIAL, single browser, single clone, visual + sync).**
Contains exactly:
- **THE ONE integration spine** (§5) — a single instrumented borne→caisse→KDS→OSS→delivery→receipt→dashboard journey run ONCE per round, owned by one lane, whose evidence the other lanes **consume** (TECH reads seq, SYNC reads dispatched_at/latency, UX reads screenshots, ADV reads injected-throw survival);
- the **per-surface visual Reads** that the spine doesn't naturally cover (stock-rupture 4+1 viewports, allergen badge cases, KDS >60min overflow, OSS empty-state + degraded banner, sales-report PDF parity, encaissement unattributed-COD signal).

Soketi/worker liveness is a **precondition checked once** by WS-0, not per-lane.

### 2.2 The HEAL loop (read-only campaign → gated heal)
This campaign does **not** heal inline. When a P0/P1 is **confirmed** (repro reproduces, file:line cited, deduped into the ledger):
1. STOP the round, record the finding;
2. If the fix would touch a **frozen file** or **NF525 invariant** → **escalate to owner gate** (LOCK doc), do not patch;
3. Otherwise the heal is a **separate, explicitly-gated** TDD step (RED test first), after which the **convergence counter resets** and the campaign re-runs from WS-0.

### 2.3 Round structure (intra-round strict ordering)
Mutating fiscal work must be ordered so a Z-close doesn't pollute a later window test:
1. **WS-0** baseline snapshot + all gates (suites, chain, frozen, harness-health, bundle-freshness, B0 z-membership, canonical fixture seed).
2. **Phase-1 non-fiscal-mutating** parallel lanes (twin-sweeps, isolation specs, security, allergen, netting *read-side* — DashboardService reads live, no Z-close needed).
3. **Phase-1 fiscal-mutating** (concurrency race + controlled Z opens/closes for late-salvage) — runs **LAST in Phase 1** because it closes Zs. Serialized behind a **fiscal-mutation lane lock** (a sentinel file) so no two fiscal-mutating agents share a seq window.
4. **Phase-2 spine** (the single live journey) + per-surface visual Reads — runs after Phase-1 fiscal work, in its own time window.
5. **Re-snapshot:** assert every prior closed Z byte-identical (read STORED totals), `verify-z-membership == B0`, ledger diff logged.

---

## 3. THE LANES — concrete workstreams

> Conventions: all DB-state assertions run via `php artisan tinker --execute=...` or app routes (the shell DB user is access-denied — **NEVER raw `mysql`**). All mutations hit **:8780/foodking_2dot0 ONLY** (never :8765/foodking — browser writes hit the real NF525 chain; DEVDB-GUARD footgun). Targeted suites run with the e2e env where DB-touching.

### WS-0 — Baseline gate + frozen fence + harness-health + bundle-freshness (P0, run FIRST, abort if RED)
| | |
|---|---|
| **How** | (1) `git rev-parse HEAD` == `f2c12bed77aaaa139e51809366901ae315a21085`. (2) Full PHPUnit `php artisan test 2>&1 \| tail -40` ⇒ **3333/0** (record as regression baseline). (3) Vitest `npx vitest run 2>&1 \| tail -20` ⇒ **2515/0**. (4) NF525: `php artisan fiscal:verify-chain --all` ⇒ **CHAIN OK**; `php artisan fiscal:verify-z-membership` ⇒ record **baseline at-risk count B0** (clone INHERITS source orphans — assert later "== B0", **NOT == 0**). (5) **Frozen fence (teeth):** run `frozen-zone-sha256` sentinel GREEN **AND** `git diff 2477ff9fc..f2c12bed7 -- <13 frozen paths EXCLUDING ZReportService>` returns **EMPTY** (mechanical, not "trust the sentinel which could be rebaselined"); the ONLY frozen file allowed to differ is `app/Services/Fiscal/ZReportService.php` whose baseline hash must == `f8f22911e0e3da755e281fd038385feef828ce2591ac6d84e0403af9ffe06041` and whose `_lock_history` must cite **LOCK_FISC_EXH_01_ZREPORT_LATE_SALVAGE_2026_06_15**. (6) **Modified-test integrity:** for every **modified** (not new) test file — `EmailQueueResilienceSentinelTest`, `RefundLoyaltyTryCatchHardenedSentinelTest`, `RefundCounterEntryNettedInZTest`, `RefundDiscountTvaNettingTwoWindowSentinelTest`, `frozen-zone-sha256-baseline.json` — `git diff 2477ff9fc..f2c12bed7 -- <file>` and assert the change **tightens or holds** the contract (NO `markTestSkipped`, NO assertion deletion, NO `assertTrue(true)` weakening). (7) **Migration applied:** tinker `Schema::hasColumn('orders','fiscal_seq_allocated_at')` == true. (8) **Boot-guard smoke:** `php artisan config:clear && php artisan about` clean. (9) **Harness-health gate (single owner, no per-lane re-pgrep — db5 collision footgun):** start the clone's OWN worker `APP_ENV=e2e php artisan queue:work redis --queue=high,default` bound to the foodking_2dot0 worktree; capture PID to a PID-file; `lsof -p <pid>` to assert **cwd == foodking_2dot0 worktree AND DB == foodking_2dot0**; `redis-cli ping` == PONG; `lsof -iTCP:6001 -sTCP:LISTEN` (soketi up) or `curl http://127.0.0.1:6001/usage`. Every lane **reads** this PID-file; **no lane re-pgreps**. (10) **Bundle-freshness gate (teeth):** `for f in $(git diff --name-only 2477ff9fc..f2c12bed7 -- 'resources/js/**/*.vue' 'resources/js/**/*.js'); do <map to public/js bundle>; test "$bundle" -nt "$f" || echo "STALE"; done` ⇒ must be empty; `lsof -p <serve-pid>` confirms :8780 serve cwd. A stale bundle is a **HALT (owner-gated rebuild)**, not a code finding. (11) **Canonical netting fixture (ONE seeder, owned here, reused by all):** seed `N` normal PAID orders + 1 PAID order with discount `D` + delivery-charge `C` + 1 partial `RefundWithCounterEntry` mirror of `M` + 1 cancelled-but-paid; **record the EXPECTED net constants** (revenue, order-count, top-item qty, discount, delivery) — every surface asserts against these frozen constants, not against each other. (12) **Baseline snapshot:** last `fiscal_sequence_no` per branch + current open/closed Z set → anchor file. |
| **Acceptance** | HEAD matches; PHPUnit 3333/0; Vitest 2515/0; CHAIN OK; z-membership baseline B0 recorded; frozen fence GREEN (only ZReportService at LOCKed hash + history, other 13 byte-identical to base); modified tests tighten-or-hold; column exists; boot clean; harness-health GREEN (clone's own worker PID confirmed via lsof cwd+DB, redis PONG, soketi listening); bundle-freshness GREEN; canonical fixture seeded with recorded constants; baseline snapshot taken. **Any failure ⇒ abort round, report environment-not-validatable (do NOT run abuse on a dirty baseline).** |
| **Severity** | **P0 gate** (campaign cannot proceed). NULL `dispatched_at` AFTER a RED harness-health = **harness-fault, not a code finding** (attribution rule baked into the loop). |

### WS-1 — NF525 fiscal exhaustivity: every realized-PAID path allocates gap-free seq AND enters exactly one Z (P0)
| | |
|---|---|
| **How** | **(A) Targeted suite:** `php artisan test --filter='Fiscal\|Delivery\|ChangePaymentStatus\|FiscalSeqAllocatedAt\|ZReportLateSalvage\|BackfillLegacyFiscalAlloc\|FiscalAllocOrphanRetry\|DeliveryNonCodFiscalAlloc\|RefundCounterEntryNettedInZ'` ⇒ all green. **(B) Per-heal tinker repros on clone:** *G-DELIV-FISCAL* — create UNPAID COD DELIVERY w/ assigned driver, drive `deliveryBoyOrderChangeStatus→DELIVERED`; assert `fiscal_sequence_no` NOT NULL + `fiscal_seq_allocated_at` stamped + escrow audit row (`OrderService.php:1977-2003`). *DV-T1 (non-COD twin)* — same for a CARD delivery still UNPAID at driver-flip (`:2024-2050`); ABUSE: a PREPAID/already-PAID delivery is NOT double-sequenced (`fiscal_sequence_no===null` no-op guard). *FISC-EXH-CPS-01* — `changePaymentStatus→PAID` on a tendered-but-seq-NULL order (`:2564-2640`) ⇒ `allocateFiscalSequenceIfPaidAndMissing` fires (`:2580`); ABUSE: flip to a NON-paid status ⇒ NO seq burned (no gap). **(C) LATE-SALVAGE 3-disjoint-window proof (crown jewel, `ZReportService.php:389-414`)** — deterministic, no 24h wait: via tinker `Order::find(x)->forceFill(['created_at'=>window N-1, 'fiscal_sequence_no'=>null])->saveQuietly()`; close `Z_{N-1}` with **explicit** `$to = midnight` (record stored total); set the salvage row's `fiscal_seq_allocated_at = midnight + 1min` (in-window N); close `Z_N` with **explicit** `$from = midnight`. Assert: (i) the row id appears in Z_N's salvaged collection (`:413-414`) **exactly once**, (ii) **Z_{N-1} byte-identical** (re-run `fiscal:verify-chain`, STORED totals unchanged), (iii) a **same-day control row** (created_at in-window) is caught by `$orders` ONLY, never the salvage block (single count), (iv) a **legacy backfilled control row** (`allocated_at==created_at` ⇒ `allocated_at <= $from`) is NEVER swept, (v) a **terminal/cancelled** late row does NOT salvage. **First-ever-Z edge:** late-salvage gated on `$from` (no prior window) ⇒ assert the clone's first Z with `$from=null` does NOT crash or double-count. **(D) NF-3 backfill:** `BackfillLegacyFiscalAllocCommand --dry-run` then real on seeded legacy NULL-seq paid rows ⇒ gap-free + `allocated_at` stamped + **idempotent on re-run**. **(E) Retry-cron CR-P1a:** flag a COD order `fiscal_alloc_error_at` + seq NULL, run `fiscal:retry-alloc` (`RetryFiscalAllocCommand.php:130-138`) ⇒ generic salvage allocates seq via raw-DB update WITH `fiscal_seq_allocated_at=now()` + clears flag; a CANCELED/RETURNED flagged row is NOT salvaged (`isRealizedPaid` guard). **(F)** `fiscal:verify-chain` after EVERY mutation = CHAIN OK, every closed Z byte-identical; `verify-z-membership == B0` after cron. **(G) changePaymentStatus route hardening:** name the `/admin` route + reuse the WS-6 idempotency-key harness so a double-POST during the test itself doesn't muddy the seq assertion. |
| **Surfaces/files** | `OrderService.php` (1977-2003, 2024-2050, 2564-2640, 2580); `ZReportService.php:389-414`; `RetryFiscalAllocCommand.php:130-138`; `BackfillLegacyFiscalAllocCommand`; live: borne wizard → /admin/encaissement → /admin changePaymentStatus path. |
| **Acceptance** | Every realized-PAID path (kiosk, counter, COD + non-COD delivery, changePaymentStatus, retry-cron) ends with a **monotonic gap-free non-null seq + stamped `allocated_at`** AND enters **exactly one Z**; cross-midnight row in exactly ONE Z (allocation window) with all prior Z byte-identical; same-day/legacy/terminal rows provably never double-swept; backfill idempotent; verify-z-membership == B0 after cron. **Any escape / gap / double-count / chain break / mutated Z = P0.** |
| **Severity** | **P0.** |

### WS-2 — Reactive cascade resilience: outbox-guard survives a REAL DB throw without halting stock/loyalty/receipt (P0)
| | |
|---|---|
| **How** | **(A) Twin-completeness sentinel:** `for f in app/Listeners/Persist*ToOutbox.php; do grep -L runOutboxPersistenceGuarded $f; done` prints **NOTHING** (14/14 guarded — confirmed). Codify as `OutboxPersistenceGuardSentinelTest` so a future 15th listener can't silently skip the guard. **(B) Sentinel suites:** `php artisan test tests/Feature/Reactive/OutboxCascadeGuardTest.php tests/Feature/Sentinels/OutboxPersistenceGuardSentinelTest.php` ⇒ GREEN. **(C) FORCED REAL THROW — Feature test, NOT browser (linchpin; the access-denied shell can't DDL a table "unwritable"):** in a Feature test on the clone connection register `DomainEvent::creating(fn() => throw new \RuntimeException('outbox-fault'))` inside test boot, then `event(new OrderCreated(...))` and assert: `DecrementStockOnOrderCreated` + `DecrementItemAvailabilityOnOrder` STILL decremented (no oversell), `Log::error '[Outbox] synchronous projection swallowed'` captured (`Log::shouldReceive`), exception **NOT propagated**. Repeat: `OrderStatusChanged→DELIVERED` ⇒ `AwardLoyaltyPointsOnDelivery` still awards (no lost points); `OrderPaidAtCounter` ⇒ `confirmCounterPayment` returns 2xx **not 500** (`PersistOrderPaidAtCounterToOutbox.php:16-27`). **(D) Registration order:** Read the EventServiceProvider listener registration and confirm `Persist*` is registered **FIRST** (the guard's whole premise — its swallow protects the followers). **(E) Reconcile:** after the swallowed throw, run `outbox:retry-failed` and assert the row eventually projects. **(F) #11 bump-survives-notification-failure (anchor, not prose):** the MEMORY lesson is `SendOrderMail` is an **event not a `Bus` job** — cite the test that injects a **throwing mail listener** and proves the KDS bump still completes; **if no such test exists in the 50 files, that itself is a finding** (heal claimed but unanchored). **(G) Live happy-path:** place a borne order with soketi UP, confirm outbox row created + KDS/OSS receive (guard must not change the happy path). |
| **Surfaces/files** | 14× `app/Listeners/Persist*ToOutbox.php`; `OutboxCascadeGuardTest`; `OutboxPersistenceGuardSentinelTest`; `EmailQueueResilienceSentinelTest`; EventServiceProvider. |
| **Acceptance** | 14/14 guarded (grep -L empty); a forced real DB throw leaves stock decremented + loyalty awarded + receipt path alive + POS 2xx (no 500); swallow log fired at error tier, exception not propagated; Persist* registered first; outbox row reconciles on retry; #11 anchored to a throwing-listener test. **Any oversell / lost points / POS 500 / propagated throw = P0.** |
| **Severity** | **P0.** |

### WS-3 — Sync isolation: per-listener `_emit` try/catch on KDS+OSS, twin-class CLOSED across all 4 fan-outs (P1)
| | |
|---|---|
| **How** | **(A) JS specs:** `npx vitest run tests/js/kdsSyncListenerIsolation.spec.js tests/js/ossListenerIsolation.spec.js` ⇒ green. **(B) TWIN-CLASS VERDICT (not a hunt — close it with file:line, the auditor's TWIN-A):** state explicitly as an acceptance line — `KdsSyncService._emit:517` guarded ✓; `OssSyncService._emit:441` guarded (console.warn '[OSS] listener threw (isolated)') ✓; `PosSyncService` — **no `_emit` subscriber fan-out**, its only `forEach:209` iterates *unsubscribers* already try-wrapped ⇒ **NOT a twin**; `WebSocketService._emit:196` — **already** `try{fn(data)}catch{console.error}` ⇒ **NOT a twin, but logs at `console.error` while KDS/OSS log `console.warn`**. **⇒ Twin-class CLOSED across all 4 fan-outs.** **(C) WebSocketService false-positive guard (baked in):** on the live throwing-listener probe, treat WebSocketService's `console.error '[WS] listener error:'` as the **expected isolated-error log, NOT a failure** — otherwise the convergence loop chases a non-bug forever. **(D) LIVE isolation proof (consumed from the spine, §5):** on /kds + /admin/order-status-screen, via `browser_evaluate` register a 3rd subscriber whose callback throws; trigger a real status transition; assert the legit subscribers STILL receive the payload (board updates) and only the isolated console.warn/error appears (screen does NOT freeze). Repeat under a **reconnect storm** (toggle network) hitting sync/state_change/reconnect paths. **(E) Belt-and-braces grep:** `grep -rnE "listeners.forEach\|handlers.forEach\|\.forEach\(\(callback\|\.forEach\(\(handler\|_listeners\." resources/js/services resources/js` ⇒ every subscriber-dispatch fan-out has a per-listener try/catch (also screen `MetricsBatcher` if present). |
| **Surfaces/files** | `KdsSyncService.js:517`; `OssSyncService.js:441,453-455`; `PosSyncService.js:209`; `WebSocketService.js:196`; isolation specs; live /kds + /admin/order-status-screen. |
| **Acceptance** | Both JS specs green; twin-class verdict CLOSED with all 4 fan-outs cited (2 guarded sync emitters + WS already-guarded + PosSync not-a-twin); WebSocketService console.error treated as expected; live throwing subscriber never blocks siblings on KDS or OSS (second handler fires, screen keeps updating); reconnect re-syncs; no other unguarded fan-out. **A board freeze for an innocent subscriber, or a confirmed unguarded twin loop = P1.** |
| **Severity** | **P1.** |

### WS-4 — Dashboard + report netting parity: refund mirrors netted identically on every money/volume surface (P1)
| | |
|---|---|
| **How** | **(A) Targeted suite:** `php artisan test --filter='DashboardRevenueNetting\|DashboardAvgTicketNetting\|SalesReportNetTotal\|ItemsReportUnitsSold\|RefundCounterEntryNettedInZ\|RefundDiscountTvaNetting'` ⇒ green. **(B) Service-layer assertions against the CANONICAL fixture constants (WS-0, NOT surface-vs-surface):** call `DashboardService` directly via tinker and assert vs frozen constants — `realizedRevenue()`; avg-ticket **numerator AND denominator** both on `realizedRevenue()+whereNull(parent_order_id)` (`DashboardService.php:445-458`, DUX-1) so a ~0-net refund/cancel day shows no micro-ticket and `ticket_moyen == net_revenue / net_order_count` exactly; 'Top du jour' `SUM(quantity)` nets the refunded units (`:862-875`, #12); hourly-traffic excludes the mirror (`:286 whereNull(parent_order_id)`, #13); `ItemService` items-report excludes the **internal upsell-vehicle category** (DUX-2) and `units_sold` is net. **(C) PDF parity (R-NET-PDF-01, `sales_report.blade.php:120-148`):** **delete any prior export artifact first** (assert regenerated from the heal'd blade, not a cached PDF on disk); export `/admin/sales-report` to PDF; **Read the PDF**; assert `Total` + `total_discount` + `total_delivery_charge` **byte-equal** the on-screen card to the cent (refunded parent's discount/delivery must NOT leak when a refund mirror exists). **(D) Cross-check to Z:** screen == PDF == Z (`fiscal:verify-chain`, revenue == sum). **(E) Live visual (from spine):** Read /admin, /admin/sales-report (screen + PDF), /admin/items report — FR money format (espace + virgule + €), no raw labels, netted figures match the card, no NaN/undefined/'0,00 €' anomalies. |
| **Surfaces/files** | `DashboardService.php` (286, 445-458, 862-875); `ItemService.php` / `ItemController.php`; `sales_report.blade.php:120-148`; /admin, /admin/sales-report (screen+PDF), /admin/items. |
| **Acceptance** | Every surface (revenue card, avg-ticket num+denom, top-items qty, hourly traffic, sales-report screen, sales-report PDF, items-report) reports the SAME net figure **to the cent** matching the WS-0 frozen constants; PDF == on-screen card exactly; internal category excluded; FR-formatted, 0 NaN/undefined. **Any surface disagreeing / double-subtract / PDF≠card = P1.** |
| **Severity** | **P1.** |

### WS-5 — Food-safety + security heals: allergen collision fires, all auth paths constant-time/neutral (P0 food-safety / P1 security)
| | |
|---|---|
| **How** | **FOOD-SAFETY (P0): (A)** `npx vitest run tests/js/ksAllergenBadgeCollision.spec.js` + `php artisan test --filter='KdsAllergenAggregationSplit\|KdsOrderItemsResourceAllergenExposure'` ⇒ green. **(B) #1 case-insensitive (KsAllergenBadge.vue:19,106-122):** mount with `customerAllergens=['Gluten','GLUTEN','GLuTeN']` + item `['gluten']` ⇒ `hasCollision==true` (`collisionSet` lowercases :112, `hasCollision` :115), `--collision` class, compact mode keeps colliding code; ABUSE: empty/null/non-string/numeric codes ⇒ no crash, no false collision. **(C) #9 double-decode (`KitchenDisplaySystemOrderService.php:599-616`):** feed `normalizeAllergensForHash` a DOUBLE-ENCODED snapshot (JSON string of JSON array) ⇒ decodes to the real array (not `[]`), two same-allergen items hash IDENTICALLY (merge into one KDS line), codes survive into merge key; ABUSE: triple-encoded ⇒ degrades to `[]` safely (no crash). **(D) Live (from spine):** build a borne order with mixed-case declared allergen vs lowercase catalog ⇒ **red collision badge FIRES** (`ks-allergen-badge--warning`, #FCE6E8 bg / #C21E2F border, ⚠ icon), codes clean (no `[]`/`0undefined`/escaped string) — **Read the screenshot** to confirm the visible red alert; KDS shows merged line + allergen surfaced. **SECURITY (P1; assert burn present+REACHABLE, NOT wall-clock — CI-flaky): (E) suite** `php artisan test --filter='KioskLoginEnumeration\|ForgotPasswordEnumeration\|LoginTimingOracle\|ApiKeyConstantTime\|SimulateKioskOrdersProdGuard'` ⇒ green. **(F) Reachability proof (presence-grep can't prove past an early return):** a test that hits the not-found path and asserts `Hash::shouldReceive('check')->once()` on the unknown-email branch for **BOTH** `LoginController` (`:69-82`) AND `KioskMachineLoginController` (`:60-73`). **(G) Body/status oracle (curl :8780):** forgot-password (`ForgotPasswordController.php:33-64`) ⇒ **identical neutral 200 + identical body** for KNOWN vs UNKNOWN email; `verifyCode` unknown-email ⇒ 422 'token_is_invalid' (same as bad token, `:134-150`), NOT 404. **(H) API-key (`ApiKeyMiddleware.php:23-30`):** `hash_equals` path; empty/null `config('app.api_key')` ⇒ request REFUSED; 1-byte-correct prefix not faster-accepted; `ApiKeyConstantTimeSentinel` green. **(I) simulate-orders prod-guard:** `kiosk:simulate-orders` hard-refuses with FAILURE when `app()->environment('production')` (UA-P2). |
| **Surfaces/files** | `KsAllergenBadge.vue:19,106-122`; `KitchenDisplaySystemOrderService.php:599-616`; `LoginController.php:69-82`; `KioskMachineLoginController.php:60-73`; `ForgotPasswordController.php:33-64,134-150`; `ApiKeyMiddleware.php:23-30`; `SimulateKioskOrders.php`; live /kiosk, /kds. |
| **Acceptance** | Mixed-case declared allergen fires RED alert (screenshot-confirmed, proves #1); double-encoded snapshot decodes to real codes / correct merge (proves #9); malformed input degrades safely; forgot-password neutral 200 identical known/unknown; verifyCode no-user == bad-token; both logins carry a **reachable** dummy-hash burn (`->once()` on not-found); ApiKey hash_equals + empty-key reject; simulate-orders prod-refused. **Any silent allergen miss = P0; any enum oracle (status/body) / non-constant compare / prod fake-order injection = P1.** Timing-only (burn present+reachable but measurable) = **P2 (document, don't gate)**. |
| **Severity** | **P0 (food-safety) / P1 (security).** |

### WS-6 — Idempotency / concurrency: double-encaissement, replay, concurrent fiscal allocation cannot gap/double-charge/duplicate-Z (P0)
| | |
|---|---|
| **How** | **(A) Double-encaissement:** POST the same counter-collect / changePaymentStatus twice with the SAME `X-Idempotency-Key` ⇒ single settlement, single `fiscal_sequence_no`, 2xx replay (not a 2nd seq); conflicting payload ⇒ **409**. **(B) Re-flip replay:** re-flip an already-PAID order to PAID ⇒ `allocateFiscalSequenceIfPaidAndMissing` is a NO-OP (seq present ⇒ not double-sequenced). **(C) Concurrent allocation (CONCRETE primitive — tinker is single-process):** two **backgrounded** `php artisan tinker --execute` processes (or an artisan command spawning 2 `DB::transaction` closures, or 2 parallel curl to `changePaymentStatus` with distinct idempotency keys) firing two transitions that both allocate a seq on branch 1 ⇒ `FiscalSequenceService` `Cache::lock + FOR UPDATE` yields **two DISTINCT consecutive monotonic gap-free seqs**, no dup, no gap (`fiscal:verify-chain --branch=1` OK). This stresses the heal's **NEW call sites** — re-prove no two call sites collide. **(D) Retry-cron idempotency:** run `fiscal:retry-alloc` TWICE ⇒ 2nd run a no-op for already-allocated rows. **(E)** Existing `Idempotency` sentinels green. **SHARED-RESOURCE LOCK:** serialize this against the spine's fiscal mutations via the **fiscal-mutation lane lock** (§2.3) — never two fiscal-mutating agents on the same seq window simultaneously. |
| **Surfaces/files** | `FiscalSequenceService.php`; `IdempotencyKeyMiddleware.php`; `OrderService` changePaymentStatus + counter-collect; /admin/encaissement. |
| **Acceptance** | Same-key double-encaissement = one settlement + one seq + 2xx replay; conflicting payload = 409; re-flip-to-PAID = no 2nd seq; concurrent allocation = distinct consecutive gap-free seqs (chain OK); retry-cron twice = idempotent; idempotency sentinels green. **Any duplicate seq / gap / double-charge / duplicate Z entry = P0.** |
| **Severity** | **P0.** |

### WS-7 — Systemic-TWIN re-sweep: hunt siblings the campaign MISSED (P1, inherits parent severity)
| | |
|---|---|
| **How** | Read-only adversarial grep across the **WHOLE codebase**, not just the 50 changed files. **(A) Unguarded fan-out** — covered + CLOSED by WS-3 twin-verdict (re-assert here as a sweep). **(B) Un-netted money/qty surface:** `grep -rn "payment_status', *PaymentStatus::PAID\|where('payment_status', PaymentStatus::PAID)" app/Services app/Http` — every revenue/qty/volume aggregation still filtering raw `payment_status=PAID` instead of `realizedRevenue()+mirror-netting` is a #12/#13/DUX-1 twin (refund leak); cross-check DashboardService, OrderService, ItemService, **any export/PDF/CSV**. **(C) Un-allocated realized-PAID flip:** `grep -rn "payment_status = PaymentStatus::PAID\|->payment_status = .*PAID" app` — **enumerate ALL PAID-flip sites** (kiosk create, counter-collect, COD/non-COD delivery, changePaymentStatus, refund mirror) and prove each either allocates a seq or is provably already-sequenced; a PAID-flip leaving `seq=NULL` with **no** `fiscal_alloc_error_at` flag escapes BOTH the Z AND the retry-cron = P0. **(D) Request-time `env()` (UNI-03 twin):** run `NoRequestTimeEnvSentinel` (scans `app/` recursively — covers `app/Http/Resources`, catches the `OrderItemResource env('CURRENCY')` class) **PLUS** the sentinel's **scope-limit fix:** it does NOT scan `config/`, blade views, or frontend JS — so additionally `grep -rn "env(" resources/views config | grep -v "^config/.*=> env("` (a blade `{{ env(...) }}` or a runtime `env()` inside a config closure is a UNI-03 sibling the sentinel can't see; top-level `=> env()` in a config file is cache-safe = fine). **(E) Outbox-guard completeness:** re-grep `Persist*ToOutbox` count vs `GuardsOutboxPersistence` count (must stay equal). For EVERY candidate twin: file:line via Read/grep + a **reproduction** (curl/tinker/DOM); **a twin with no repro is REJECTED, not surfaced.** |
| **Surfaces/files** | Whole `app/`, `resources/views`, `config/`, `resources/js`; `NoRequestTimeEnvSentinel`. |
| **Acceptance** | ZERO unguarded subscriber fan-outs; ZERO raw-PAID-filtered money/qty surfaces; ZERO realized-PAID flip sites that leave seq=NULL without alloc-or-error-flag; ZERO request-time `env()` in app code (sentinel green) **and** none in blades/config-closures (grep clean); 14/14 outbox guarded. **Any confirmed twin (file:line + repro) is a finding at its INHERITED severity** (NF525/food-safety = P0; netting/sync = P1). An empty sweep with evidence = clean. |
| **Severity** | **P1 floor, inherits parent for confirmed twins.** |

### WS-8 — Delivery integrity + UNI-03 config:cache safety + recall leg (P1)
| | |
|---|---|
| **How** | **(A) G-DELIV-ORPHAN (`OrderService.php:2343-2355`):** admin/OSS `changeStatus→OUT_FOR_DELIVERY` on a DELIVERY order with `delivery_boy_id=NULL` ⇒ **422 'Assignez un livreur'**; with a driver ⇒ allowed; ABUSE: a non-delivery type / config flag off ⇒ NOT blocked (guard is type+config-scoped, no false 422 on takeaway/dine-in). **(B) CR-P1b off-book (`OrderService.php:2356-2372`):** admin/OSS `changeStatus→DELIVERED` on UNPAID COD ⇒ **422 'finalisée par le livreur'** (cash never settles off-book); prepaid delivery still allowed. **(C) G-DELIV-CASH (`OrderService.php:2124-2160`):** drive a COD DELIVERED with NO open driver cash session ⇒ escrow row STILL written (cash recorded) AND a **countable** signal: `Log::error 'unattributed COD collection'` + **`AuditLogService` action `cash.delivery.unattributed_collection`** written (assert the queryable row, not just UI), and **NO session auto-opened** (owner OD-1). **(D) config:cache safety (UNI-03):** `php artisan config:cache` then re-probe currency `€` / FR date / DEMO badge on /admin/items + a receipt ⇒ NO blank / `0undefined` / wrong symbol (`env()` returns null post-cache; `config()` must hold); `NoRequestTimeEnvSentinel` green proves no remaining request-time env() in app. **(E) RECALL leg (GAP-1 closure):** bump an order then **recall** it ⇒ assert (i) `PersistKdsOrderRecalledToOutbox` fired through the guard, (ii) board shows recall live on a 2nd tab, (iii) recall does NOT re-allocate/burn a fiscal seq. **Sentinels:** `DeliveryDispatchRequiresDriver`, `UnattributedCodCollection`, `CashAudit2StepDriverFlow`, `ChangePaymentStatusOffBookGuard` green. |
| **Surfaces/files** | `OrderService.php` (2124-2160, 2343-2372); `AuditLogService`; `PersistKdsOrderRecalledToOutbox`; /admin/encaissement (Caisse Livreur); /kds. |
| **Acceptance** | OFD blocked without driver (type+config scoped, no false positives); admin can't off-book a COD delivery (422); COD with no session records cash + emits a **countable audit row** + no auto-open; encaissement surfaces it; config:cache leaves €/FR-date/DEMO correct; recall projects through guard + shows live + no seq re-burn. **Unmanned dispatch / silent cash loss / auto-opened phantom session / off-book settlement = P1.** |
| **Severity** | **P1.** |

### WS-9 — UI/UX visual sweep across the 7 surfaces (P1 visual = gate; some P2)
| | |
|---|---|
| **How** | **(UX-1) A-012b stock legibility (`StockRuptureDashboardComponent.vue:154-157,691-702`):** /admin/stock/rupture at **5 widths — 1440 / 1024 / 768 (split-pane regression viewport) / ~191 (the ADV-lane narrowest split-pane) / 390** (RECONCILE the two lanes' disputed widths — test BOTH 768 and 191). At each: `browser_take_screenshot` full-page + **Read**; assert every name column fully legible (no single-letter / '9px' collapse / 'Sa…' truncation — `minmax(230px,1fr)` floor forces fewer columns, 2-line clamp allowed, mid-word collapse NOT); toggle pill (role=switch) text 'En stock'/'Rupture' never clipped; rail labels+badges not overlapping. `browser_evaluate` to read computed `grid-template-columns` (must resolve to FEWER columns, not starved names) + `.name` clientWidth ≥ ~120px @768; `:title` tooltip on long names; tab → focus-visible ring; distinct `empty` vs `empty_search` copy (no raw key). **(UX-2) Allergen badge** — covered by WS-5 D + a11y: `browser_snapshot` ⇒ `role=alert + aria-live=assertive` on collision, `role=group + aria-live=off` otherwise; `aria-label` FR-localized, **NO PII**; `prefers-reduced-motion` ⇒ pulse animation removed (WCAG 2.3.3). **(UX-3) KDS overflow (`KdsOrderCard.vue:333-355`):** backdate an order >60min ⇒ `elapsedFormatted` rolls past 59:59 to H:MM WITHOUT column-clip/grid-break; <60min MM:SS intact; queue/source/state/allergen resolved (no `0undefined`/raw label.X); `kds_card_aria` label present. **(UX-4) OSS empty + degraded (`PreparingAndReadyComponent.vue:7,53,78`):** empty board ⇒ both columns show localized centered `label.oss_no_orders` (28px muted #A0A3BD, NOT raw key, NOT void, NOT '0 orders'); block ws:6001 (route abort) ⇒ `connectionDegraded` banner appears + recovers; @1920 wall-display columns balanced, contrast OK. **(UX-6) Encaissement + Caisse Livreur:** labels/amounts FR-resolved no raw key; unattributed-COD signal visible (color+text, `role=alert/aria-live`, not silent, not auto-open); amounts FR-formatted no NaN; focus-visible on tab. **(UX-8) Gestion sweep (P2):** /admin/items (FR money, VAT/TVA label, images), historique (date filters, refund mirrors paired with parent not phantom rows), users (roles resolved, no enum hint), /login, forgot-password (identical neutral copy known vs unknown — screenshot both), config:cache € + FR date + DEMO intact; icon-only buttons have `aria-label`, delete dialog FR. **a11y rule:** ARIA assertions need `browser_snapshot` (ARIA tree) + computed-contrast, NOT eyeballing the image (over-certifying a11y from a screenshot is the recurring failure mode). |
| **Surfaces/files** | All 7 live surfaces; `StockRuptureDashboardComponent.vue`; `KsAllergenBadge.vue`; `KdsOrderCard.vue`; `PreparingAndReadyComponent.vue`. |
| **Acceptance** | 5/5 stock viewports legible (computed name-col ≥120px @768, fewer-columns not starved @191); allergen RED alert + role=alert/aria-live + reduced-motion honored + no PII; KDS >60min no clip; OSS empty localized + degraded banner recovers; encaissement signal prominent; gestion surfaces clean (FR money/date, no raw key/0undefined); forgot-password identical copy; config:cache-safe €/date/DEMO; icon-only aria-labels. Every claim **Read-from-image**, every a11y from `browser_snapshot`. **Any raw label / truncation / 0undefined / blank empty-state / palette-drift on a live surface = P1 (visual mandate is the gate).** |
| **Severity** | **P1 (visual) / P2 (gestion cosmetics).** |

---

## 4. ABUSE SCENARIO CATALOG (exact repro + PASS criterion)

> Each runs on :8780/foodking_2dot0 via tinker/curl/Playwright. Default verdict **REFUTED** until the repro reproduces.

| # | Scenario | Exact repro | PASS criterion | Sev |
|---|---|---|---|---|
| A1 | **Late-salvage cross-midnight double-count** | Order created_at∈window N-1, seq NULL; close Z_{N-1} `$to=midnight`; set `fiscal_seq_allocated_at=midnight+1min`; close Z_N `$from=midnight` | Sale in EXACTLY Z_N once; Z_{N-1} byte-identical; same-day control caught by `$orders` only; appears once | P0 |
| A2 | **Legacy-row re-sweep** | Backfilled row `allocated_at==created_at` touched after its Z closed | `allocated_at<=$from` ⇒ NEVER swept; legacy control excluded | P0 |
| A3 | **Frozen Z rewrite** | Run late-salvage, re-`verify-chain` | All prior closed Z STORED totals byte-identical | P0 |
| A4 | **First-ever-Z `$from=null`** | Close the clone's very first Z | No crash, no double-count on null window key | P0 |
| A5 | **Alloc-failure degraded** | Force `FiscalSequenceService->next` throw during COD DELIVERED | Order stays PAID + seq NULL + `fiscal_alloc_error_at`; food NOT un-delivered; no driver crash; retry-cron later salvages | P0/P1 |
| A6 | **Concurrent encaissement race** | 2 backgrounded `changePaymentStatus→PAID` (distinct idem keys), same branch | 2 distinct consecutive gap-free seqs; chain OK | P0 |
| A7 | **Seq=NULL realized sale (missed flip site)** | Enumerate ALL PAID-flip sites (WS-7 C) | Each allocates-or-flags; none leaks seq=NULL silently | P0 |
| A8 | **Outbox throw halts cascade** | `DomainEvent::creating(throw)` Feature test on OrderCreated | Stock decremented (no oversell) + loyalty (no lost pts) + receipt 2xx (no 500); swallow logged | P0 |
| A9 | **POS-500 via throw** | Outbox throw on `OrderPaidAtCounter` | `confirmCounterPayment` 2xx, not 500 | P0 |
| A10 | **Allergen case-evasion** | `customerAllergens=['GLUTEN','Gluten']` vs item `['gluten']` | RED collision badge FIRES (case-insensitive); screenshot-confirmed | P0 |
| A11 | **Allergen double-decode** | double-encoded snapshot to `normalizeAllergensForHash` | Decodes to real codes (not `[]`); two same items merge + hash identically; triple-encoded degrades safely | P0 |
| A12 | **Sync listener poison** | `browser_evaluate` register throwing subscriber on KDS+OSS mid-stream + reconnect storm | All other tabs keep receiving updates; throwing one isolated (warn/error log); no freeze; WebSocketService console.error = expected | P1 |
| A13 | **Auth enumeration trifecta** | known vs unknown email/username: HTTP status + body identical; `Hash::check->once()` on not-found | Indistinguishable by status & body; burn reachable on BOTH logins; ApiKey hash_equals + empty-key reject; simulate-orders prod-refused | P1 |
| A14 | **Off-book COD / orphan dispatch** | admin `→DELIVERED` on UNPAID COD; `→OUT_FOR_DELIVERY` no driver | Both 422; no false 422 on takeaway/dine-in | P1 |
| A15 | **Unattributed COD collection** | COD DELIVERED, no open driver session | escrow written + countable audit row `cash.delivery.unattributed_collection` + no auto-open; encaissement surfaces it | P1 |
| A16 | **Refund-leak twin / PDF≠card** | canonical fixture: discount+delivery+refund mirror | avg-ticket(num+denom) / top-du-jour / hourly / sales card / **PDF** / items-report all net identically; PDF Total == card to the cent | P1 |
| A17 | **config:cache blank-out (UNI-03)** | `php artisan config:cache` then render €/date/DEMO | No blank / wrong symbol / raw config key; sentinel + blade/config grep clean | P1/P2 |
| A18 | **Ruptured-item sale (#15)** | mark `is_available=0` for branch_id=1, POST barcode lookup at /admin/pos | 404 'branch_unavailable'; branch-available item OK | P1 |
| A19 | **Double-encaissement / offline flush** | spam KDS bump + encaissement confirm; queue offline POS payment then reconnect | single settlement, single seq, single receipt, single bump | P1 |
| A20 | **KDS >60min overflow** | backdate order >60min on /kds | elapsed rolls to H:MM, no column clip / grid break (Read screenshot) | P1 |
| A21 | **A-012b narrow split-pane** | resize /admin/stock/rupture to 768 AND ~191px | names legible (not single-letter), grid resolves fewer columns | P1 |
| A22 | **OSS empty void** | empty the OSS board | localized centered empty copy, not raw key / void | P1 |
| A23 | **Soketi hard-kill mid-flight** | place order while :6001 down, then restart | order still reaches KDS+OSS via polling within budget; reconnect fires catch-up burst, drains backlog; order never lost | P1 |
| A24 | **Transient 5xx/401 storm** | inject one bad sync response | poller NOT permanently halted (re-armed); kitchen self-heals | P1 |
| A25 | **Contract-violation payload** | event with incomplete required keys | lands in `failed` with `last_error 'contract_violation:'`, NOT broadcast; no 6× retry storm | P1 |
| A26 | **EventType::all() gap** | a const missing from `all()` ⇒ `EventContract::validate` silently skips | codified sentinel: ∀ Persist*ToOutbox const ∈ `EventType::all()` | P1 |
| A27 | **Cross-branch channel leak** | kiosk/customer token subscribes to `branch.{other}` | authz denied (routes/channels.php:41-61) | P1 |
| A28 | **Double-dispatch race** | two workers grab same DomainEvent id | `lockForUpdate+dispatched_at` ⇒ broadcasts exactly once (no dup KDS ticket / double CA) | P2 |
| A29 | **Outbox rescue loop** | run `outbox:rescue` / `retry-failed` on dispatched + terminal rows | re-queue only `attempts<5`; terminal quarantined; no re-broadcast / infinite churn | P2 |
| A30 | **Observability lying green** | soketi dead, hit `SyncOverviewController.outboxOverview` | `ws:heartbeat` freshness reflects DEAD (stale), not cached-green | P2 |
| A31 | **UNPAID bumps KDS** | try to bump/notify an UNPAID order | `KitchenReleaseRule` SSOT blocks; listener-iso must not resurrect unreleased-bump | P1 |
| A32 | **#11 bump survives notif-fail** | throwing mail listener on `SendOrderMail` (event, not Bus job) | KDS bump still completes; cite the anchoring test (or finding if absent) | P1 |
| A33 | **Recall leg (GAP-1)** | bump then recall an order | `PersistKdsOrderRecalledToOutbox` fires through guard; recall live on 2nd tab; no seq re-burn | P1 |

---

## 5. THE LIVE CROSS-SYSTEM JOURNEY (the ONE integration spine)

**Run ONCE per round, sequentially, single browser, owned by one lane; TECH/SYNC/UX/ADV consume its evidence.** Multi-tab Playwright (/kiosk/idle, /kds, /admin/order-status-screen, /admin) + `browser_console_messages` + `browser_network_requests`. Inject **one outbox throw** + **one sync-listener throw** during the run to prove cascade + board both survive. **Precondition:** WS-0 harness-health GREEN (clone's own worker consuming `high`).

| Leg | Action | Per-leg checks |
|---|---|---|
| **L1 Borne** | /kiosk/idle → wizard order, declare a **mixed-case allergen** vs catalog, pay (Plan B route-to-counter) | Read each step: prices per NF525 SSOT (backend, no price-on-step), allergen badge, brand palette Cayenne #F4501E/#FFB800, no raw key; `t0` = POST 2xx |
| **L2 Outbox→queue** | (instrument) | `domain_events` row for order.created within ≤1s (`t1`); `dispatched_at` set by **clone's own worker** (`t2`, NOT NULL — proves `high` consumed); EventContract no `contract_violation:` |
| **L3 KDS sync** | /kds (2 tabs) | New ticket APPEARS live via soketi (`t3`), allergen surfaced on a correctly-**MERGED** line (twin items #9); 2-tab parity; isolated throwing-subscriber doesn't freeze board; no `0undefined`/raw label.X |
| **L4 Caisse encaissement** | /admin/pos + /admin/encaissement collect **espèces** | PAID + `fiscal_sequence_no` monotonic gap-free + `allocated_at` stamped + **operator=cashier** attribution; NF525 ticket (operator/seq/FR money/legal mentions, no `0undefined`) — Read the ticket |
| **L5 KDS bump** | bump on /kds | Bump **survives an injected notification failure** (#11); UNPAID never bumps (KitchenReleaseRule); recall leg (A33) optional |
| **L6 OSS** | /admin/order-status-screen | Order flows preparing→prêt **live** (isolated listeners, OssSyncService); 2-tab; empty/degraded states coherent |
| **L7 Delivery** | seed/convert COD delivery: dispatch (driver REQUIRED — G-DELIV-ORPHAN UI BLOCKS with FR copy, screenshot the guard) → DELIVERED at doorstep | seq allocated at doorstep + escrow audit; unattributed-COD case ⇒ countable audit row + no auto-open |
| **L8 Receipt** | NF525 ticket | Complete: operator identity, fiscal seq, FR money, legal mentions, no `0undefined` |
| **L9 Dashboard** | /admin | CA increments by **EXACT net** (`t5`, to the cent); avg-ticket net (num+denom); top-du-jour net; hourly traffic excludes any mirror |
| **L10 Reports** | /admin/sales-report (screen+PDF), /admin/items, /admin/stock/rupture, historique, users | PDF Total == card to the cent; A-012b names legible at narrow width; items-report excludes internal category; refund mirrors paired (not phantom rows) |
| **L-END** | `fiscal:verify-chain --all` + `verify-z-membership` | CHAIN OK; every prior Z byte-identical; z-membership == B0 |

**Timing budget:** assert ordering `t0<t1<t2<t3` and KDS+OSS+dashboard all reflect within **≤8s** (SYNC-2), measured with **server timestamps** (occurred_at / dispatched_at), NOT wall-clock alone (avoid false P1 flakes). **For EACH leg:** Read the screenshot (truncation/overlap/raw-key/palette-drift/empty-state) + `browser_console_messages` clean (isolated listener warn/error acceptable).

**Acceptance:** single journey passes with gap-free monotonic seq + correct operator + complete NF525 ticket; allergen merged+surfaced; bump survives notif-fail; OSS/KDS live-update with an injected throw isolated; delivery seq+escrow + unattributed-COD audit; dashboard CA/avg/top/traffic all net-exact == journey net; CHAIN OK + prior Z byte-identical; **zero console error / raw label / blank across all screenshots.** Any cross-system regression / sync freeze / fiscal escape / visual fault = **P0/P1**.

---

## 6. SEVERITY GATES + DECISION TREE + CONVERGENCE

### 6.1 Severity gate table (airtight)
| Class | Severity | Gate |
|---|---|---|
| NF525 seq escape / gap / double-count / chain break / Z mutated | **P0** | hard NO-GO, abort round |
| Food-safety allergen false-negative (case / double-decode) | **P0** | hard NO-GO |
| Outbox throw halts stock/loyalty/receipt; POS 500 on PAID | **P0** | hard NO-GO |
| Duplicate seq / double-charge / duplicate Z entry under concurrency | **P0** | hard NO-GO |
| Off-book COD settlement / unmanned dispatch reaching OFD | **P1** | NO-GO until healed |
| Sync board freeze for innocent subscriber; un-netted money/qty surface; auth enum oracle (status/body); seq=NULL realized-PAID flip; PDF≠card; ruptured-item sellable; lost order while degraded | **P1** | NO-GO |
| Visual: raw label / truncation / 0undefined / blank empty-state on a live surface | **P1** | NO-GO (visual mandate IS the gate) |
| **Missed twin of any P0/P1** | **inherits parent** | as parent; an unresolved P0/P1 twin = NO-GO identical to a primary |
| Timing-only oracle measurable but burn present+reachable | **P2** | document, not gate (CI-flaky) |
| Observability green while soketi down; double-broadcast under race; rescue loop | **P2** | document |
| Gestion cosmetic (minor i18n/contrast on a non-critical surface) | **P2/P3** | document |
| **Stale bundle detected** | **HALT** | owner-gated rebuild; NOT a code finding |
| Deferred-by-design (frozen-only fix, owner-gated UNI-03 cloud, V1-LOCAL-out-of-envelope) | **n/a** | documented with reason, not counted as a fault |

### 6.2 Per-round decision tree
1. **WS-0 RED** → ABORT round, report environment-not-validatable. (NULL dispatched_at after RED harness-health = harness-fault, not a code finding.)
2. **WS-0 GREEN** → run Phase-1 non-fiscal lanes (parallel) → Phase-1 fiscal lanes (serialized, lane-lock) → Phase-2 spine + visual Reads.
3. **Any P0** → STOP round; if fix touches frozen/NF525 → escalate owner gate; else heal as a separate gated TDD step → **counter resets** → re-run from WS-0.
4. **Any unresolved P1** → NO-GO; same heal-or-escalate path; **counter resets**.
5. **Confirmed twin** → inherits parent severity; same treatment.
6. **P2/P3 only** → document in ledger, round may be "clean" for convergence purposes (P0+P1=0), but P2/P3 remain backlog.
7. **Round clean (P0+P1=0)** → log ledger diff vs prior round → check convergence (§6.4).

### 6.3 The deduped finding ledger (resolves "identical set" ambiguity)
A single ledger keyed `(heal-id, file:line, repro-hash)`. A finding is ONE entry regardless of how many lanes hit it (two lanes re-deriving the same twin = one entry, not "set changed"). Each entry carries: severity, exact repro (curl/tinker/Playwright), file:line, status (open/healed/refuted/deferred). **Convergence compares the deduped ledger round-over-round**, and the loop logs the diff.

### 6.4 Convergence rule (binding, closes the 3 loopholes)
**CONVERGED** iff **two CONSECUTIVE rounds** where:
- the deduped ledger has **P0 = 0 AND P1 = 0**; **AND**
- the ledger is the **identical SET** (not just count) — any new/dropped/re-derived entry resets the counter; **AND**
- **no heal (any code change) was applied between the two rounds** — a heal in round N forces N+1 and N+2 to be fresh clean rounds; **AND**
- every WS-0 gate GREEN in both rounds (suites 3333/0 + 2515/0, CHAIN OK, z-membership == B0, frozen fence = only ZReportService at LOCKed hash + 13 others byte-identical, harness-health, bundle-freshness).
- *Empty-set guard:* two trivially-empty sets across a heal do NOT count — the no-heal-between clause prevents declaring convergence on a single real clean round dressed as two.

### 6.5 Risks baked into the loop
Harness-fidelity (pgrep worker-collision → NULL dispatched_at is harness-fault first); shared-infra (mutations on :8780 only, never :8765, never `php artisan test` against an op-DB .env.testing — DEVDB-GUARD); frozen-touch (ZReportService read-only — defect = owner gate, not patch); timing-test flakiness (assert burn reachable, NOT wall-clock); convergence-integrity (changed set resets; log the diff); false-refute (EMPIRICAL > inference — drop unreproduced candidates); fiscal shared-resource (serialize Z-mutating work behind the lane-lock); stale-bundle (HALT, owner-gated rebuild); a11y blind-spot (browser_snapshot + computed-contrast, never eyeball).

---

## 7. DEFINITION OF DONE

The campaign is **DONE = GO** only when, across **TWO CONSECUTIVE convergent rounds** (§6.4):

1. **WS-0 gate GREEN** both rounds — PHPUnit 3333/0, Vitest 2515/0, `fiscal:verify-chain --all` = CHAIN OK, `verify-z-membership` == B0; frozen fence = ONLY `ZReportService.php` differs (hash `f8f229…06041` + `_lock_history` cites LOCK_FISC_EXH_01), the other 13 frozen files **byte-identical to base 2477ff9fc** (mechanical `git diff` EMPTY); modified tests tighten-or-hold; harness-health + bundle-freshness GREEN.
2. **WS-1** — every realized-PAID path (kiosk, counter, COD + non-COD delivery, changePaymentStatus, retry-cron) ends non-null monotonic gap-free seq AND enters exactly one Z; the 3-disjoint-window late-salvage proof shows the cross-midnight sale counted ONCE with all prior Z byte-identical; same-day + legacy + terminal rows provably never double-swept; backfill idempotent.
3. **WS-2** — 14/14 outbox listeners guarded (sentinel); a FORCED real DB throw leaves stock/loyalty/receipt intact + no 500 + swallow logged; #11 anchored to a throwing-listener test; happy path unaffected.
4. **WS-3** — twin-class CLOSED across all 4 fan-outs (file:line each); a throwing subscriber never freezes KDS or OSS live; isolation specs green; WebSocketService console.error treated as expected.
5. **WS-4** — refund/discount scenario nets identically **to the cent** on revenue card, avg-ticket num+denom, top-items qty, hourly traffic, sales-report screen AND PDF (regenerated), items-report (internal category excluded), all matching the WS-0 frozen constants; PDF == card.
6. **WS-5** — live mixed-case allergen collision badge fires (screenshot), allergens decoded once, all auth paths neutral/constant-time (burn reachable on both logins via `->once()`, forgot/verifyCode bodies identical known/unknown, hash_equals + empty-key reject, simulate-orders prod-refused).
7. **WS-6** — idempotent double-encaissement (one seq, 2xx replay, 409 conflict), concurrent allocation = distinct gap-free seqs, retry-cron idempotent.
8. **WS-7** — twin-sweep returns ZERO unguarded `_emit` / un-netted money-qty surface / seq=NULL realized-PAID flip / request-time `env()` (app + blade/config grep), each backed by grep+repro evidence.
9. **WS-8** — OFD blocked without driver, off-book COD 422, unattributed-COD countable audit row + no auto-open, config:cache-safe €/date/DEMO, recall projects through guard + no seq re-burn.
10. **WS-9 / spine** — full live journey + every screenshot visually clean (FR i18n/money, no raw labels, A-012b legible at 768 AND 191, no console errors), dashboard CA delta == net revenue to the cent, NF525 ticket correct, sync ≤8s on server timestamps, 2-tab parity on KDS+OSS.
11. **The deduped ledger is EMPTY at P0+P1 in both rounds, identical, with no heal applied between them, and the ledger diff is logged** (convergence honesty).

**Any P0 or unresolved P1 = NO-GO.** Every finding cited with **file:line + reproduction** (anti-hallucination); unreproducible findings DROPPED, not surfaced. Frozen defects escalate to owner gate, never auto-patched. Deferred-by-design items documented with reason, not counted as faults. The frozen touch is and remains **exactly** the LOCK-approved ZReportService late-salvage block — nothing else.
```

Plan delivered above as a single executable master validation plan. Grounding verified against the live worktree: HEAD `f2c12bed7` confirmed; 14/14 `Persist*ToOutbox` guarded (`grep -L`=empty); the only frozen code touch is `ZReportService.php` (+37 lines, hash `f8f229…06041`, `_lock_history` cites LOCK_FISC_EXH_01_ZREPORT_LATE_SALVAGE_2026_06_15); the other 13 frozen files have zero diff vs base. The four sync fan-outs are resolved with file:line — `KdsSyncService._emit:517` and `OssSyncService._emit:441` guarded, `PosSyncService.forEach:209` iterates unsubscribers (not a twin), `WebSocketService._emit:196` already guarded but logs `console.error` (baked in as the expected-isolated-log false-positive guard). Key file:lines: `OrderService` 1977-2003/2024-2050/2124-2160/2343-2372/2564-2640, `ZReportService:389-414`, `RetryFiscalAllocCommand:130-138`, `DashboardService` 286/445-458/862-875, `sales_report.blade.php:120-148`, `KsAllergenBadge.vue:106-122`, `KitchenDisplaySystemOrderService.php:599-616`.