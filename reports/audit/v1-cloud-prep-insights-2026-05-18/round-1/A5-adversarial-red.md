# A5 — Adversarial RED audit (V1 Cloud-Prep, round-1)

**Branch** : `v1-0-1-hardening-2026-05-17`
**Committed HEAD** : `1235e3e1a` (Wave 5I)
**Working tree** : 22 modified files + 9 untracked code/config/test files
**Methodology** : distrust the CONVERGENCE_FINAL "GO ABSOLUTE" verdict. Find what the owner missed OR regressed.
**Date** : 2026-05-18
**Author axis** : A5 RED-team hostile auditor

---

## Headline

The CONVERGENCE_FINAL doc says **GO ABSOLUTE for Phase D cloud deploy**. Reality on disk says the merge ships a **half-finished `simulation_hardware` feature** whose code paths, config file, dependent test, and 5 fixture updates **all live in the working tree only, never committed**. Best case at deploy: `config('pos.simulation_hardware')` returns `null` → bypass inactive → safe. Worst case: dev `.env` (`POS_SIMULATION_HARDWARE=true`) leaks to prod via copy-paste → **NF525 cash-trail bypass active in production**. There is no middle path that ships cleanly.

**Verdict** : NO-GO until owner commits the full simulation_hardware bundle OR `git restore`s it and ships from `1235e3e1a` clean. Merging HEAD without resolving the working tree is malpractice.

---

## P0 NEW findings (in severity order)

### P0-A5-#1 — `simulation_hardware` bypass entirely uncommitted (NF525 deploy deathtrap)

**Severity** : P0 (NF525 risk + ship-blocker for `git stash`/CI-fresh-clone)

The `simulation_hardware` flag plumbing exists in 4 disconnected layers, none of which are committed:

| Layer | Path | Status | Evidence |
|---|---|---|---|
| Config file | `config/pos.php` (lines 1-39) | **UNTRACKED** | `git ls-files --error-unmatch config/pos.php` → "did not match" |
| Controller bypass | `app/Http/Controllers/Admin/PosController.php:92-97` | **MODIFIED unstaged** | `if (config('pos.simulation_hardware') === true) { return; }` |
| Service bypass | `app/Services/PaymentService.php:278-283` | **MODIFIED unstaged** | downgrades `$strict → false` when sim flag on |
| Split-pay bypass | `app/Services/Payments/SplitPaymentService.php:200-210` | **MODIFIED unstaged** | `$simulating = config(…); if ($hasCashTranche && ! $simulating)` |
| Sentinel test | `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` | **UNTRACKED** | new file 9.7 kB, asserts 4 payment scenarios with sim ON |

**Failure modes**

1. **`git stash` or fresh clone** : `config/pos.php` evaporates. `config('pos.simulation_hardware')` returns `null`. Two of the three bypass sites use `=== true` (PosController, SplitPaymentService) so they fall through safely, but `PaymentService:280` uses `if ($strict && config(...) === true)` so also safe. Result: dev environment that relied on this flag now hard-fails CashDrawerSession precondition.
2. **Production `.env` copy-paste** : `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:112` correctly sets `POS_SIMULATION_HARDWARE=false`, but the comment block immediately above (lines 107-111) explicitly references the 3 code-path file:line sites — **so the template documents code that does not exist in the committed tree**. Anyone diff'ing template-vs-committed-code thinks the doc is wrong; anyone trusting the doc copies `.env` then ships → if a stale dev `.env` had `=true` it activates a non-existent bypass (safe), or worse if the working-tree code was committed in a rush.
3. **Test suite RED on fresh clone** : `PosSimulationHardware4ScenariosTest.php` uses `Config::set('pos.simulation_hardware', true)` at line 53. With `config/pos.php` absent, Laravel never registers this key → `Config::set` writes to runtime registry which works at test-time. But the test file itself is untracked, so a CI checkout doesn't even see it. **Not a failure**, but a "claimed-shipped, actually-doesn't-exist" gap.

**Heal** : Owner MUST either (a) `git add` the 5 files + commit as a single coherent "Wave 5J — simulation_hardware feature" OR (b) `git restore` the 3 service/controller mods + `rm config/pos.php` + `rm tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` to ship from `1235e3e1a` clean.

---

### P0-A5-#2 — 5 PHPUnit test files modified depend on already-committed F-SPLIT-PHANTOM-CARD-001 — **CI red on fresh clone**

**Severity** : P0 (ship-blocker)

Commit `55edb83ba` (Wave 5F) landed F-SPLIT-PHANTOM-CARD-001 — `terminal_id` is now mandatory for every CARD tranche in `PosOrderRequest`. **The 5 dependent test files that supply `PaymentTerminal` fixtures are MODIFIED but never committed**.

| Test file | Modification | Status |
|---|---|---|
| `tests/Feature/Pos/PosCashTrailTest.php:200-211` | Adds `PaymentTerminal::create([...STATUS_ACTIVE])` + `terminal_id => $terminal->id` in payload | unstaged |
| `tests/Feature/Pos/SplitPaymentEndToEndTest.php:101-110` | Adds `$this->terminal = PaymentTerminal::create([...])` in setUp | unstaged |
| `tests/Feature/Pos/TerminalIdWireInTest.php:110-145` | Renames test method + flips contract from "nullable legacy" to "rejected when CARD without terminal_id" | unstaged |
| `tests/Feature/Sentinels/SplitPaymentSentinelTest.php:40+94-106` | Adds `PaymentTerminal $terminalA` + setUp creation | unstaged |
| `tests/Unit/Services/Payment/SplitPaymentServiceTest.php` | Modified (similar terminal fixture additions) | unstaged |

**Claim vs reality**

CONVERGENCE_FINAL §5 line 70 claims `PHPUnit Wave 5G broader: 95/95 PASS`. That number is true **only in working tree**. A reviewer who clones HEAD `1235e3e1a`, runs `php artisan test --filter=SplitPayment`, will see **multiple failures** because the validation contract changed (committed) but the test fixtures didn't follow (uncommitted).

**Heal** : Owner MUST `git add tests/Feature/Pos/* tests/Feature/Sentinels/* tests/Unit/Services/Payment/*` + commit as part of Wave 5F align OR revert F-SPLIT-PHANTOM-CARD-001 if the fixtures are not ready. Cannot ship to prod with red CI on fresh clone.

---

### P0-A5-#3 — CONVERGENCE_FINAL.md stale by 2 waves — verdict built on pre-Wave-5H state

**Severity** : P0 (governance/audit-trail integrity)

`reports/test-e2e/v1-cloud-prep-2026-05-17/CONVERGENCE_FINAL.md`:

| Doc claim | Doc line | Reality |
|---|---|---|
| `HEAD post-session : 155ddbde8` | line 6 | Actual HEAD = `1235e3e1a` (2 commits ahead) |
| Commit table stops at `155ddbde8` (Wave 5G) | line 27 | Missing `46fb4ef2d` (5H) + `1235e3e1a` (5I) |
| "**Wave 5H pending (NOT done)** : PhpSpreadsheet RCE upgrade … FormRequest authz refactor 88 endpoints" | line 141 | Wave 5H DID land — see commit body `46fb4ef2d` : 5 CVEs CLOSED + 5 FormRequest authz hardened |
| "GO ABSOLUTE for Phase D cloud deploy" | line 12 | Based on 13 P0 + 5 P1 closure tally that excludes the Wave 5I A.1/C.1/A.2 heals |

**Why this is a real risk** : owner could use this stale doc to brief a deploy reviewer ("13 P0 closed → GO") without knowing the Wave 5I A.1 heal (POS IDOR 403/404 timing leak in `PosOrderController.php:107-117`) was a NEW P1 discovered POST-CONVERGENCE. The doc presents the verdict as final, but the substrate moved.

**Heal** : Owner MUST refresh CONVERGENCE_FINAL with `HEAD=1235e3e1a` + Wave 5H+5I rows in commit table + remove the "Wave 5H pending" line + add new Wave 5I findings table. Without this, the doc is a future-tense lie.

---

### P0-A5-#4 — POS offline replay payload incomplete — never persists a finalized payment

**Severity** : P0 (NF525 cash-trail / customer-money-lost risk)

`resources/js/components/admin/pos/PosComponent.vue:2985` enqueues offline orders **BEFORE** the payment modal opens and BEFORE PaymentComponent strips totals (POS-A6):

```js
// PosComponent.vue:2983-2996 — when navigator.onLine === false
const queued = await this.enqueueOrder({ ...this.checkoutProps.form });
```

But `this.checkoutProps.form` at line 2972 carries only the assembled pre-checkout shape — `idempotency_key`, items, totals — **NOT** `pos_payment_method`, `cash_received`, `payment_breakdown[]`. Those are populated inside `PaymentComponent.vue:runConfirmOrderAttempt` which only runs when the cashier opens the modal.

Then replay at `resources/js/composables/usePosOfflineState.js:48` blindly POSTs the queued shape:
```js
await postFn('admin/pos/order', entry.payload, config);
```

The replay target `POST /admin/pos/order` resolves to `PosController::saveOrder` which calls `assertCashDrawerSessionOpenIfCashInvolved($request)` (PosController:90) + further down dispatches to `SplitPaymentService` if `payment_breakdown` present. With `payment_breakdown` ABSENT in the replayed payload, the order may be persisted with **NO `OrderPayment` row at all** OR fail validation at PosOrderRequest (depending on which fields are required vs nullable post-POS-A6).

**Failure modes**

1. Order persisted, NO `OrderPayment` row → cash collected by the cashier (because they tapped CASH offline in their head) but database records no payment → NF525 reconciliation impossible (fiscal_sequence_no allocated, no payment trail).
2. Order rejected 422 → `markFailed` (usePosOfflineState:52) increments attempts → retries every 30s forever (no terminal-failure path in the code). The entry rots in IndexedDB until TTL_MS=30min (line 21) purges it. **Customer paid, cashier handed receipt, database has nothing.**
3. Idempotency replay : even if owner later wires a "finalized form" enqueue, the IndexedDB cache holds the broken shape AND a stable `idempotencyKey` (line 67) — second push with corrected shape returns 409 `replay` from cached 2xx OR conflicts on payload hash mismatch.

**Heal proposal** :
- enqueue ONLY after PaymentComponent.runConfirmOrderAttempt successfully assembled `payment_breakdown` (move enqueue from PosComponent:2985 into PaymentComponent's catch-block when axios fails network)
- OR mark queued entries as `pending_payment_capture: true` and rerun a cashier-side flow on reconnect (delete entry on success, ask cashier to redo).

---

### P0-A5-#5 — Stripe cents-truncation fix `Stripe.php:58` UNCOMMITTED — CONVERGENCE doc defers to V1.0.2 backlog while code already healed

**Severity** : P0 (revenue + claim-vs-reality)

Working tree at `app/Http/PaymentGateways/Gateways/Stripe.php:50-58` shows:
```php
'amount' => (int) round((float) $order->total * 100),
```
(diff: `+ (int) round((float) $order->total * 100)` vs `- (int) $order->total * 100`)

The CONVERGENCE_FINAL doc line 138 explicitly lists:
> **Frozen-zone LOCK pending owner-gate (V1.0.2 scope)** : Stripe cents-truncation fix unbundled (CTO P0-6, V1.0.1 V1.0.2 backlog)

**Two contradicting facts** :
- Code in working tree IS healed (correct round-before-cast pattern matching `OrderController:137` and `SplitPaymentService:103/110`)
- CONVERGENCE_FINAL says it is deferred to V1.0.2

Either way, the working-tree state is fragile: `git stash` reverts the fix (€0.99 truncation reappears per €X.99 order). A merge from HEAD `1235e3e1a` without staging this file ships the BROKEN Stripe amount cast to production.

**Note** : not in `composer.lock` either, so the fix is purely in `Stripe.php`. Single LOC, easy commit.

**Heal** : commit this file in the same staging chunk as the CONVERGENCE doc refresh + update doc line 138 to reflect actual status.

---

### P0-A5-#6 — `config/pos.php` referenced by 3 production code paths + 1 cloud env template — but not in `git`

**Severity** : P0 (deploy fragility, complements #1)

Two artifacts treat `config/pos.php` as a real file:
- `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:109` : "Code reads: config/pos.php:37 → bypasses CashDrawerSession precondition…"
- `PosController.php:92`, `PaymentService.php:280`, `SplitPaymentService.php:206` : all call `config('pos.simulation_hardware')`

A Laravel `config(...)` call on an absent config file returns `null`. The 3 bypass guards all use `=== true` (strict), so the **production code is currently safe-by-accident** (null !== true → bypass off → CashDrawerSession check fires normally).

**But** : the moment owner runs `php artisan config:cache` in deploy, Laravel scans `config/*.php` and bakes the result into `bootstrap/cache/config.php`. With `config/pos.php` missing, the `pos.simulation_hardware` key never exists → caller logic unchanged. Still safe.

**Real failure** : owner ships HEAD + working-tree mods + a sloppy `git add -A` that picks up `config/pos.php` BUT misses `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` → sentinel test never exists in CI → flag toggle never regression-tested.

**Heal** : either `git add config/pos.php` + the test + 3 service mods together OR delete `config/pos.php` and remove the references from cloud env template + 3 service mods. Half-done is worse than not-done.

---

### P0-A5-#7 — Frozen-zone discipline still clean (NEGATIVE finding — confirm-not-regress)

**Severity** : P0 confirmation (means owner discipline is intact for this surface)

Direct verification :
```
git diff d16d4ac48..HEAD --stat -- \
  resources/js/components/frontend/kiosk/ \
  public/js/pos-wizard.js public/css/pos-wizard.css \
  resources/views/admin-pos-v4.blade.php \
  app/Services/Fiscal/ app/Models/Scopes/BranchScope.php \
  app/Http/Middleware/IdempotencyKeyMiddleware.php \
  app/Services/Pricing/PricingService.php \
  app/Domain/Order/OrderStateMachine.php
```
**Output : empty.** Zero lines touched on any of the 13 CLAUDE.md §7 frozen files between V1.0.1 baseline (`d16d4ac48`) and HEAD `1235e3e1a`. This part of the CONVERGENCE doc (§5 line 80) is verified.

**Bonus** : `.cursor/hooks/safety-check.sh` is **strengthened** (uncommitted but in working tree) — list expanded from 2 → 14 frozen-zone entries syncing with CLAUDE.md §7. This is a defensive improvement, not a weakening. Owner should commit it.

---

## P1 + P2 findings

### P1-A5-#8 — Cron schedule audit : all 12 scheduled jobs have `withoutOverlapping` + `onOneServer`

**Severity** : P1 confirmation

`app/Console/Kernel.php` audit (lines 33-194) :

| Cmd | Cadence | Guards |
|---|---|---|
| `purge-expired-otps` (closure) | every 15min | `withoutOverlapping` only — **missing `onOneServer`** |
| `foodking:outbox:rescue` | everyMinute | both |
| `foodking:outbox:monitor --threshold=10` | everyMinute | both |
| `foodking:outbox:retry-failed --since=24h` | hourly | `withoutOverlapping(10)` + onOneServer |
| `foodking:webhook:retry-failed --since=24h` | hourly | both |
| `CleanupStalePendingKioskOrders` (job) | every5min | both |
| `pos:purge-parked-orders` | 03:15 | both |
| `foodking:outbox:prune --older-than-days=90` | 04:00 | both |
| `foodking:webhook:prune --older-than-days=90` | 04:15 | both |
| `SloEvaluatorJob` (job) | every5min | `withoutOverlapping(5)` + onOneServer |
| `stock:scan-rupture` | cron 5min | both + `when()` flag-gated |
| `foodking:fiscal:retry-alloc` | everyMinute | both |
| `foodking:availability:reset-stale-quota` | 00:05 | both |
| `foodking:fiscal:archive` (closure) | 02:00 | both |

**Finding** : `purge-expired-otps` lacks `onOneServer` — on a multi-VPS Phase D scale-out, this runs N times. Probably benign (OTP purge is idempotent) but worth fixing.

**Heal** : V1.0.2 add `->onOneServer()` to the OTP purge call.

---

### P1-A5-#9 — Bcrypt timing-attack signal disclosure (login latency leak)

**Severity** : P2 (marginal at FoodKing scale, ~150ms is sub-WAN-RTT noise)

`LoginController.php:95-98` (committed in `155ddbde8`) :
```php
if (Hash::needsRehash($user->password)) {
    $user->password = Hash::make($request['password']);
    $user->save();
}
```

`Hash::make` with `BCRYPT_ROUNDS=12` adds ~150-200ms latency on first login per user. An attacker probing `/api/login` with known emails can measure response delta to identify "users with stale (rounds=10) hashes" → "user has account old enough to predate the rounds bump" → "likely a long-tenure target, e.g. owner-physique".

**Mitigation options** :
1. Move rehash to a queued job dispatched after successful auth (decouples latency from response)
2. Always run a sleep/decoy on fresh-hash branch to flatten timing
3. Accept the leak (FoodKing scale, mild signal)

**Heal** : V1.0.2 backlog — dispatch `BcryptRehashJob` after auth, return immediately.

---

### P1-A5-#10 — Outbox `attempts<6 AND undispatched` events never pruned, never alerted at age — silent accretion in worst case

**Severity** : P2 (covered by `MonitorOutboxStaleness` if it runs)

`PruneOutboxCommand.php:50-60` predicate matches :
- `(A) dispatched_at IS NOT NULL AND dispatched_at < cutoff` (broadcast-succeeded)
- `(B) attempts >= 6 AND created_at < cutoff` (terminal failure)

**Gap** : `attempts < 6 AND dispatched_at IS NULL AND created_at < cutoff` → never matches either clause → row sits forever.

**Counter-defense** : `MonitorOutboxStaleness.php:44` runs everyMinute with `--threshold=10 --stale-after=30s` (Kernel:49-52). Threshold breach raises `Log::error` + non-zero exit → cron-failure alert.

**Real failure mode** : if the cron monitor itself is down (e.g. supervisor process not running), un-dispatched events accumulate silently. Prune never touches them. Domain_events grows unbounded.

**Heal proposal** : Add a clause (C) `dispatched_at IS NULL AND created_at < ($cutoff x 3)` (i.e. 270d) → guarantees ANY un-dispatched row past 9 months is treated as terminal-loss + prune-eligible + emits a `Log::error` per pruned row.

---

### P2-A5-#11 — Mobile `menu.js` aggressively modified (allergens + supplements rewrite) — uncommitted, separate scope from V1 Cloud-Prep

**Severity** : P2 (scope drift, not regression)

`git diff HEAD -- mobile/data/menu.js mobile/screens-item-steps.jsx` shows the `[MASSIVE-LOGIC HEAL 2026-05-17 P0]` allergens block — adds FIC 1169/2011 allergen arrays to 9 SUPPLEMENTS. This is outside the V1 Cloud-Prep cycle scope per the task list.

**Risk** : if owner bundles these changes into the same merge commit, they bloat the V1 Cloud-Prep PR with mobile-only logic, making rollback granularity worse. Two distinct concerns conflated.

**Heal** : split into a separate commit `feat(mobile): allergens FIC 1169/2011 — generated supplements` BEFORE the V1 Cloud-Prep merge to main.

---

### P2-A5-#12 — `AGENTS.md` modification + 4 mojibake-named untracked files (`,`, `[`, `"L'article ne correspond pas.,"`, `"Utilisateur non trouvé.,"`)

**Severity** : P3 (hygiene)

`git status` shows 4 untracked filenames consisting of stray punctuation or error-message text. These are almost certainly accidental shell-redirect outputs (e.g. `echo "L'article…" > "L'article…"`).

**Risk** : `git add -A` sweeps them into the next commit. Unprofessional in a release PR.

**Heal** : `rm` them BEFORE the merge stage. Verify `git status` clean of these entries.

---

## Phase D Ansible playbook — quick spot-check

Vault refs in `deploy/ansible/group_vars/all.yml:32-39` declare `vault_db_password`, `vault_redis_password`, etc. **as placeholders only**. There is no actual `group_vars/all/vault.yml` file with encrypted values in the repo (correctly gitignored per NF525, but not verifiable without owner-physique access to the vault password).

**Risk** : `ansible-playbook --check` cannot dry-run successfully without populated vault. Owner-physique action item #5 in CONVERGENCE_FINAL line 163 says "Generate ansible-vault password + populate group_vars/all.yml vault refs". This blocks Phase D step "verify playbook idempotent" until done.

**Heal** : owner MUST run `ansible-playbook --check -i inventory/production.ini site.yml --ask-vault-pass` against staging before any prod run. CONVERGENCE doc should add explicit acceptance criteria for `--check` clean output.

---

## Owner-claim verification matrix

| Owner claim (commit body / CONVERGENCE) | Reality | Verdict |
|---|---|---|
| Wave 5G "5 V1.0.2 P1 closures" (commit `155ddbde8`) | 28 files / +1386/-10 LOC — bcrypt + OSS wakeLock + Settings fanout + Branch revoke + Health probe all present in tree | TRUE |
| Wave 5H "PhpSpreadsheet 5 CVEs CLOSED + FormRequest authz × 5" (commit `46fb4ef2d`) | composer.lock bumped 1.30.0→1.30.4 + 5 FormRequest files modified `return true` → `$this->user()?->can(...)` | TRUE |
| Wave 5I "POS IDOR timing leak + cloud env template + Ansible pre-migrate snapshot" (commit `1235e3e1a`) | 3 distinct heals all present in commit tree | TRUE |
| CONVERGENCE_FINAL line 6 "HEAD post-session : 155ddbde8" | Actual HEAD = `1235e3e1a` | FALSE (stale) |
| CONVERGENCE_FINAL line 141 "Wave 5H pending (NOT done)" | Wave 5H landed at `46fb4ef2d`, 2 commits earlier | FALSE (stale) |
| CONVERGENCE_FINAL §5 "PHPUnit Wave 5G broader: 95/95 PASS" | True ONLY with uncommitted test-fixture mods on disk; fresh-clone CI will fail | FALSE (working-tree dependent) |
| Frozen-zone diff 0 lines (CONVERGENCE §5 line 80) | Confirmed via `git diff d16d4ac48..HEAD --stat` empty for 13 frozen files | TRUE |
| simulation_hardware "production-safe default false" (commit `1235e3e1a` body C.1) | TRUE if `config/pos.php` is in deploy. UNTRACKED = not in deploy. | UNVERIFIABLE until committed |
| Stripe cents-truncation "V1.0.2 backlog" (CONVERGENCE line 138) | Fix already lives in working tree `Stripe.php:58` | FALSE (uncommitted fix exists) |

---

## Recommended deploy gate (BEFORE merge to main)

Owner MUST perform the following in order :

1. **Decide scope of simulation_hardware feature** :
   - Option A : commit it as Wave 5J — `config/pos.php` + `PosController` + `PaymentService` + `SplitPaymentService` + `PosSimulationHardware4ScenariosTest.php` in one atomic commit. Add CI assertion `App\Providers\AppServiceProvider` boot-time `throw if app()->environment('production') && config('pos.simulation_hardware') === true`.
   - Option B : `git restore` all 3 service mods + `rm config/pos.php` + `rm tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` + revert `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt:107-112` block.

2. **Commit the F-SPLIT-PHANTOM-CARD-001 test fixtures** (5 files) OR revert commit `55edb83ba`. Cannot ship with red CI on fresh clone.

3. **Commit Stripe cents-truncation fix** `Stripe.php:58` as a standalone commit OR revert to original.

4. **Refresh CONVERGENCE_FINAL.md** : update `HEAD=1235e3e1a`, add Wave 5H/5I commit rows, remove "Wave 5H pending" line, refresh §5 test convergence numbers, refresh §7 backlog list.

5. **Remove the 4 mojibake-named files** + `,` + `[` from working tree.

6. **Commit `.cursor/hooks/safety-check.sh` strengthen** + `AGENTS.md` scope disambiguation.

7. **Run full PHPUnit suite + Vitest run on a fresh `git clone --branch v1-0-1-hardening-2026-05-17 → checkout 1235e3e1a` directory** (clean working tree, no stash). Confirm the numbers match CONVERGENCE_FINAL §5.

8. **Owner-physique : populate Ansible vault + run `ansible-playbook --check`** against staging. CONVERGENCE acceptance criterion missing.

Until all 8 are green, **NO-GO**.

---

## Summary

- **6 P0 findings** : 5 about the simulation_hardware deploy deathtrap + F-SPLIT-PHANTOM-CARD-001 test fixtures + Stripe cents fix + CONVERGENCE doc stale.
- **3 P1/P2 findings** : OTP purge missing `onOneServer`, bcrypt timing leak (V1.0.2 backlog), outbox un-dispatched event accretion.
- **1 P0 confirmation** : frozen-zone discipline IS intact (0 lines diff on 13 CLAUDE.md §7 files vs V1.0.1 baseline).
- **Owner-claim matrix** : 4 doc claims FALSE due to working-tree drift after CONVERGENCE was written. 5 claims TRUE.

The CONVERGENCE_FINAL "GO ABSOLUTE" verdict is **premature**. The architecture is sound, the commits are well-disciplined, the frozen zones are intact — but the working tree carries a half-shipped feature + dependent test fixtures + a critical revenue fix all dangling. Owner must close that gap BEFORE merge.
