# Wave N T1 — PHPUnit Broad Smoke

**Branch:** `heal/cms-pr1-quickwins-2026-05-18`
**HEAD at run:** `190458edd` (lifecycle Z2 P1 follow-up; one commit after the handoff base `a9b745060`)
**Date:** 2026-05-20
**Command:** `php artisan test` (no `--stop-on-failure`)

---

## Headline

**2435 passed / 3 failed / 29 skipped / 2 incomplete** in **382.70s** (~6m23s)

- Pass rate: **2435 / (2435+3) = 99.88 %** on executable tests.
- Skipped (29) + incomplete (2) are pre-existing Owner-finalize / V1.0.2 backlog markers (e.g. `Tests\Load\RushMidiSimulationTest::s72/s73` documented Owner-finalize HTTP-route remediation, S7.1 already covers the structural fiscal-monotonic invariant).

---

## Failure breakdown

| # | Test | Class | Line | Reason |
|---|---|---|---|---|
| 1 | `branch admin cannot update foreign profile by forging payload scope` | `Tests\Feature\Composer\ComposerAuthzMinimalTest` | 114 | Expected 403, got 404. Foreign `ItemWizardProfile` (branch_id_scope=B) hidden from Branch-A user by `WizardProfileBranchScope` before authz fires → implicit route-model binding misses → 404. |
| 2 | `show defaults to actor branch and does not leak foreign latest profile` | `Tests\Feature\Composer\ComposerAuthzMinimalTest` | 139 | Expected 403, got 404. GET `?branch_id_scope=$branchB->id` — same scope-filter-vs-authz race: returns 404 (not visible) instead of 403 (forbidden). |
| 3 | `branch admin cannot mutate composer steps for other branch` | `Tests\Feature\Composer\ComposerAuthzMinimalTest` | 237 | Expected 403, got 404. POST `/profiles/{foreignProfile}/steps` — foreign profile scoped out, RMB misses, 404 before authz. |

All 3 failures are inside the **same file** (`ComposerAuthzMinimalTest`) and the **same controller surface** (`ComposerProfileController` + `ComposerStepController`), with the **same root pattern**: scope filter precedes authz, surfacing 404 where the test asserts 403.

---

## Regression risk

**0 regressions detected from Wave K / L / M.**

Verification:
- `git log --since="2026-05-15" -- app/Http/Controllers/Admin/ComposerProfileController.php app/Http/Controllers/Admin/ComposerStepController.php database/seeders/ComposerPermissionsMinimalSeeder.php` → **empty** (no commits touched these in the audit window).
- `git log --all -- tests/Feature/Composer/ComposerAuthzMinimalTest.php` → latest touch is in commits `9730b18e7 / 53f1ea45c / b873d4728` (older than Wave K).
- Wave K/L/M heals (190458edd → a9b745060) targeted: lifecycle dispatch (Z2), withoutGlobalScopes plural (Z6 P1), kiosk_machines UNIQUE (P3), Slot FR placeholders (P4), addon-role membership trait (Z4 P0-01), parent_order_id UNIQUE (Z8 P0-1), outbox retry-failed cap (Z3 B-2), polling_fallback dead code (Z3 B-6), outbox rescue stranded-claimed (Z3 B-1), loyalty refundPoints idempotent NOOP (Z8 P0-2), RefundCreated listener reorder (Z8 P2-2), cashBack DB::transaction (Z8 P1-2), `decrementForOrder` Cache::add SETNX (Z2 P1), OutboxBroadcastSwallowed listener (Z3 B-3), PosLoyaltyController branch check (Z6+Z8). **None overlap the composer surface.**
- The middleware chain (`permission:catalog.compose` → `wizard.per_item_profile_guard`) was last modified before the audit window (commit `5469e82ba` removed unrelated SetLocale, not composer).

**Conclusion:** failures are **pre-existing scope-leaks-as-404 behaviour**, not introduced by Wave K/L/M.

---

## Classification

| Test | Classification |
|---|---|
| Composer #1 — update foreign by forging payload | **PRE-EXISTING** (BranchScope-precedence-over-authz, pattern documented in audit history `feedback_silent_html_masquerade.md` analogue). Not a V1 ship blocker — the substantive security invariant (foreign cross-branch mutation is rejected) holds; only the HTTP status code disagrees (404 vs 403). |
| Composer #2 — show defaults / no leak | **PRE-EXISTING** — same scope-filter-first pattern; data leak is **prevented** (404 = no leak), test contract is over-strict on status code. |
| Composer #3 — cannot mutate foreign steps | **PRE-EXISTING** — same family; 404 still blocks the mutation. Security-wise equivalent to 403. |

---

## Pre-existing failures (acceptable)

The 3 Composer failures are the **only** REDs and all classify as pre-existing scope-vs-authz status-code mismatch. The 29 skipped + 2 incomplete are explicitly Owner-finalize / V1.0.2 backlog (e.g. Load rush S72/S73, various flagged-off feature paths).

No new V1.0.X-tagged regression entries needed.

---

## Verdict

- **GREEN-or-Baseline:** **YES** (baseline). 2435/2438 = 99.88 % executable green; 3 REDs are pre-existing 404-vs-403 status-code disagreements on already-secure scope filtering, not security regressions.
- **V1 ship blocker tests:** **none.** The security invariants (cross-branch read-protection + cross-branch mutation-rejection) are upheld by `WizardProfileBranchScope`; only the test's expected HTTP status (403) diverges from observed (404). Suggested V1.0.2 follow-up: align controller to throw `AuthorizationException` (→403) before falling through scope-filtered RMB, OR loosen test to `assertStatus([403,404])`. Not blocking V1 Le Cayenne.
- **NF525 / frozen zones:** untouched (no failures in `Tests\Feature\Fiscal\*`, `Tests\Feature\Sync\*`, `Tests\Feature\Stock\*`, `Tests\Feature\Webhooks\*` — all green).

---

## Evidence pointers

- Full raw output truncated to last 200 lines in this run; failure block reproduced verbatim above.
- Test file: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Composer/ComposerAuthzMinimalTest.php:114,139,237`
- Route definitions: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/routes/api.php:735-759`
- Middleware: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Middleware/EnsureProfileNotItemOwnedUnlessDemoEnabled.php` + `app/Support/WizardPerItemDemo.php`
- Seeder: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/seeders/ComposerPermissionsMinimalSeeder.php`
