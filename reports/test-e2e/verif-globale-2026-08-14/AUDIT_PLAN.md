# AUDIT PLAN — verif-globale-2026-08-14

**Scope commits** (`git log 26667af86..HEAD --oneline`, read via `git show <sha> --stat` + full
diff on every commit touching auth/fiscal/frozen files):

```
b77465fdb brain(§2): convergence Vague 1 + Vague 5 GOAL_CAYENNE_FINITION (docs only)
dbbe877a3 test(sidebar): remonte le plancher sentinelle à 18
60faeba6e feat(stock): écran d'ajustement inventaire matières premières
11019f363 fix(fiscal): "marquer payé" scellait des ventes sans mode de paiement
53b1dc6d6 fix(caisse): la raison d'écart ne quittait jamais le navigateur
f662a1277 fix(caisse): badge « 2e viande » — pos-wizard.js (FROZEN)
bf7fffea6 docs(lock): LOCK_POSWIZARD_VIANDE_BADGE_2026-08-14
d402e4c38 brain(§2): faux positif STALE deploy-vps.sh (docs only)
72cf928d4 fix(deploy): vérif de fraîcheur des bundles — mix-manifest.json only
ac5ab47f5 fix(caisse): caissier peut imprimer le ticket promo — nouvelle permission pos-flyer-print
0fe79b167 fix(cuisine): nom de repli du pont Epson (Windows config string only)
```

Plus a light spot-check of the orchestrator's own pre-range commits `2381d0bfc` (RBAC direct-API
test) and `bd17406f1` (SLO metric fix) — both confirmed ancestors of `26667af86` via
`git merge-base --is-ancestor`, i.e. genuinely already covered, not "another agent's unverified
work". Full diffs of every commit above were read (not just messages) before writing this plan.

## Pre-flight (already done, do not repeat)

- Dev server up at `http://127.0.0.1:8000`.
- Admin creds: `admin@lecayenne.fr` / `TestVisuel2026!`. POS/cashier login via
  `tests/e2e/helpers/login.js` (`loginAsPosOperator`) — verify it points at a seeded POS Operator
  account; if not, create one inline per spec (pattern used in `PromoFlyerCashierAccessTest.php`:
  `User::factory()->create(...)->assignRole('POS Operator')` — but that's PHPUnit; for Playwright
  use whatever fixture user `loginAsPosOperator` already wires, confirm its role before Wave A).
- PIN roue 481526 (not needed this audit — roue out of scope, see below).
- `workers=1` (DB-mutating specs, avoid cross-worker collisions).
- VPS `lecayenne` confirmed at same HEAD as local (deploy already run today).
- `tests/e2e/helpers/mega-audit-snap.js` (`attachMegaAuditRecorder`) for the 4-file artifact
  quartet on every visual state — mandatory per `REVIEWER_PROTOCOL.md` already written at
  `reports/test-e2e/verif-globale-2026-08-14/REVIEWER_PROTOCOL.md`.
- `tests/e2e/helpers/rate-limit.js` (`clearFoodKingRateLimits`) — required for Wave A, which
  will deliberately hit `PromoFlyerService::DAILY_CAP_PER_USER` (40/day) — run this at the start
  of that spec's `beforeAll`, and be aware the cap is DAILY so a spec that creates >40 flyers in
  one run will legitimately 429 on the 41st call **by design** — assert that, don't fight it.

## Out of scope (explicit)

1. **Roue / compte** (`reports/test-e2e/roue-account-e2e-2026-08-13/`, 5-round convergence
   completed earlier today) — verified via `git show --stat` on all 11 commits above: none touch
   `WheelSpin`, roue routes, or account/loyalty surfaces. No regression path exists. Not
   re-audited.
2. **`0fe79b167` (kitchen Epson printer bridge fallback name)** — pure string change in 3
   Windows-side launcher artifacts (`.ps1`, `.vbs`, `kitchen-bridge.js` fallback constant), no PHP
   route, no Vue component, nothing Playwright can reach (the bridge is a physical LAN process on
   a Windows PC that doesn't exist in the test env). Verification is a **static grep + unit-test
   check only** (folded into Wave E below as a 2-minute check, not a full wave) — the actual fix
   can only be proven on-site with the physical printer, which is explicitly noted as pending
   install in the commit message itself ("il reste à installer le service NSSM persistant").
3. **`72cf928d4` / `d402e4c38` (deploy-vps.sh bundle-freshness check)** — a bash script that runs
   during `ssh`-deploy, not reachable via HTTP or Playwright. Verification is a **dry-run /
   logic-trace only** (folded into Wave E), not a wave of its own.
4. **`b77465fdb`, `d402e4c38`, `bf7fffea6`'s narrative half** — `PROJECT_BRAIN.md` and the LOCK
   doc's prose are docs-only; the LOCK doc's *factual claims* (hash match, test count) ARE
   verified in Wave B, but the prose itself isn't a re-audit target.
5. **`2381d0bfc` / `bd17406f1`** — pre-range, already has its own evidence from earlier today.
   Wave F gives it a light spot-check only, not a full re-audit.

## Wave A — Cashier permission widening (`ac5ab47f5`), adversarial focus

**Spec**: `tests/e2e/verif-globale-cashier-permission-2026-08-14.spec.js`
**Contexts**: 3 browser contexts — POS Operator (cashier), Branch Manager, and a role WITHOUT
the permission (Chef, mirroring the PHPUnit witness in `PromoFlyerCashierAccessTest.php`).
**Surfaces**: `/admin/pos` tracker (`PosOrdersTrackerComponent.vue`), promo-flyer panel
(`PromoFlyerComponent.vue`), direct API calls via `page.request` for the negative-permission
probe.

Adversarial angle the orchestrator flagged: **does `pos-flyer-print` leak into any OTHER gated
UI?** Already partially answered by static read — `CouponController.php:26` still gates `store`
on `coupons_create` alone (verified via grep, `pos-flyer-print` does not appear anywhere outside
`PromoFlyerController`/seeders/migration/tests). Wave A must confirm this holds at runtime, not
just in source:

Numbered visual states:
1. `01-cashier-tracker-flyer-button-visible` — POS Operator sees the 🎟️ button on the tracker
   header AND on a platform-order card (`isPlatformOrder(order) && canPrintFlyer` — both gates
   must be true).
2. `02-cashier-flyer-create-201` — cashier submits create form, assert HTTP 201 (not 403), assert
   toast/success state, assert `network.json` shows no unexpected 4xx.
3. `03-cashier-flyer-reprint-200` — reprint succeeds.
4. `04-cashier-flyer-revoke-200` — revoke succeeds.
5. `05-cashier-coupon-crud-still-403` — **the adversarial core assertion**: same cashier session,
   direct `page.request.post('/api/admin/coupon', {...})` (generic `CouponController::store`,
   gated `coupons_create` only) → assert `403`. Proves `pos-flyer-print` did NOT widen into
   generic coupon CRUD.
6. `06-chef-flyer-button-hidden` — Chef (no `pos-flyer-print`) does NOT see the flyer button
   (`v-if="canPrintFlyer"` defensive gate) — assert DOM absence, not just hidden.
7. `07-chef-flyer-direct-api-403` — Chef hits `/api/admin/promo-flyer` directly (bypassing the
   v-if) → assert `403`, not silent 500 or accidental 200.
8. `08-daily-cap-429` — drive the cashier session to `DAILY_CAP_PER_USER` (40) creates in one
   run (batch via `page.request`, not UI, for speed) then assert the 41st returns `429` with the
   documented message, and that an **Admin** session in parallel is NOT capped (create #41 as
   Admin → 201). This is the real "does the permission widening open an abuse vector" proof the
   PHPUnit suite didn't runtime-exercise (it tests the cap logic but not cross-role exemption via
   live HTTP + UI).

**Acceptance**: all 8 states green, zero P0/P1 per `REVIEWER_PROTOCOL.md`. Backing PHPUnit already
green (`tests/Feature/Promo/PromoFlyerCashierAccessTest.php`, 2026-08-14) — re-run as a fast
confirmation: `php artisan test --filter=PromoFlyerCashierAccessTest`.

## Wave B — Frozen-zone `pos-wizard.js` LOCK verification (`bf7fffea6` + `f662a1277`)

**Spec**: `tests/e2e/verif-globale-poswizard-badge-2026-08-14.spec.js` (extends existing
`tests/js/posWizardViandeSupplementUnified.spec.js` coverage with a live-browser visual pass —
the Vitest suite already proves the logic, this wave proves the RENDER).

Pre-check (no browser needed, do first, cheap):
- Recompute `shasum -a 256 public/js/pos-wizard.js` and diff against
  `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` entry for that path — must match
  exactly (confirmed already in plan research: `24eaac96230b4f37fa26a24a2a71e2a05d6d81c9f7679a128f3b5835464560d3`
  on both sides — re-verify fresh, don't trust the cached value).
- Confirm `git diff --stat -- <13 §7 frozen files>` since `26667af86` shows ONLY
  `public/js/pos-wizard.js` with the 18-line delta claimed in the LOCK doc (no other frozen file
  touched).
- Confirm the LOCK doc (`plans/LOCK_POSWIZARD_VIANDE_BADGE_2026-08-14.md`) was committed in
  `bf7fffea6`, BEFORE the patch commit `f662a1277` (LOCK-first discipline) — check commit
  timestamps/order, not just presence.
- `npx vitest run tests/js/posWizardViandeSupplementUnified.spec.js` — re-confirm 5/5 (claimed in
  LOCK doc §4).

Numbered visual states (live POS wizard, `/admin/pos`, cashier or admin session):
1. `01-sandwich-classique-1ere-viande` — add 1st viande (included), badge shows `1/1 incluse`.
2. `02-sandwich-classique-2e-viande-supplement` — add a DIFFERENT 2nd viande → assert badge text
   is **exactly** `1 incluse + 1 supp.` (not the old frozen `1/1 incluse`), assert tile shows
   `✓1` (or `✓` + count), assert price line moved +2,50€, assert `+2,50€` suppl tag visible on
   tile.
3. `03-tacos-l-2e-viande-gratuite-unaffected` — Tacos L (where 2nd viande is free per existing
   rule) still shows correct included-count badge, NOT the new supplement text — proves the
   display-only patch didn't accidentally change quota logic for a product where 2 are already
   included.
4. `04-cart-total-matches-line-items` — numeric integrity check per REVIEWER_PROTOCOL category
   11: cart total after the supplement add = sum of line items including the +2,50€.

**Acceptance**: SHA match, frozen-diff clean, 4 visual states green, zero price/quota regression
on any product family tested.

## Wave C — Fiscal "mark paid" scope-check (`11019f363`)

**No new Playwright spec required for the fiscal-purity check** — this is a static + PHPUnit
verification, done BEFORE any live-order test, because it's the highest-risk item in this batch
(NF525-adjacent):

1. `git show 11019f363 --stat` — confirm (already done in plan research) it touches ONLY
   `app/Services/OrderService.php` + a new test file. Zero lines in
   `FiscalSequenceService.php` / `ZReportService.php` / `AuditLogService.php` / `BranchScope.php`.
2. Confirm `OrderService::changePaymentStatus()` calls
   `app(\App\Services\Payments\PosMethodFromGateway::class)->appliquer($locked)` — a pre-existing
   service (from the `PaymentService::payment()` gateway-callback fix the commit message
   references), not new fiscal logic — re-read `PosMethodFromGateway::appliquer()` itself to
   confirm the "never overwrite an existing pos_payment_method" guard is real, not just claimed.
3. `php artisan test --filter=ZReportUnknownMethodSentinelTest` (new test in this commit) +
   `php artisan test --group=fiscal` (or `tests/Feature/Fiscal/`) — full fiscal suite green.

**Then one live-flow Playwright state**, folded into a shared "admin operations" spec
(`tests/e2e/verif-globale-fiscal-cash-2026-08-14.spec.js`, shared with Wave D below since both are
small, focused, non-visual-heavy fixes):
1. `01-mark-paid-dropdown-sets-payment-method` — as Admin, open an UNPAID online/table order,
   use the dropdown "marquer payé" action, then query the order via API/DB assertion (not just
   UI) that `pos_payment_method` is now non-null and correctly mapped (CARD/CASH per the gateway
   used) — proves the fix end-to-end, not just unit-level.

**Acceptance**: zero frozen-fiscal-file diff, fiscal PHPUnit suite green, live mark-paid flow sets
`pos_payment_method` correctly.

## Wave D — Cash-drawer variance reason regression proof (`53b1dc6d6`) + new stock page (`60faeba6e`/`dbbe877a3`)

Two independent fixes bundled in one spec file for wall-clock efficiency (`verif-globale-fiscal-cash-2026-08-14.spec.js`, continued):

### D1 — Cash drawer close-with-variance
1. `02-open-session-then-close-with-variance` — open a drawer session, record enough cash
   movements to force `|variance| > cash.variance_threshold_eur` (2,00€), attempt close WITHOUT a
   reason → assert 422 `CASH_VARIANCE_REASON_REQUIRED` (still enforced, guard I6 intact) — THEN
   retry WITH a reason typed in the dialog → assert the POST `/reconcile` network call
   (`network.json`) actually carries `variance_reason` in its request body (this is the literal
   regression: the body used to be `{}`), assert 200, assert session status becomes CLOSED (not
   stuck OPEN).
2. Backing test: `php artisan test --filter=CashDrawerCloseVarianceTest` (183 lines, already
   claims 4 new assertions) + `npx vitest run tests/js/cashDrawerServiceReconcileReason.spec.js`.
3. **Do NOT** attempt to close the 2 real stuck-open production sessions mentioned in the commit
   message (36/49 days old, 3 818,30€) — that's a production data operation, out of scope for a
   local/dev e2e audit, flag it as a manual owner action if still open on VPS (read-only check:
   query VPS `cash_drawer_sessions` for `status=open AND opened_at < now() - interval 7 day` — a
   READ, not a write).

### D2 — RawMaterialAdjustComponent (fresh page, needs functional CRUD proof per project standard)

**Spec** (separate file, page is substantial): `tests/e2e/verif-globale-raw-material-adjust-2026-08-14.spec.js`

Per this project's established standard (see `memory/goal_admin_nav_breadth_convergence_2026-08-13.md`
pattern — "page loads" is not proof, functional CRUD-level proof is required), and the `data-testid`
hooks already present in `RawMaterialAdjustComponent.vue` make this straightforward:

Numbered states:
1. `01-sidebar-entry-present` — confirm `menu.raw_material_adjust` sidebar link navigates to
   `/admin/stock/raw-material-adjust` (regression-proofs `dbbe877a3`'s sentinel bump — sidebar
   count now 18, entry text present).
2. `02-list-loads-materials` — `[data-testid=raw-material-adjust]` renders, materials list
   populated (not stuck on `[data-testid=rma-loading]`), search filter
   (`[data-testid=rma-search]`) narrows the list.
3. `03-open-adjust-panel` — click `[data-testid='rma-open-<id>']`, panel opens
   (`[data-testid='rma-panel-<id>']`), form pre-filled with current `on_hand` as target.
4. `04-submit-empty-reason-blocked` — clear the reason field, submit → assert client-side
   `[data-testid=rma-form-error]` fires (reason < 3 chars), assert NO network call fired (this is
   a UI-level guard duplicating the backend `min:3` rule — confirm both layers agree).
5. `05-submit-negative-target-blocked` — target < 0 → same client-guard-then-backend-403 pattern;
   then bypass the UI via `page.request` directly with `target_on_hand: -5` → assert backend 422
   (validation `min:0`) independently of the UI guard (this is the REAL functional proof, not the
   client-side mirror).
6. `06-submit-valid-adjustment-writes-stock-and-movement` — valid target + reason, submit, assert
   201/200, assert `on_hand` in the card updates to the new value (`[data-testid='rma-onhand-<id>']`),
   AND assert a new row appears in `[data-testid='rma-history-list-<id>']` with the correct
   `reason`, `delta`, `previous_on_hand`/`target_on_hand` — this is the CRUD-completeness check:
   read-after-write across BOTH the stock table and the movement history, not just a 200 response.
7. `07-permission-gate-history-vs-adjust` — role with `items_show` but not `items_create` sees
   `[data-testid=rma-read-only]` / `[data-testid=rma-readonly-panel]`, history loads, but the
   adjust form is absent — assert direct `POST /api/admin/raw-materials/{id}/adjust` from that
   role → 403 (mirrors the `items_create` gate at controller level).
8. `08-branch-isolation` — (if a 2nd branch fixture is cheap to seed) a material from branch A is
   not visible/adjustable by a branch B session — otherwise document as "V1 mono-branch, isolation
   code present (`authorizeWritableBranchScope`) but not exercised — acceptable per V1 LOCAL
   Le Cayenne single-branch envelope, note for V2 backlog."

**Acceptance**: `php artisan test --filter=RawMaterialAdjustEndpointTest` green (11/11 already
claimed), all 8 states green with real read-after-write proof on state 6 (the one that actually
matters — everything else is scaffolding around it).

## Wave E — Kitchen printer bridge + deploy script (light static/logic verification, no browser)

Both fixes are unreachable by Playwright (Windows LAN process; deploy shell script). Fold into a
short written verification, not a spec file:

1. **`0fe79b167`**: grep-confirm all 3 launcher artifacts
   (`install-kitchen-service.ps1`, `start-kitchen-bridge-hidden.vbs`, `kitchen-bridge.js`) now say
   `Epson TM-m30 Cuisine` consistently, and confirm the counter-printer name
   (`Epson TM-m30II`, the COUNTER bridge's fallback) still exists unchanged in the counter-side
   launcher files (they must NOT have been accidentally touched — the two bridges must never share
   a printer name). Confirm `tests/Feature/Kitchen/KitchenTicketAutoPrintTest.php:82` already
   encodes `Epson TM-m30 Cuisine` as expected (it does, per grep — good, the test was updated in
   lockstep or already correct). Run `php artisan test --filter=KitchenTicketAutoPrintTest`.
2. **`72cf928d4`**: read `tools/deploy-vps.sh` VÉRIF 1 block, manually trace the logic against
   two scenarios: (a) a build where ALL bundle content changed → mix-manifest.json mtime fresh →
   pass; (b) a build with ZERO content change (webpack `compareBeforeEmit` skip) →
   mix-manifest.json STILL gets rewritten (per the commit's own claim, "vérifié sur lecayenne") →
   pass, no false rollback. This can be dry-run locally: touch `public/mix-manifest.json`,
   `DEPLOY_START=$(date +%s)`, sleep 1s, re-run just the VÉRIF 1 block in isolation (extract to a
   throwaway shell snippet) against the real file — confirm no `rollback` call fires today with a
   fresh manifest.

**Acceptance**: printer name consistency confirmed across all 3 files with no counter/kitchen
cross-contamination, `KitchenTicketAutoPrintTest` green, deploy-script logic trace confirms no
false-positive path remains for the byte-identical-bundle case.

## Wave F — Light spot-check, orchestrator's own pre-range changes (`2381d0bfc`, `bd17406f1`)

Not a full re-audit — both are pre-`26667af86`, already have their own evidence from earlier
today. 10-minute spot-check:

1. `php artisan test --filter=` (whatever class `2381d0bfc` added — confirm it's still green
   after everything else that landed since, since a later commit could have silently broken RBAC
   direct-API enforcement).
2. Confirm `bd17406f1`'s `payment_success_rate` metric still computes without error post-deploy
   (one live check: hit whatever admin dashboard/metrics endpoint surfaces it, confirm no
   type-juggling regression reintroduced by any commit in the new range — none of the 11 commits
   above touch observability/metrics code per the stat diffs already read, so this should be a
   clean pass).

**Acceptance**: both still green, no regression introduced by the 11 commits since.

## Wave ordering / parallelization

A (independent) ‖ B (independent) ‖ E (independent, no browser, run first — cheapest) can run in
parallel. C and D1 share a spec file (sequential within it — cash-drawer and mark-paid both touch
`orders`/`cash_drawer_sessions` state, keep same worker). D2 is independent, can run in parallel
with C/D1. F runs last, cheap, after everything else is confirmed not to have regressed it.

Recommended real parallel batch: **[E, F]** (cheap, no-browser/light) first to clear fast; then
**[A, B, D2]** in parallel (independent browser contexts); then **[C+D1]** last (shares OrderService
fiscal-adjacent surface, wants full attention, not parallel with anything else touching
orders/cash).
