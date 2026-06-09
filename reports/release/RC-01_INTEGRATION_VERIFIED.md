# RC-01 INTEGRATION — VERIFIED TRIAL (de-risks GATE-INT-1)
2026-06-09. Companion to `branch-merge-manifest.md`.

## What was done (safe, reversible, no push, no deployed-line change)
A **trial merge** of `heal/deployed-dashboard-fixes-2026-06-08` (e12762844, 20 unique commits) into `heal/pre-cloud-exec-2026-06-05` (da4d45dac) was executed in an **isolated worktree** on branch **`trial/rc-01-integration-2026-06-09`** (commit `2feebad32`, merge parents da4d45dac + e12762844). The deployed line was NOT touched.

## Verified result — the integration is LOW-RISK
- **64 files auto-merged cleanly** — including `fr.json`, `en.json`, `routes/api.php` (the append-coordination registries union-merged automatically).
- **11 conflicts, ALL resolved**:
  - **7 FR-locale display components** (Employee/Admin/Chef lists + EmployeeShow + CashSessionReport + ItemList + CatalogStudio) — every one is a **duplicate-correct-fix**: BOTH branches independently fixed the SAME defects (phone-null guard, role→FR label, cash-session money, item PRIX currency). My adversarial massive-E2E found exactly what the deployed-dashboard team also found. Resolved by keeping the **live-verified `-ours`** versions (proven on :8765 this session). No semantic conflict — two right answers to the same bug.
  - **4 compiled bundles** (`admin-shell/admin-reports/pos-app.js` + `mix-manifest.json`) — resolved to HEAD, **must be rebuilt on adoption** (`npm run prod`), never hand-merged.
- **FROZEN-SAFE (the critical invariant):** the merge changes **ZERO frozen files**, and independently, the **sibling's 20 commits touch ZERO frozen files**. So RC-01 integration needs **no GATE-FROZEN** — it's all non-frozen FR-locale + dashboard-excellence polish.
- **Net delta:** 53 files, +1785/−195 (the sibling's dashboard-excellence W1–W5 value + the resolved FR-locale set).

## What this means for GATE-INT-1 (owner decision)
GATE-INT-1 is now de-risked from "decide a direction + execute a risky unaudited merge" to **"approve adopting a verified, frozen-safe, conflict-resolved integration."** The trial branch `trial/rc-01-integration-2026-06-09` is ready to inspect.

**On owner approval, adoption = 3 mechanical steps (I can run them):**
1. Merge `heal/deployed-dashboard-fixes-2026-06-08` into the chosen release branch (same resolution as the trial: keep live-verified FR-locale `-ours`, rebuild bundles).
2. `npm run prod` → rebuild bundles; run `BundleFreshnessSentinel` + `FrozenZoneSha256BaselineSentinelTest` (expected GREEN — 0 frozen) + full PHPUnit/Vitest.
3. Live smoke on :8765/:8767 (delete-dialog FR, transactions FR, items PRIX FR — already verified on the deployed tree this session).

## Residual owner decision (the irreducible part)
**Which branch is the canonical release target** (manifest recommends `pre-cloud-exec` = the deployed line) and **authorization to adopt** (it changes what ships). That single approval unblocks the full integration. Everything technical is verified.

## Note
Trial bundles are stale (rebuilt on adoption). The merge SOURCE is proven (frozen-safe, conflict-resolved); full test-green is a 1-command verification once adopted onto a deps-installed tree.
