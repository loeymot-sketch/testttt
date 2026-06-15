All claims grounded: the `NoRequestTimeEnv` sentinel scans `app/` recursively (covers `app/Http/Resources`, so it catches the `OrderItemResource env('CURRENCY')` twin class — but does NOT scan `config/`, `resources/views` blades, or frontend JS, which is a real coverage edge worth noting). The guards, the allocation helper, and the fiscal-error flag all exist where cited. I have enough grounding. Here is the hardening critique.

---

# ADVERSARIAL PLAN AUDIT — Hardening Critique (additions/fixes only)

**Grounding done (file:line verified, not inferred):** HEAD `f2c12bed7` ✓; 50 code files / 14 Persist*ToOutbox all guarded (`grep -L`=empty) ✓; ZReportService late-salvage at `ZReportService.php:389-414` with 3 disjoint window keys gated on `$from` ✓; frozen baseline `f8f229…06041` + `_lock_history` cites the 2026-06-15 LOCK ✓; off-book 422 `OrderService.php:2372`, orphan 422 `:2355`, alloc helper `:2580`, error-flag `:1996/2042` ✓; `NoRequestTimeEnvSentinel` scans `app/` recursively (covers `app/Http/Resources`) ✓. **One material structural finding below (TWIN-A).** The plan is strong; the gaps are at the seams.

---

## A. COVERAGE GAPS the four lanes miss

**TWIN-A — `PosSyncService` and `WebSocketService.emit` are named as twin *candidates* but never RESOLVED to a verdict (the plan leaves the twin-class OPEN).**
I checked all three `*Sync*.js` + `WebSocketService`:
- `KdsSyncService._emit:517` — guarded ✓ (per-callback try/catch).
- `OssSyncService._emit:441` — guarded ✓.
- `PosSyncService` — has **no `_emit` subscriber fan-out**; its only `forEach` (`:209`) iterates *unsubscribers* and is already `try`-wrapped (`:210`). Not a twin.
- `WebSocketService._emit:196` — **already** `try { fn(data) } catch { console.error }`. Not a twin, BUT it logs at `console.error` while KDS/OSS log `console.warn` — the SYNC lane's acceptance ("warn-only acceptable, error=fail") will **false-positive on a healthy WebSocketService isolated throw**. → **ADD**: SYNC-WS3/WS-7 must (a) state the *verdict* "twin-class CLOSED across all 4 fan-outs (file:line each)" as an explicit acceptance line, not leave it as a hunt; (b) treat `WebSocketService`'s `console.error` as the *expected isolated-error log*, not a failure, on the live throwing-listener probe — otherwise the convergence loop chases a non-bug. This is exactly the "lens catches the CLASS not the instance" discipline the campaign claims — but the draft stops at "hunt", never lands the class-closed proof.

**GAP-1 — `PersistKdsOrderRecalledToOutbox` + the recall→409 path is untested by any lane.** The change-set touches the KDS recall surface (`KdsUnreleasedOrderBumpGuardTest`, `KdsOrderRecalled`). WS-3/UX-3/SYNC-WS6 cover bump-survives-notif-fail and unreleased-bump, but **none drives a recall → assert the recall event still projects through the guarded outbox AND the KDS board reflects the recall live**. → ADD a recall leg to SYNC-WS1/UX-3: bump an order, recall it, assert (i) `PersistKdsOrderRecalledToOutbox` fired through the guard, (ii) board shows recall live on a 2nd tab, (iii) recall does not re-allocate/burn a fiscal seq.

**GAP-2 — `EmailQueueResilienceSentinelTest` + `RefundLoyaltyTryCatchHardenedSentinelTest` were MODIFIED (not new) and no lane validates the regression.** These two sentinels changed (`+31/-?`, `+13/-?`). A modified sentinel can be *loosened* to pass. → ADD to WS-0: for every **modified** test file (not just new ones), `git diff 2477ff9fc..f2c12bed7 -- <file>` and assert the change *tightens or holds* the contract (no `markTestSkipped`, no assertion deletion, no `assertTrue(true)` weakening). The draft only counts the headline 3333/0; a silently-weakened sentinel passes that count while covering nothing.

**GAP-3 — `#11 bump-survives-notification-failure` (SendOrderMail event-not-job) is asserted in WS-10/UX-7 prose but has NO dedicated technical anchor.** MEMORY's own lesson: `SendOrderMail` is an **event**, not a `Bus` job — a throwing listener is needed to make it RED. → ADD to WS-2/SYNC-WS6 acceptance: cite the test that injects a throwing mail listener and proves the KDS bump still completes; if no such test exists in the 50 files, that's itself a finding (the heal is claimed but unanchored).

**GAP-4 — `NoRequestTimeEnvSentinel` scans `app/` ONLY.** Verified: it does **not** scan `config/`, blade views (`resources/views`), or frontend JS. The UNI-03 twin lives in app code (caught), but a `config/*.php` that itself calls `env()` at request time when the file is *not* cached, or a blade `{{ env('CURRENCY') }}`, escapes the sentinel. → ADD to WS-7(D)/UX-8: `grep -rn "env(" resources/views config | grep -v "^config/.*=> env(" ` — config files reading env() *at top level* (cache-safe) are fine; a blade or a runtime `env()` call inside a config closure is a UNI-03 sibling the sentinel can't see. State this scope-limit explicitly so the green sentinel isn't over-trusted.

**GAP-5 — Refund/cancel netting fixture is described but its DETERMINISTIC construction is left vague across WS-4/UX-5/SYNC-WS6/WS-8 — four lanes build the SAME fixture four different ways → four non-comparable results.** → ADD a single **canonical netting fixture spec** (one tinker seeder, owned by WS-0, re-used by all): `N` normal PAID orders + 1 PAID order with discount D + delivery-charge C, then 1 partial `RefundWithCounterEntry` mirror of M, + 1 cancelled-but-paid. Record the *expected* net (revenue, order-count, top-item qty, discount, delivery) as constants. Every surface asserts against those frozen constants, not against each other (avoids the vacuous "PDF==card but both wrong" trap UX-Risk-5 flags but doesn't fix).

**GAP-6 — No lane validates `deploy.sh` changed (+16) or the rebuild discipline as an EXECUTABLE gate.** Every lane *mentions* stale-bundle risk but only mitigates by "validate live behavior." → ADD a concrete WS-0 bundle-freshness assertion: `for f in $(git diff --name-only 2477ff9fc..f2c12bed7 -- 'resources/js/**/*.vue' 'resources/js/**/*.js'); do bundle=<mapped public/js>; test "$bundle" -nt "$f" || echo "STALE: $bundle older than $f"; done` plus `lsof -p <serve-pid>` to confirm the :8780 serve cwd. A stale bundle must HALT (rebuild = owner gate), not be "noticed later."

---

## B. VAGUE → CONCRETE (unexecutable workstreams sharpened)

**B-1 — "Force a REAL throw in the outbox projection" (WS-2/SYNC-WS2/WS-3) is the linchpin but the mechanism is hand-wavy ("temporarily make domain_events unwritable / a test double").** On a live :8780 clone you cannot "make a table unwritable" without DDL the access-denied shell user can't run. → CONCRETE: do this in a **Feature test on the clone DB connection**, not via Playwright — register a model event `DomainEvent::creating(fn() => throw new \RuntimeException('outbox-fault'))` inside the test boot, fire `event(new OrderCreated(...))`, assert stock row decremented + `Log::error '[Outbox] … swallowed'` captured via `Log::shouldReceive`. The LIVE browser leg only proves the *happy path* unaffected. Split the fault-injection (test-harness) from the happy-path (live) explicitly — the draft conflates them.

**B-2 — "Concurrent allocation" (WS-6/WS-2) "fire two parallel transitions" has no concrete concurrency primitive.** tinker is single-process. → CONCRETE: two **backgrounded** `php artisan tinker --execute` processes (or an artisan command that spawns 2 `DB::transaction` closures via `pcntl`/parallel curl to two `changePaymentStatus` endpoints with distinct idempotency keys), then assert `SELECT fiscal_sequence_no … ORDER BY id` shows two *consecutive* values, no dup/gap. Note the SHARED-RESOURCE hazard (your own Risks section): serialize this against WS-10's journey on the same clone — add an explicit **fiscal-mutation lane lock** (a sentinel file / branch-window) so the journey doesn't pollute the race repro.

**B-3 — Late-salvage "construct an order in window N-1, close Z, allocate in window N" (WS-1B / WS-5 abuse) — HOW do you cross midnight on a clone without waiting 24h?** → CONCRETE: manipulate `created_at` and `fiscal_seq_allocated_at` directly via tinker (`Order::find(x)->forceFill([...])->saveQuietly()`), and drive `ZReportService::close($from,$to)` with **explicit window bounds** (not "now"). The proof: close Z_{N-1} with `$to = midnight`, set the salvage row's `fiscal_seq_allocated_at` to `midnight + 1min`, close Z_N with `$from = midnight`. Assert the row's `id` appears in Z_N's salvaged collection (`:413-414` range) and NOT in Z_{N-1}. The draft says "via tinker/seed" without naming the time-bound `close()` call that makes it deterministic.

**B-4 — "fiscal:verify-z-membership => 0 at-risk" appears in 3 lanes as the orphan detector, but the BASELINE on a freshly-cloned `foodking_2dot0` is not necessarily 0** (the clone inherits whatever orphans the source DB had). → CONCRETE: WS-0 must *record the baseline at-risk count B0* and every later assertion is "at-risk == B0" (return to baseline), not "== 0". Asserting absolute 0 on an inherited clone will false-RED.

**B-5 — Security timing (WS-5/WS-6/SYNC) correctly says "assert burn present+reachable, not wall-clock."** Sharpen the *reachability* proof: the failure mode is "burn placed but an early `return` precedes it." → CONCRETE: assert via a test that hits the not-found path and verifies `Hash::check` was invoked (spy/mock `Hash::shouldReceive('check')->once()` on the unknown-email path) for BOTH `LoginController` and `KioskMachineLoginController` — a presence-grep can't prove reachability past an early return; a `->once()` expectation on the not-found branch can.

---

## C. ARCHITECTURE / SEQUENCING WEAKNESSES

**C-1 — Playwright contention is the #1 unaddressed architecture risk.** Four lanes each want live :8780 browser tabs (UX-7, SYNC-WS1, WS-8, WS-10 are all "full cross-system journeys" — that's **four near-identical live journeys**). One Playwright MCP, one clone, one fiscal chain = they CANNOT run in parallel without corrupting each other's seq/Z assertions. → FIX: **collapse the four end-to-end journeys into ONE shared live journey run** instrumented to satisfy all four lanes' acceptance simultaneously (TECH cares about seq, SYNC cares about dispatched_at/latency, UX cares about screenshots, ADV cares about injected-throw survival). Designate it the **integration spine**, run it ONCE per round, sequentially, owned by one lane; the other three *consume its evidence*. This is the minimal-path-to-maximal-coverage the draft asks for but doesn't architect.

**C-2 — Code-level vs live-browser split is not declared, so everything implicitly wants the browser.** → FIX: partition explicitly:
- **Phase 1 (parallel, no browser, no contention):** all PHPUnit/Vitest suites, all `grep` twin-sweeps, all fault-injection Feature tests (B-1), the netting-fixture seeder + per-surface *service-layer* assertions (DashboardService/ZReportService called directly in tinker), the concurrency race (B-2). These have ZERO Playwright dependency and parallelize freely across agents.
- **Phase 2 (sequential, single browser, single clone):** the ONE integration spine (C-1) + the per-surface *visual* Reads (stock-rupture 4 viewports, allergen badge, KDS overflow, OSS empty-state, PDF parity). Soketi/worker liveness is a *precondition* checked once.
- Convergence loop re-runs Phase 1 fully + Phase 2 only on surfaces touched by a heal.

**C-3 — Worker/soketi precondition is checked per-lane (duplicated, race-prone).** → FIX: a single **WS-0 harness-health gate** owns it: start the clone's OWN `queue:work redis --queue=high,default`, capture PID, `lsof -p <pid>` to assert cwd==foodking_2dot0 worktree AND `DB`==foodking_2dot0, `redis-cli ping`, `lsof -iTCP:6001 -sTCP:LISTEN`. Every lane *reads* this gate's PID file; no lane re-pgreps (the documented db5 collision footgun). If the gate is RED, NULL `dispatched_at` is harness-fault, **not** a code finding — bake this attribution rule into the loop so a flaky worker never resets the convergence counter falsely.

**C-4 — The four lanes have OVERLAPPING workstreams with no de-dup → the same finding gets raised 4× and the "identical finding-set" convergence rule becomes ambiguous (is it 4 findings or 1?).** → FIX: a **shared finding ledger** keyed by `(heal-id, file:line, repro-hash)`. A finding is one entry regardless of how many lanes hit it. Convergence compares the *deduped ledger* round-over-round. Without this, two lanes independently re-deriving the same twin will look like "set changed" and reset the counter forever.

**C-5 — Ordering dependency missing: WS-1 (fiscal) and WS-8/WS-4 (netting) both mutate the clone's Z and seq; if netting runs first and closes a Z, the late-salvage window test inherits a polluted Z set.** → FIX: declare a strict intra-round order for *mutating* fiscal work: (1) baseline snapshot → (2) netting fixture + read-side assertions (no Z close needed — DashboardService reads live) → (3) fiscal exhaustivity incl. the controlled Z opens/closes (LAST, because it closes Zs) → (4) re-snapshot, assert prior Zs byte-identical. The draft lists workstreams but never orders the Z-mutating ones.

---

## D. SEVERITY GATES + CONVERGENCE RULE — make them airtight

The draft's convergence rule ("2 consecutive clean rounds, P0+P1=0, identical set") is correct in spirit but has **three loopholes** to close:

**D-1 — "Identical set" is undefined when the set is EMPTY.** Two consecutive *empty* sets trivially satisfy "identical." → TIGHTEN: convergence = **2 consecutive rounds where the deduped ledger is EMPTY at P0+P1 AND no heal was applied between them AND every WS-0 gate (suites, chain, frozen, harness-health, bundle-freshness) is GREEN in both.** A heal applied in round N *forces* round N+1 and N+2 to be fresh clean rounds (the counter resets on ANY code change — the draft says this for findings but not for *heals*, which is the actual reset trigger).

**D-2 — Severity inheritance for twins is stated but not gated.** A missed twin of a P0 (NF525/food-safety) is itself P0. → ADD an explicit gate row: "any confirmed twin inherits its parent heal's severity; an *unresolved* P0/P1 twin = NO-GO identical to a primary."

**D-3 — Frozen-diff gate has no teeth for the NON-fiscal frozen files.** WS-0 checks ZReportService==authorized hash, but the *other 13 frozen files* are only checked by "the sentinel is green." → ADD: WS-0 must assert all 13 non-ZReportService frozen hashes are **byte-identical to the 2477ff9fc base** (not just "sentinel green" — the sentinel could itself have been rebaselined). Concretely: `git diff 2477ff9fc..f2c12bed7 -- <13 frozen paths excluding ZReportService>` returns EMPTY. (I verified pos-wizard.js, PaymentComponent, FiscalSequenceService etc. are unchanged in the file list — but the *gate* must enforce it mechanically, not trust my read.)

**Severity gate table to ADD (currently scattered in prose):**
| Class | Severity | Gate |
|---|---|---|
| NF525 seq escape / gap / double-count / chain break / Z mutated | **P0** | hard NO-GO, abort round |
| Food-safety allergen false-negative (case/double-decode) | **P0** | hard NO-GO |
| Outbox throw halts stock/loyalty/receipt; POS 500 on PAID | **P0** | hard NO-GO |
| Off-book COD settlement / unmanned dispatch reaching OFD | **P1** | NO-GO until healed |
| Sync board freeze for innocent subscriber; un-netted money surface; auth enum oracle (status/body); seq=NULL realized-PAID flip | **P1** | NO-GO |
| Missed twin of any P0/P1 | **inherits parent** | as above |
| Visual: raw label / truncation / 0undefined / blank empty-state on a live surface | **P1** | NO-GO (visual mandate is the gate) |
| Timing-only oracle measurable but burn present+reachable | **P2** | document, not gate (CI-flaky) |
| Observability shows green while soketi down | **P2** | document |
| Stale bundle detected | **HALT** (owner-gated rebuild) | not a code finding |

---

## E. SMALLER SHARPENINGS (high-signal, low-words)

- **WS-1(C) "admin changePaymentStatus→PAID on a seq=NULL order via /admin path"** — name the route + the idempotency-key requirement, else a double-POST during the test itself muddies the seq assertion. Reuse the WS-6 idempotency harness.
- **UX-5/WS-4 PDF Read** — assert the PDF is *regenerated from the heal'd `sales_report.blade.php:120-148`*, not a cached PDF on disk. Add: delete any prior export artifact before the export step.
- **SYNC-WS5(a) EventType::all() completeness** — make it a *test assertion* not a manual cross-map: `foreach(glob Persist*ToOutbox) extract the EventType const referenced, assert in_array(const, EventType::all())`. The MEMORY footgun (const missing from all() → silent skip) deserves a codified sentinel, not a one-time grep.
- **G-DELIV-CASH (WS-7/UX-6)** — assert the audit signal is **countable** (a queryable `AuditLogService` action `cash.delivery.unattributed_collection`), not just "visible in UI." A screenshot proves UI; the owner needs the row for reconcile. Both, not either.
- **A-012b (UX-1)** — the draft tests 768/390; ADD the **~191px** width the ADV lane (WS-9e) cites as the real split-pane regression viewport. The two lanes disagree on the critical width (768 vs 191) — reconcile to test BOTH and assert `grid-template-columns` resolves to fewer columns (not starved names) at the narrowest.
- **Convergence honesty anchor** — bake in the campaign's own scar (MEMORY: "2nd cycle changed the set → convergence NOT revendiquée"): the loop must log the ledger diff each round so a silently-changed set can't be hand-waved into "converged."

---

## F. ONE-LINE VERDICT ON THE DRAFT

Architecturally sound and unusually rigorous on the fiscal crown-jewel; the real weaknesses are **(1) four redundant live journeys that will contend on one browser/clone/chain → collapse to one instrumented spine; (2) the twin-class left "open as a hunt" instead of closed with a 4-fan-out verdict (PosSync/WebSocketService resolved here); (3) no deduped finding-ledger so "identical set" convergence is ambiguous; (4) fault-injection mechanism hand-wavy → must be a Feature-test model-event throw, not browser; (5) baseline-relative assertions (z-membership, Z set) instead of absolute-0 on an inherited clone.** Add the WS-0 bundle-freshness + harness-health + 13-frozen-byte-identical gates with teeth, the canonical netting fixture, and the Phase1(parallel,no-browser)/Phase2(sequential,one-spine) split, and this converges on the minimal path.