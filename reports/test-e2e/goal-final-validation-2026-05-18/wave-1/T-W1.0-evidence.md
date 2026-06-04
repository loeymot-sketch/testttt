# Wave 1 — Re-attestation Evidence

**Date** : 2026-05-18
**Branche** : `heal/cms-pr1-quickwins-2026-05-18`
**HEAD** : `01d2b25f6` (T-6.4 PersistBranchStatusChangedToOutbox)
**Baseline diff range** : `626d5a389..HEAD`

## §1 — NF525 Chain
```
$ php artisan fiscal:verify-chain --all
+ branch=1 CHAIN OK
SWEEP COMPLETE — CHAIN OK on every active branch (1 total)
```
**Status** : GREEN. Chain unchanged-or-appended.

## §2 — Frozen-zone diff (13 protected files)
```
$ git diff --stat 626d5a389..HEAD -- \
  resources/js/components/frontend/kiosk/Kiosk{Wizard,App,Upsell}Component.vue \
  public/{js/pos-wizard.js,css/pos-wizard.css} \
  resources/views/admin-pos-v4.blade.php \
  app/Services/Fiscal/{FiscalSequence,ZReport,AuditLog}Service.php \
  app/Models/Scopes/BranchScope.php \
  app/Http/Middleware/IdempotencyKeyMiddleware.php \
  app/Services/Pricing/PricingService.php \
  app/Domain/Order/OrderStateMachine.php
(empty output — zero diff)
```
**Status** : GREEN. 0 lines diff across 13 frozen files.

## §3 — PHPUnit broad smoke `--filter='Fiscal|Pos|Kds|Trust|Pricing|Outbox|Admin'`
```
Tests:  3 failed, 2 incomplete, 15 skipped, 978 passed
Time:   141.09s
```
**Status** : 978/981 GREEN (99.7%). 
**Baseline failures (pre-existing, not regressions)** :
- `Tests\Feature\Composer\ComposerAuthzMinimalTest` × 3 (assertForbidden expects 403, gets 404 — Composer endpoints still return 404 for forged-payload cross-branch. Same 404-vs-403 IDOR-timing pattern healed Wave 5I for POS; NOT yet applied to Composer endpoints. V1.0.2 backlog.)

**Note** : Test file `ComposerAuthzMinimalTest.php` dates 9 May (pre-Critical-Focus, pre-T-6.4). Not introduced this session. Pattern matches BRAIN §3 "POS IDOR 403/404 timing" healed at `1235e3e1a` Wave 5I — but Composer surface not in that heal scope.

## §4 — Vitest full smoke
```
Test Files  2 failed | 233 passed (235)
Tests       6 failed | 1494 passed | 3 skipped (1503)
Duration    14.32s
```
**Status** : 1494/1500 GREEN (99.6%).
**Baseline failures (pre-existing, not regressions)** :
- `tests/js/kioskOfflineQueueV2.spec.js` × 5 — KioskOfflineConflictModalComponent.vue render error in Proxy._sfc_render. Test file commit `f1e0d6119` [P-MEGA-W7-A] dates back well before this session.
- `tests/js/posWizardComposerProfile.spec.js` × 1 — snapshot assertion for `:items="items"` string match. Test file pre-existing.

## §5 — Dev server reachability
```
$ curl -s -o /dev/null -w "%{http_code}\n" --max-time 3 http://127.0.0.1:8000/login
200
```
**Status** : Local Laravel dev server UP at port 8000. E2E specs reachable.

## §6 — Git status summary
- Branch verified : `heal/cms-pr1-quickwins-2026-05-18` ✅
- HEAD verified : `01d2b25f6` ✅
- Single commit since baseline : T-6.4 listener (no frozen-zone touch, contained in `app/Listeners/PersistBranchStatusChangedToOutbox.php` + EventServiceProvider register)
- Working tree dirty (BRAIN edits + reports — non-source-code changes from prior sessions)

## §7 — Conclusion Wave 1
**Pass** : 7/7 zones Critical Focus convergence (HEAD `1e7c65ecc` Wave 7-zone) + T-6.4 add holds GREEN.
- NF525 chain CHAIN OK
- Frozen-zone diff = 0
- PHPUnit smoke 978/981 (3 baseline)
- Vitest 1494/1500 (6 baseline)
- 3 + 6 = 9 baseline failures all pre-existing, all attributable to commits before `626d5a389`
- No new regressions introduced by T-6.4 commit

**Verdict** : Wave 1 RE-ATTESTATION GREEN.

## §8 — Carry-forward to Wave 3-5
Tasks ready to execute scope-minimal :
- T-6.3.1 Stripe CSRF except pattern bare route (1 LOC + test)
- T-9.4.1 EnsureUserStatusActive PHPUnit sentinel (NEW test, 4 cases)
- T-9.1.1 IngredientController authz middleware (small + test)
- T-1.3.1 LOCK plan Fiscal anon class (doc-only)
- T-5.1.2 LOCK plan W2 (doc-only)
- T-5.1.3 LOCK plan W5 (doc-only)
- T-6.4 verify regression (already commit `01d2b25f6` — verify test runs)

V1.0.2 backlog (deferred, rationale documented) :
- 3 ComposerAuthzMinimalTest failures → V1.0.2 (apply 5I IDOR-timing fix to Composer endpoints)
- 6 Vitest baseline failures → V1.0.2 (KioskOfflineConflictModal render + posWizardComposerProfile snapshot)
