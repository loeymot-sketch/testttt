# Borne ultrareview — fix + test convergence (testttt)
2026-06-09 · branch `heal/cms-pr1-quickwins-2026-05-18` · supervisor: Claude

Origin: `/ultrareview` of the borne review-bench (`foodking-review/borne`, snapshot of THIS repo @ ad29e7875). 6 findings; fixes ported here (the only runnable instance) and validated with real interface + backend tests.

## Findings — final verdict
| # | Sev | Verdict | Heal (files) | Tests (real, run here) |
|---|---|---|---|---|
| F1 quantity clamp / stale line-total | Moderate | **FIXED** | `store/modules/kioskCart.js` (ADD_ITEM, REPLACE_ITEM_AT) + `KioskOrderSummaryComponent.vue` (recap cap) | `tests/js/kioskCartClampTotal.spec.js` (4) — **proven fail-on-unhealed** (125€ vs 100€) → pass |
| F2 PricingPreview tax-on-pre-discount | Low-Mod | **NOT A BUG (closed)** | none | code evidence: kiosk_promo preview formula `subtotal+tax−discount` == `PricingService` total formula (`PricingService.php:353`, tax per-line on gross). Consistent with coupon path + actual charge. |
| F3 login enumeration | Low | **FIXED** | `app/Http/Controllers/Auth/KioskMachineLoginController.php` (password checked before account-state) | `tests/Feature/Kiosk/KioskLoginEnumerationTest.php` (4) + 12 existing login/security tests still green |
| F4 offline enqueue-during-sync race | Low | **FIXED** | `helpers/kioskOfflineQueue.js` (snapshot + re-merge) | `tests/js/kioskOfflineQueueSyncRace.spec.js` (2) |
| F5 virtual keyboard no Shift key | P2 | **FIXED** | `ds/KsVirtualKeyboard.vue` (Shift button) | `tests/js/ksVirtualKeyboardShift.spec.js` (3) |
| F6 Waiting markReady leaves preparing-redirect timer | P2 | **FIXED** | `KioskWaitingComponent.vue` (markReady stops preparing+elapsed timers) | `tests/js/kioskWaitingMarkReadyTimer.spec.js` (2) — **proven fail-on-unhealed** → pass |

## Test results (real, this checkout)
- **Frontend (vitest, source-level): 13 files / 69 tests PASS** — 58 pre-existing kiosk specs (zero regression) + 11 new (F1/F4/F5/F6).
- **Backend (phpunit, sqlite :memory:): 27 PASS** — 23 pre-existing kiosk Feature + 4 new (F3). Existing login/security suites (6+2+2+2) re-run green after the F3 reorder.
- **F1 & F6 proven as real regressions** (revert→fail, restore→pass).

## Browser E2E — NOT green, but external to this work
`tests/e2e/03-kiosk-wizard.spec.js`: 1 pass / 4 fail. Cause: `/kiosk/login` returns **HTTP 500** on the live :8000 app (dev checkout never had its frontend built — Vite manifest missing). The served `public/` bundle **predates these source edits** (F5 marker absent from `public/`), so it runs original JS and neither reflects nor regresses the fixes. These failures are app-setup (build + seed), not the borne findings.

## To make browser-E2E green (separate task)
`npm run build` (compile the source incl. these heals into `public/`), seed a kiosk machine + menu, then re-run `PLAYWRIGHT_NO_WEB_SERVER=1 playwright test tests/e2e/03-kiosk-wizard.spec.js`.

## Working-tree note
The 5 kiosk source files + login controller + 5 test files are my changes. Pre-existing uncommitted KDS/sync working-tree changes on this branch are NOT mine (untouched). Nothing committed (awaiting owner OK).
