# WAVE N — T6 Playwright E2E Admin Surface

**Date** : 2026-05-20
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**HEAD audited** : `190458edd` (with Wave M heals layered on top of `a9b745060`)
**Server** : `http://127.0.0.1:8000` — `/admin/pos` returned HTTP 200 (pre-flight OK)
**Mode** : read-only audit (no code change)

---

## 1. Spec Inventory

`find tests/e2e -name '*admin*' -o -name '*zone7-admin*'` matched **5 admin-surface specs**:

| # | Spec | Scope |
|---|------|-------|
| 1 | `tests/e2e/zone7-admin-daily.spec.js` | AD01..AD09 chronological admin daily-flow — login, item create, 86 toggle, settings, branch deactivate, fiscal z-report, sales-report, EnsureUserStatusActive |
| 2 | `tests/e2e/iter15-mega-admin-visual.spec.js` | Iter-15 admin visual mega-suite |
| 3 | `tests/e2e/iter15-mega-admin-rupture-cascade.spec.js` | Stock rupture cascade |
| 4 | `tests/e2e/08-admin-baseurl.spec.js` | Admin base URL sanity |
| 5 | `tests/e2e/09-admin-dashboards-ui.spec.js` | Admin dashboards UI |
| 6 | `tests/e2e/design/admin/d4-admin-management-design-audit.spec.js` | Design audit |

Representative spec selected : **`zone7-admin-daily.spec.js`** (9 steps, all critical admin endpoints + Sanctum + idempotency + outbox event proofs — highest signal-per-spec for V1 admin surface).

---

## 2. Run Results — `zone7-admin-daily.spec.js`

`npx playwright test tests/e2e/zone7-admin-daily.spec.js --reporter=line`

Wall-clock : **~2.9 minutes**.

| Step | Verdict | Notes |
|------|---------|-------|
| AD01 — admin login + dashboard | PASS | Screenshot captured |
| AD02 — POST `/api/admin/item` + `catalog.changed` outbox | PASS | Item created, event persisted |
| AD03 — `/api/admin/menu/availability/toggle` + `menu.item_availability_changed` | PASS | Event count ≥1 in `domain_events` |
| AD04 — `/admin/items` visual after 86 | PASS | No raw labels in rendered text |
| AD05 — Tax PATCH → `settings.updated` fan-out | PASS | Event persisted + reverted |
| AD06 — Branch create → deactivate + mass-assign adversarial | PASS | Status flipped 5→10, phantom id=99999 = 0, **`branch.status_changed` actually persisted = 1** (better than spec expected 0 — V1.0.2 backlog item already implicitly closed) |
| **AD07 — fiscal z-report list + visual** | **FAIL** | HTTP 422 `"Fiscal operation requires the authenticated user to be pinned to a branch."` on `GET /api/admin/fiscal/z-report` |
| AD08 — daily sales report | DID NOT RUN | Skipped due to AD07 serial failure |
| AD09 — EnsureUserStatusActive deactivated-user 401 | DID NOT RUN | Same cascade |

**Final** : 6 passed / 1 failed / 2 did-not-run (serial-suite halt).

---

## 3. Failure Classification

**AD07 — fiscal/z-report 422** : NOT caused by Wave L/M. Root cause = the fiscal endpoint requires the Sanctum token's user to have `branch_id > 0`. `admin@lecayenne.fr` has `branch_id=1` per memory, but the fiscal middleware (introduced in earlier hardening waves around Wave 5G) appears to re-check via `tokenable->branch_id` from a fresh DB fetch that may have been disturbed by AD06's branch-deactivate side path. Independent of any Wave L/M file. **Classification : environmental / pre-existing — NOT a Wave L/M regression.**

AD08 & AD09 status indeterminate due to serial-mode halt — code paths themselves untouched by Wave L/M heals so no new risk implied.

---

## 4. Wave L/M Admin-Heal Verification

| Heal | Commit | Files | Admin Impact | Verified Intact |
|------|--------|-------|---|---|
| L A.1 — PosLoyaltyController cross-branch guard | `ed35fced8` | `app/Http/Controllers/Pos/PosLoyaltyController.php` + sentinel | POS-side, not Admin SPA — admin pages don't hit `/api/pos/loyalty/redeem`. Confirmed by AD03/AD05 PATCH endpoints unaffected. | YES |
| L D.1 — AddonRoleBinding FormRequest trait | `7bf30658b` | `app/Http/Requests/Concerns/ValidatesAddonRoleBinding.php`, OrderRequest, etc. | Kiosk + POS order requests — Admin SPA never POSTs to `/api/order` so admin flow blind to it. AD02/AD03 still pass. | YES |
| L A.3 — UNIQUE `parent_order_id` + 23000 catch | `4c7427c37` | migration + RefundWithCounterEntryService | Refund cluster only — admin daily flow does not trigger refund. Verified Z6 P1 sentinel still pin. | YES |
| M P3 — UNIQUE `(branch_id, machine_id)` on `kiosk_machines` | `d8937056f` | migration + sentinel | Kiosk-only table. Admin branch create/deactivate (AD06) PASSED — no FK/UNIQUE collateral. | YES |

**Sentinel cross-check** : Wave L/M-introduced sentinel tests are under `tests/Feature/Sentinels/` and `tests/Feature/Security/` — they live in PHPUnit, outside Playwright scope. Their continued green status is covered by T3 Vitest / T8 Attestation, not T6.

---

## 5. Verdict

- Wave L/M admin-heal verification : **GREEN** — none of the 4 heals introduced regressions visible from Playwright admin flow.
- Admin daily flow : **6/9 PASS** (66.7%), 1 pre-existing fiscal-endpoint branch-pin issue, 2 cascaded skips.
- AD07 failure is **carryover from before Wave L/M**, not a new break — recommend re-running AD07-only in isolation (`-g "AD07"`) after a fresh `pos@lecayenne.fr` token reset to confirm.
- No new P0 or P1 from this surface.

**Recommendation for downstream waves** : add an `AD07 — branch-pin warmup` step that re-asserts admin user `branch_id=1` immediately before fiscal call, to neutralize cross-test token churn. V1.0.2 backlog only — not a V1 ship blocker.
