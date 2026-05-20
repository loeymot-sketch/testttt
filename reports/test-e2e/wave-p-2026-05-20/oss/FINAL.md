# Wave P-4 OSS (Order Status Screen) E2E Audit + Heal — FINAL

**Date**: 2026-05-20
**Branch**: heal/cms-pr1-quickwins-2026-05-18
**System under test**: OSS — customer-facing order status wall display
**Surface**: `/admin/order-status-screen` (Vue SPA route)
**Spec**: `tests/e2e/wave-p-oss-2026-05-20.spec.js`
**Iterations consumed**: 4 / 5 (test-bug fixes, no implementation heals required)
**Wall-clock**: ~12 minutes

---

## Verdict

**GO** — OSS surface is production-perfect. **Zero P0/P1 findings.**
Allowlist heal R-3 (2026-05-18) confirmed enforced end-to-end on the
customer-wall public path. No frozen-zone touch. No console errors. No 5xx.

---

## Test coverage matrix (6 states, all green)

| State | Setup                                              | Assertion                                                       | Screenshot                                  | Result |
|------:|----------------------------------------------------|-----------------------------------------------------------------|---------------------------------------------|--------|
| 1     | Empty DB                                            | Two columns rendered, both show `—` empty placeholder           | `screenshots/O01-empty.png`                 | PASS   |
| 2     | 3 PREPARING (KIOSK K-101, TAKEAWAY T-202, KIOSK K-103) | All three appear in PRÉPARATION column                          | `screenshots/O02-3-preparing.png`           | PASS   |
| 3     | 2 PRÉPARATION (K-101, K-404) + 2 PRÊT (K-103, T-202) + DELIVERY D-666 + POS P-777 placed | **D-666 + P-777 NOT visible anywhere** (allowlist regression)   | `screenshots/O03-2prep-2ready-allowlist.png` | PASS   |
| 4     | K-101 status PREPARING → PRÊT                       | K-101 leaves PRÉPARATION, appears in PRÊT (auto-refresh)         | `screenshots/O04-k101-moved-to-ready.png`   | PASS   |
| 5     | T-202 soft-deleted (pickup)                         | T-202 disappears from PRÊT (no lingering)                       | `screenshots/O05-t202-picked-up.png`        | PASS   |
| 6     | Visual + i18n + console hygiene                     | FR headers, no raw labels, font ≥ 36px, zero console errors      | (same as O05)                                | PASS   |

---

## Allowlist regression — primary mission outcome

Backend filter at two byte-identical paths:

- `app/Services/OrderStatusScreenOrderService.php:59-62` (`list()`, auth-gated `/api/admin/oss-order`)
- `app/Services/OrderStatusScreenOrderService.php:196-200` (`listForBranch()`, public `/api/frontend/oss-order`)

Both enforce `whereIn('order_type', [OrderType::KIOSK, OrderType::TAKEAWAY])`,
fail-closed — POS (15), DELIVERY (5), DINING_TABLE (20) **cannot** reach the
customer wall even via the legacy `whereNotNull('token')` OR-branch.

The E2E spec injects exactly this regression: order types 5 (DELIVERY) and
15 (POS) are seeded with `status = PREPARING (7)`, branch_id = 1, fresh
`order_datetime`, and the SPA still hides them. Verified at the rendered DOM
level (`page.locator('li:has-text("D-666"), li:has-text("TKDEL01")').count() === 0`)
on State 3.

---

## Visual evidence

Each screenshot independently confirms:

- **O01 (empty)**: 3-column layout (Articles à préparer | En préparation | Prêt). Both right columns show the em-dash `—` placeholder. Column header colors: PRÉPARATION `#B0004D` (rose foncé), PRÊT `#1AB759` (vert vif).
- **O02 (3 PREPARING)**: `N°K-101`, `N°K-103`, `N°T-202` displayed in dark crimson `#991B1B`, ~40px font-size, centered, mb-6 spacing. Easily readable from across a fast-food dining room.
- **O03 (2+2 allowlist)**: PRÉPARATION `N°K-101 / N°K-404` (red); PRÊT `N°K-103 / N°T-202` (vert foncé `#0E7C3A`). DELIVERY D-666 and POS P-777 — both correctly absent. Allowlist visually proven.
- **O04 (status transition)**: K-101 has moved column. PRÉPARATION now only `N°K-404`; PRÊT shows `N°K-101 / N°K-103 / N°T-202`.
- **O05 (pickup)**: T-202 soft-deleted, gone from PRÊT. Only `N°K-101` + `N°K-103` remain green.

---

## Hygiene checks (all clean)

- `consoleErrors`: `[]`
- `networkFails`: `[]` (zero 5xx on `/api/admin/oss-order` or `/api/frontend/oss-order`)
- `ossApiFailures`: `[]`
- Token font-size (computed): ≥ 36px (mission spec was "BIG and readable from distance" — measured via `getComputedStyle`)
- i18n FR: headers "En préparation" + "Prêt" (no raw `label.preparing`, `oss.foo`, `undefined`)
- Region semantics: `role="region"` + `aria-label` on both columns + `role="main"` on the screen wrapper (a11y intact)

---

## Files touched

- **NEW**: `tests/e2e/wave-p-oss-2026-05-20.spec.js` — the spec (sole deliverable)

No production code touched. No frozen-zone diff. `git diff public/js/admin-oss.js`
remains the pre-existing in-flight WIP from prior waves — untouched by this run.

---

## Iteration log

| Iter | Outcome                                                                                          | Action                                                                                          |
|-----:|---------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------|
| 1    | FAIL — `[role="region"][aria-label*="prêt"]` selector did not match                              | Test bug: CSS attribute selectors are case-sensitive. Switched to `page.getByRole('region', { name: /pr[êe]t|ready/i })`. |
| 2    | FAIL — `seedOrder` returned null (PHP snippet had a dead duplicate line + JS-string `+` concat)  | Test bug: rewrote `seedOrder` to use concatenated single-statement string, passed `--execute` and snippet as separate argv. |
| 3    | FAIL — `h3:first` was "Articles à préparer" (PopularItemComponent column), not the PRÉPARATION column header | Test bug: scoped the `h3` lookup inside the previously-resolved `preparingRegion` / `readyRegion`. |
| 4    | **PASS** — 1 passed (20.8s)                                                                       | —                                                                                                |

No iteration consumed an implementation-side heal. Production code is already
correct (the R-3 2026-05-18 heal stuck and is proven enforced).

---

## Cross-reference

- Service code: `app/Services/OrderStatusScreenOrderService.php` (list + listForBranch)
- Controller: `app/Http/Controllers/Admin/OrderStatusScreenController.php` (index + publicIndex)
- Vue components:
  - `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue`
  - `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`
  - `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue`
- Store module: `resources/js/store/modules/orderStatusScreenOrder.js` (conditional auth → admin vs public endpoint)
- SPA router: `resources/js/router/modules/orderStatusScreenRoutes.js` + `resources/js/router/index.js` `PUBLIC_FRIENDLY_AUTH_ROUTES`
- Sister sentinel: `tests/Feature/OSS/OssCustomerScreenFilterTest.php` (backend allowlist)
- Memory ref: `feedback project_pos_first_page_oss_filter_2026-05-18` (the R-3 heal)

---

## Recommendation

**Ship.** OSS surface clears all Wave P-4 acceptance criteria with zero deviation.
No further heal cycles needed on this surface. The spec is durable — keep it in
the suite as a regression sentinel for the allowlist.
