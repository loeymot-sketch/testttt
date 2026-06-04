# ULTRA REVIEW — Unified Plan Adversarial Challenge (2026-05-21)

**Reviewer** : independent Ultra-Review agent (READ-ONLY, max-memory)
**Branch** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `1116b3957`
**Source under review** :
- `reports/reconciliation-unified-2026-05-21.md` (unified plan, 23 UNI items, 10 batches)
- `reports/audit-verify-other-session-2026-05-21.md` (verify of Audit A)
- `reports/audit-verify-wave-polish-2026-05-21.md` (verify of Audit B)

**Method** : every load-bearing claim in the reconciliation was re-tested against (a) file:line evidence at current HEAD, (b) live PHPUnit run, (c) bundle vs source mtime, (d) git status working-tree state.

**Bottom line** : the reconciliation is **directionally correct and 80% trustworthy**, but **batch 1 as proposed (UNI-01 + UNI-02 + UNI-04) is unsafe** and contains two material errors. See §A below.

---

## A. Sequencing verdict — SPLIT BATCH 1

**Verdict on proposed batch 1 (UNI-01 + UNI-02 + UNI-04)** : **SPLIT REQUIRED — do not ship as one batch.**

### Reason 1 — UNI-01 has a false premise

The reconciliation framed UNI-01 as "Re-NOOP 2 still-failing sentinel methods OR add `@group manual` to 2 untagged methods" (`reports/reconciliation-unified-2026-05-21.md:133`).

**Primary-source evidence — all 4 methods ARE already `@group manual`** :
- `tests/Feature/Sentinels/AllergenCoverageSentinelTest.php:143` → `@group manual` on `coverage_meets_eu_1169_minimum_threshold`
- `tests/Feature/Sentinels/AllergenCoverageSentinelTest.php:178` → `@group manual` on `required_allergens_are_set_per_signature_item`
- `tests/Feature/Sentinels/AllergenCoverageSentinelTest.php:207` → `@group manual` on `explicitly_none_items_are_empty_array_not_null`
- `tests/Feature/Sentinels/AllergenCoverageSentinelTest.php:231` → `@group manual` on `ssot_cache_consistency_holds_post_seed`

Live `php artisan test --filter=AllergenCoverageSentinelTest` → **2 failed / 2 passed**.

The 2 failing methods (`coverage_meets_eu_1169_minimum_threshold` + `required_allergens_are_set_per_signature_item`) are ALREADY annotated — they fail because `phpunit.xml` lacks the `<groups><exclude>` block, NOT because they're missing annotation. **UNI-01 (a)** = no-op (annotations already there). **UNI-01 (b)** "re-NOOP the test body" = destroys the FIC-compliance acceptance criteria preserved as documentation per the test's own docblock (lines 33-47 of the test file explicitly says re-enable path requires keeping the assertion logic).

**Correct fix** : **UNI-02 alone is sufficient**. UNI-01 collapses into UNI-02. Adding the phpunit.xml `<exclude>` block makes the 4 `@group manual` annotations functional and CI goes green.

### Reason 2 — UNI-04 INVERTS an existing sentinel invariant

The reconciliation framed UNI-04 (`OutboxRescueCommand.php:47` widen `< 5` → `< 12`) as "1 LOC + test" (`reports/reconciliation-unified-2026-05-21.md:136`).

**Primary-source evidence — there is ALREADY a test that asserts the OPPOSITE invariant** :
- `tests/Feature/Outbox/OutboxRescueStaleClaimedRowsTest.php:114-127` — `test_rescue_skips_crash_claimed_row_past_attempts_cap` seeds `attempts=5` and asserts `Bus::assertNotDispatched(DispatchDomainEventsJob::class)`.
- Test docblock at lines 110-112 verbatim : *"Cap — `attempts>=5` rows are out of the rescue scope; they belong to the RetryFailed lane (hourly, scope `failed(5)`). Picking them here would step on RetryFailed's audit-write contract."*

Widening `< 5` → `< 12` directly contradicts this encoded invariant. Cost is NOT "1 LOC + sentinel" but :
1. Rewrite `test_rescue_skips_crash_claimed_row_past_attempts_cap` to assert NEW boundary (now `attempts>=12`).
2. Update the docblock (which encodes the contract reasoning).
3. **Cross-check the race risk with `OutboxRetryFailedCommand`** : `failed(5)` scope means RetryFailed picks rows with `attempts>=5` AND `dispatched_at IS NULL` (verified via `app/Console/Commands/OutboxRetryFailedCommand.php:104` `<` `REPLAY_MAX_ATTEMPTS=12`). The widened rescue lane would now overlap (attempts 5-11, crash-claimed) — does the overlap collide with `OutboxRetryFailedCommand`'s audit-write contract under concurrent cron? **The reconciliation did not analyze this.** This is the exact "step on RetryFailed's audit-write contract" risk the existing test docblock warns about.
4. **Worst-case if widened**: a row with `attempts=8` and a genuinely poison broadcast (Pusher misconfigured, payload malformed) would now get re-queued by rescue every 10 min in addition to RetryFailed's hourly retry. 12-attempts ceiling holds, but the row reaches it faster, hits `pruneOutbox` after 90d still — so not infinite retry. The real risk is **double-broadcast amplification** (one rescue + one RetryFailed both fire the same row in the same cron tick if their schedules drift).

**Verdict on UNI-04** : **not 1-LOC + sentinel — it's a 2-phase change** : (a) reason whether the rescue/RetryFailed lane separation can be safely overlapped, document the new contract, (b) implement + update existing test + add new boundary test. **Estimated effort = 30-60 min orchestrated, not 5 min.** Should NOT be in same batch as UNI-02.

### Reason 3 — owner mandate verbatim "small simple functions one by one, NOT structural, NOT payment"

- UNI-02 = phpunit.xml config 3 LOC → fits.
- UNI-04 = Outbox sync-critical 1-LOC code + test rewrite + cross-cutting overlap analysis → **structural**, not "simple function".

**Honoring owner mandate strictly = SPLIT.**

### Recommended new sequencing

| Batch | UNI | Reason | Effort | Owner verify |
|---|---|---|---|---|
| **1a (ship now)** | UNI-02 | Single 3-LOC phpunit.xml edit → CI green immediately | 5 min | `php artisan test --filter=AllergenCoverageSentinelTest` → 0 tests run or all skipped |
| **1b (next batch)** | UNI-05 + UNI-07 + UNI-16 | EN catalog Cash Overview cluster — pure data adds, visible UX fix, zero code risk | 30 min | Visit `/admin/cash-overview?lang=en`, observe no raw `label.X` |
| **1c (separate batch with owner gate)** | UNI-04 | Outbox lane widening — requires reasoning about race with RetryFailed lane, NOT a 1-LOC change | 45-60 min | Run full Outbox test suite, document race-window analysis, owner reviews |
| **defer** | UNI-03 | Cache guard widen — cloud-only impact, owner mandate "no cloud talk until owner initiates" | — | Defer until owner triggers cloud cutover prep |

---

## B. Items added (NEW-NEW — missed by all 3 prior reports)

### B.1 — Working-tree bundle drift (10 `public/js/*.js` files dirty)

**Evidence** — `git status --porcelain public/js/` :
```
 M public/js/admin-kds.js
 M public/js/admin-oss.js
 M public/js/admin-shell.js
 M public/js/kiosk-errors.js
 M public/js/kiosk-shell.js
 M public/js/kiosk-wizard-step.js
 M public/js/kiosk-wizard.js
 M public/js/pos-app.js
 M public/js/pos-shell.js
 M public/js/vendor.js
?? public/js/admin-reports.js.LICENSE.txt
```

- `git diff --stat HEAD -- public/js/` shows `vendor.js | 35632 ++++++++…` — a 35,632-line vendor.js diff sitting in the working tree, uncommitted.
- The reconciliation states "frozen-zone diff = 0" and treats the tree as clean. But ANY owner manual-test session loads whichever bundle is on disk, not whichever is committed. **This is a non-reproducibility risk** — if owner reports "I tested X and it works", which bundle was loaded ? committed HEAD or working-tree ?

**Severity** : **P1 — discipline/repro risk**, not a code defect per se.

**Recommendation** : before running any owner manual-test, either (a) commit the 10 bundles with `chore(bundles): commit working-tree bundle drift post Wave X-N`, or (b) `git checkout -- public/js/` to revert. Owner-gated decision (these bundles are downstream of source changes Claude made or that landed in side branches — a npm run build vs revert is a real choice).

### B.2 — `PosShortcutOrderResource.php` DOES exist (cartography drift refinement)

**Evidence** — `find /Users/.../app -name "PosShortcutOrder*.php"` :
```
app/Http/Resources/PosShortcutOrderResource.php
```

The verify-other-session report (Claim 5) says "`PosShortcutOrderController` does NOT exist" — true. But it didn't grep for `PosShortcutOrder*` more broadly. The Resource exists; the Controller doesn't. The shortcut-order surface is real, served via `PosController` + inline closures. Cartography drift = naming mismatch, not full hallucination.

**Severity** : **P3 — cartography hygiene**. UNI-10's framing should be **"remove fictional Controller reference; resource is real, point to actual route handlers"**, not "controller doesn't exist therefore feature doesn't exist".

### B.3 — Architectural risk: PHP-vs-JSON catalog split is NOT addressed

The verify-wave-polish report flagged (page 16) : *"the `lang/en/all.php` + `lang/fr/all.php` `delivery_cash_*` fix targets the **Blade / Laravel PHP catalog** — used by Blade templates and `__('all.label.…')` calls. It does **not** propagate to the Vue runtime, which reads `resources/js/languages/*.json`."*

This is a **dual-catalog architectural risk** that the reconciliation glossed over. The unified plan UNI-05/07/08/20 only adds keys to `resources/js/languages/*.json` (Vue side). It does NOT enforce parity with `lang/{fr,en,ar}/all.php`. **Silent drift between the two catalogs will recur** — there is no CI sentinel.

**Recommendation** : **add a new UNI-24** : sentinel test that asserts every `label.*` key in `resources/js/languages/fr.json` exists in EN+AR with non-empty values, AND a separate sentinel that asserts `lang/*/all.php` and `resources/js/languages/*.json` don't conflict on common keys. **Effort** : 1-2h. **Severity** : P2 — prevents future Wave-Polish-like drift.

### B.4 — Outbox lane race between UNI-04 widen and RetryFailed `failed(5)` scope

(Already covered in §A Reason 2.) **The reconciliation did not analyze the race window.** This is a structural concern that requires reasoning before UNI-04 can be safely shipped, not a 1-LOC heal.

### B.5 — Audit-verify-other-session Claim 8c is OBSOLETE

**Evidence** :
- `stat -f "%m" /Users/.../public/js/admin-kds.js` = 1779335526 (May 21 05:52:06)
- `stat -f "%m" /Users/.../resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue` = 1779334153 (earlier)
- `grep -nE "_onEsc|key === 'Escape'" /Users/.../public/js/admin-kds.js` → matches at lines 199, 200, 204, 207, 208
- `git log --oneline public/js/admin-kds.js` → commit `f0060a138 fix(wave-x-B round-1): … rebuild stale KDS bundle …`

The bundle WAS rebuilt and IS committed. Audit-verify-other-session Claim 8c "bundle stale" reads a state that no longer exists. The reconciliation should not carry this forward as open. **One less surface to worry about.**

---

## C. Items to KILL (outright drop from unified plan)

### C.1 — UNI-01 — KILL (collapse into UNI-02)

As shown in §A Reason 1, the framing is based on a false premise (all 4 methods already `@group manual`). The actual fix = UNI-02 alone. UNI-01 should be deleted from the plan.

### C.2 — UNI-03 — DEFER, not kill, but explicitly out of V1 LOCAL scope

The reconciliation rates UNI-03 (cache driver guard widen) as P0 with HIGH cloud risk. Verify-other-session correctly downgrades this to **P2 cloud-prep**. Owner mandate per `feedback_no_cloud_until_owner_initiates.md` is verbatim *"NE PAS proposer/évoquer cloud actions tant qu'owner ne dit pas explicitement go production"*.

**Verdict** : do not ship in current V1 LOCAL cycle. Park as `V1.0.X-cloud-prep` backlog. Avoid even mentioning it in owner's next decision unless owner asks about cloud.

### C.3 — UNI-04 — DOWNGRADE from P1 to "needs design conversation"

Per §A Reason 2. Not a 1-LOC change. Needs race-window analysis with RetryFailed. **If owner mandate is "small functions one by one no structural", UNI-04 is structural-adjacent.** Kill from current batch; document for V1.0.X with required design work.

### C.4 — UNI-19 — KILL or DOWNGRADE to P3

The verify-wave-polish report (Item 6) explicitly corrected the WCAG rule: this is **WCAG 1.4.11 (Non-text Contrast 3:1)**, not the AA-text 4.5:1 rule. Default `#888` ≈ 3.0:1 — borderline. Only orange `#fb8c00` ≈ 2.4:1 truly fails. The reconciliation still rates this P2 with same effort. **Real scope** is "change orange to one shade darker" — 1 CSS line, P3 polish. Should be bundled into a "5-minute polish" stack, not its own item.

### C.5 — UNI-20 (AR catalog batch) — owner-gated as written, KEEP but flag risk

The reconciliation flags this as needing native-speaker review. The risk goes further: pushing agent-translated AR into production without owner native-speaker sign-off is the kind of "fabricated compliance" pattern Wave Q-4 retracted on allergens. **Owner should explicitly gate this batch with "I have native-speaker review" before any commit, even if agent-translation drafts the keys.**

---

## D. Risk assessment — independent rating per item

Note: my rating below is independent of the reconciliation's rating. Numbers in parens = reconciliation's rating for diff.

| UNI | My severity | My LOCAL risk | My CLOUD risk | Δ vs reconciliation |
|---|---|---|---|---|
| UNI-01 | **KILL** | — | — | (recon P0 → my KILL) |
| UNI-02 | P0 CI | NONE | NONE | (same) |
| UNI-03 | P2 cloud-only | NONE | MEDIUM | (recon P0 HIGH → my P2 MEDIUM; verify-other-session agrees) |
| UNI-04 | P1 but needs design | LOW | LOW-MEDIUM | (recon "1 LOC" → my "30-60 min + race analysis") |
| UNI-05 | P1 polish | NONE | NONE | (same) |
| UNI-06 | P1 i18n | NONE | NONE | (same) |
| UNI-07 | P1 UX | NONE | NONE | (same) |
| UNI-08 | P2 i18n | NONE | NONE | (same) |
| UNI-09 | P3 doc | NONE | NONE | (same) |
| UNI-10 | P3 doc — needs B.2 refinement | NONE | NONE | (same; reword needed) |
| UNI-11 | P1 a11y | LOW | LOW | (same) |
| UNI-12 | P1 a11y | LOW (cross-callsite testid risk) | LOW | (recon underestimates Vitest re-run cost) |
| UNI-13 | P1 i18n | LOW (testid drift) | LOW | (same) |
| UNI-14 | P2 polish | NONE | NONE | (same) |
| UNI-15 | P2 UX | LOW | LOW | (same) |
| UNI-16 | P2 UX (already bundled) | NONE | NONE | (same) |
| UNI-17 | P2 mobile | NONE | NONE | (same) |
| UNI-18 | P2 a11y | NONE | NONE | (same) |
| UNI-19 | P3 (downgraded) | NONE | NONE | (recon P2 → my P3) |
| UNI-20 | P2 owner-gated | NONE | LOW | (recon "agent translation" risk acknowledged) |
| UNI-21 | P2 ops | LOW (restore drill) | LOW | (same) |
| UNI-22 | DEFER | NONE | HIGH | (same, cloud-only) |
| UNI-23 | DEFER | NONE | HIGH | (same, cloud-only) |
| **NEW: UNI-24 — i18n catalog parity sentinel** | P2 prevention | NONE | NONE | NEW |
| **NEW: UNI-25 — commit-or-revert bundle drift** | P1 repro | LOW (CI passes either way) | LOW | NEW |

---

## E. Manual verify checklist per batch

### Batch 1a — UNI-02 (CI unred)

| Step | Command / Action | Expected |
|---|---|---|
| 1 | Apply 3-LOC edit to `phpunit.xml` (add `<groups><exclude><group>manual</group></exclude></groups>` between `</testsuites>` and `<coverage>`) | File saved |
| 2 | `php artisan test --filter=AllergenCoverageSentinelTest` | "0 tests" OR "No tests executed" (the 4 manual-tagged tests are now excluded) |
| 3 | `php artisan test` (full suite, ~5 min) | All previously-green tests still green, no new failures |
| 4 | `git diff phpunit.xml` | Single hunk, 3-5 LOC added |
| **Owner UX verify** | None — invisible config change | — |
| **Risk** | NONE | — |

### Batch 1b — UNI-05 + UNI-07 + UNI-16 (Cash Overview EN pillar)

| Step | Command / Action | Expected |
|---|---|---|
| 1 | Edit `resources/js/languages/en.json` to add 20+2 missing keys (per reconciliation list) | File saved |
| 2 | Fix `en.json:1103` `label.received_amount` value from `"Montant reçu"` → `"Amount received"` | File saved |
| 3 | `npm run dev` OR `npx mix` (rebuild Vue bundle) | New bundle |
| 4 | Owner visits `http://127.0.0.1:8000/admin/cash-overview` with locale=EN | Sees "Amount received", "Grand total", "Cash drawer reconciliation", etc., not raw `label.received_amount` |
| 5 | Owner visits FR view | No regression, FR labels unchanged |
| **Risk** | NONE — pure data additions | — |

### Batch 1c (separate batch, NOT with 1a/1b) — UNI-04 design conversation

**Pre-implementation step** : write a 1-page analysis document covering :
1. The 3 cron-lane scopes : `OutboxRescueCommand` (current `<5`), `OutboxRetryFailedCommand` (`<12`), `PruneOutboxCommand` (90d + `attempts>=6`).
2. Under proposed widening (`<12`), what overlap exists in rows with `attempts ∈ [5, 11]` and `dispatched_at IS NULL` — both rescue and RetryFailed pick these.
3. RetryFailed schedule (`hourly`) vs rescue schedule (verify in `app/Console/Kernel.php`) — collision tick probability.
4. `lockForUpdate` semantics : does it serialize, or do both jobs claim the same row before one wins ?
5. The existing test `test_rescue_skips_crash_claimed_row_past_attempts_cap` invariant — what new invariant replaces it ?

**Then** : owner reviews the design doc, decides ship/defer. If ship, the implementation is `< 5 → < 12` + test rewrite. If defer, doc lives in `V1.0.X-backlog`.

**Manual verify** : NONE before design doc is owner-approved.

### Batch 2 (UNI-11 + UNI-12 + UNI-18 + UNI-19 — a11y cluster)

| Step | Action | Expected |
|---|---|---|
| 1 | Implement Escape handler on `PosCounterCollectModal.vue` mirroring `KdsHistoryDrawer.vue:189-201` | Code added |
| 2 | Run Vitest filter on POS + KDS specs | All green |
| 3 | Open `/admin/pos` with `?show-counter-collect=true` (or natural flow) | Modal opens |
| 4 | Press `Escape` | Modal closes; focus returns to trigger button (visible yellow ring) |
| 5 | Open KDS history drawer, press Escape | Drawer closes; focus returns to trigger button |
| 6 | (UNI-19) Verify `#888` border on KDS history items is darker shade (`#666` or `#555`) | Visual: borders look slightly darker grey |
| **Risk** | LOW (cross-callsite Vitest re-run) | — |

### BRAIN updates (Batch 7 in reconciliation)

| Step | Action |
|---|---|
| 1 | §2 — replace "PRODUCTION-READY" with "PRODUCTION-READY at RUNTIME ; CI green after UNI-02 lands" |
| 2 | §1 — update FormRequest baseline `69` → `66` |
| 3 | §2 — prune accumulated "PRIOR START HERE" entries to current + 1 |
| 4 | §2 — re-word Wave Q-4 retraction line to clarify "annotation added; CI gate functional after phpunit.xml fix UNI-02" |
| 5 | CLAUDE.md §8 — clarify the cache-driver guard scope (`array`/`null` forbidden ; `file`/`database` flagged for cloud-prep V1.0.X) |

---

## F. BRAIN update plan

The reconciliation's §5 BRAIN audit is **directionally correct**. My additions :

### F.1 — Add a new BRAIN §2 entry post-UNI-02 ship

```
- 🆕 START HERE 2026-05-21 EVENING — UNI-02 PHPUNIT.XML EXCLUDE BLOCK SHIPPED ✅
  Single 3-LOC edit closes the Wave Q-4 retraction incomplete-heal.
  AllergenCoverageSentinelTest 4 methods now actually skip in CI (the
  @group manual annotations were present since c28f7a452 but the phpunit.xml
  <groups><exclude> block was missing → annotations were decorative).
  Live verify : `php artisan test --filter=AllergenCoverage` → 0 tests run.
  Full suite re-green. NF525 chain unchanged. Frozen-zone diff = 0. No code
  side-effects — pure config.
```

### F.2 — DO NOT update BRAIN until UNI-02 actually lands

The current §2 headline "PRODUCTION-READY" is overstated per the reconciliation. **The fix is UNI-02 + a BRAIN edit, both at once.** If we edit BRAIN before UNI-02 lands, we create more drift. If we ship UNI-02 without updating BRAIN, the next session will re-discover the contradiction. **Bundle both into one commit.**

### F.3 — Add prevention sentinel mention

After UNI-24 (i18n catalog parity sentinel) lands, add a CLAUDE.md §13 Evidence Rules line :
*"i18n parity gates enforce that label.* keys exist across fr/en/ar before merge — prevents Cash-Overview-style runtime label leaks."*

---

## G. Final go/no-go for batch 1

### Verdict : **NO-GO for batch as proposed (UNI-01 + UNI-02 + UNI-04).** **GO for revised batch 1a (UNI-02 alone).**

### Justification

1. **UNI-01 is a no-op** : all 4 methods already `@group manual`. The proposed "(b) re-NOOP" would destroy preserved FIC-compliance documentation per the test's own docblock. The proposed "(a) annotate the 2 untagged methods" cannot be done — there are no 2 untagged methods. Collapse into UNI-02.

2. **UNI-02 is the actual fix** : 3-LOC `phpunit.xml` edit. NONE risk. Verify in 5 min. Owner verify = `php artisan test --filter=AllergenCoverageSentinelTest` returning "0 tests" or skip output. **This is the one true heal for the CI red state.**

3. **UNI-04 is structural-adjacent**, not "1-LOC + sentinel". The existing test `OutboxRescueStaleClaimedRowsTest::test_rescue_skips_crash_claimed_row_past_attempts_cap` encodes the opposite invariant. Widening `<5` → `<12` inverts the cap and overlaps with `OutboxRetryFailedCommand`'s `failed(5)` scope without race-window analysis. Owner mandate "no structural" → UNI-04 stays out of current batch.

4. **Working-tree bundle drift (B.1) MUST be resolved before owner manual test** — repro risk. This is the highest practical priority right now and the reconciliation missed it.

### Recommended ship-now sequence

| Order | Item | Time | Reason |
|---:|---|---|---|
| 1 | **UNI-25 (NEW)** Commit-or-revert the 10 dirty bundles in `public/js/*` | 5-15 min depending on owner choice (commit vs revert) | Repro discipline. Owner manual test loads bundles from disk. |
| 2 | **UNI-02 alone** (3-LOC phpunit.xml + same-commit BRAIN §2 update) | 10 min | CI green + memory truth. Single commit. |
| 3 | **Batch 1b** UNI-05 + UNI-07 + UNI-16 — Cash Overview EN pillar | 30 min | Visible owner UX win, zero risk, pure data |
| 4 | (owner-gated) UNI-04 design-doc-first conversation | 45 min then owner decide | Structural-adjacent — design before code |
| 5 | (owner-gated) Subsequent batches per reconciliation §4 with corrections in this review | per batch | Continue with mandate discipline |

---

## Summary back (≤500 words)

### 1. First-batch verdict
**SPLIT.** Proposed batch (UNI-01 + UNI-02 + UNI-04) is unsafe.
- UNI-01 has a false premise (all 4 sentinel methods are ALREADY `@group manual` per `AllergenCoverageSentinelTest.php:143,178,207,231` — collapse into UNI-02).
- UNI-02 alone is the actual heal (3-LOC `phpunit.xml` `<groups><exclude>` block).
- UNI-04 is NOT a 1-LOC change — it inverts the existing invariant in `OutboxRescueStaleClaimedRowsTest.php:114` (`test_rescue_skips_crash_claimed_row_past_attempts_cap` asserts attempts≥5 must NOT be rescued — its docblock at lines 110-112 explicitly says picking them "would step on RetryFailed's audit-write contract"). Widening `<5` → `<12` overlaps with `OutboxRetryFailedCommand`'s `failed(5)` scope without race-window analysis. **Needs design doc before code.** Ship UNI-02 alone as batch 1a (15-min CI green), then Batch 1b Cash Overview EN pillar (UNI-05 + UNI-07 + UNI-16), then design-then-ship UNI-04 as its own batch with owner gate.

### 2. Top 3 items missed by all 3 prior agents
1. **Working-tree bundle drift** — `git status` shows 10 `public/js/*.js` files dirty, including `vendor.js +35,632 lines uncommitted`. Owner manual-test loads whichever bundle is on disk → non-reproducibility risk. Commit-or-revert decision is owner-gated.
2. **UNI-04 inverts an existing test invariant** — the reconciliation framed it as 1-LOC; the existing test at `OutboxRescueStaleClaimedRowsTest.php:114` explicitly contradicts the proposed change with docblock warning about RetryFailed lane collision.
3. **Dual-catalog architectural risk** — adding keys to `resources/js/languages/*.json` doesn't enforce parity with `lang/{fr,en,ar}/all.php` (Blade catalog). Wave-Polish-like drift will recur without a parity sentinel (proposed NEW: UNI-24).

### 3. Top 3 items to KILL
1. **UNI-01** — collapse into UNI-02 (false premise: all 4 methods already `@group manual`).
2. **UNI-03** — owner mandate "no cloud talk until owner initiates"; defer to V1.0.X-cloud-prep, do not raise in current cycle.
3. **UNI-19** — WCAG 1.4.11 borderline (not the AA-text 1.4.3 rule the recon implies); real scope is one CSS line for the orange shade. P3 polish.

### 4. Go/no-go on UNI-01 + UNI-02 + UNI-04 batch
**NO-GO.** Ship **UNI-02 alone** as revised batch 1a (3 LOC, 15 min total including BRAIN update in same commit).

### 5. Most-critical owner manual-verify step
**Before any further work** : owner decides commit-or-revert on the 10 dirty bundles in `public/js/`. Without that, every "verify it works" result is non-reproducible because the runtime bundle ≠ committed HEAD.

### 6. Single biggest hidden risk to V1 LOCAL
**Working-tree bundle drift** (B.1). All session memory says "frozen-zone diff = 0" and "production-ready" — but the working-tree has ~35k uncommitted lines in `vendor.js` and 9 other bundles. Any "I tested X and it works" claim today is tied to whichever bundle is on disk, not to the commit graph. **This is exactly the kind of silent-state risk CLAUDE.md §12 anti-drift rules guard against.** Owner-gated decision needed before next manual-test session.

---

**End of ULTRA REVIEW.**
