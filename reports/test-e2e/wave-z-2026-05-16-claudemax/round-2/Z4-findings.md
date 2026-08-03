# Z4 — OSS Order Status Screen (Round 2 Wave Z findings)

**Auditor**: Z4 sub-agent (read-only, adversarial — Round 2 verification)
**Branch**: feature/mobile-app-le-cayenne-2026-05-10
**HEAD**: 56204f052
**Round 1 doc**: `…/round-1/Z4-findings.md`
**Verdict**: **GO** — both P1 gates closed; one new minor (P3) found during RED-team pass; no regressions from Wave Z heals.

---

## Verification summary

| Finding | R1 Severity | R1 Verdict | R2 Result |
|---|---|---|---|
| Z4-P1-01 raw `label.popular_menu_items` | P1 | FALSE FINDING | **Confirmed false** — 5 JSON hits |
| Z4-P1-02 non-deterministic order | P1 | HEALED `d424f8402` | **Confirmed healed** — orderBy applied to both methods |
| Z4-P2-03 stale PREPARED orders | P2 | DEFERRED V1.0.1 | Unchanged — file:line stable |
| Z4-P2-04 cross-branch popularity | P2 | DEFERRED V1.0.1 | Unchanged — file:line stable |
| Z4-P2-05 branch enumeration disclosure | P2 | DEFERRED V1.0.1 | Unchanged — file:line stable |
| Z4-P2-06 AR i18n missing | P2 | DEFERRED V1.0.1 | Unchanged + see NEW-001 |

---

## 1) Z4-P1-01 — `label.popular_menu_items` raw — FALSE FINDING confirmed

**Grep result** (`grep -rn 'popular_menu_items' resources/js/languages/`):
```
resources/js/languages/en.json:958:        "popular_menu_items": "Articles à préparer",
resources/js/languages/de.json:717:        "popular_menu_items": "Beliebte Menüpunkte",
resources/js/languages/bn.json:717:        "popular_menu_items": "জনপ্রিয় মেনু আইটেম",
resources/js/languages/fr.json:833:        "popular_menu_items": "Articles à préparer",
resources/js/languages/ar.json:795:        "popular_menu_items": "عناصر القائمة الشعبية",
```
5/5 language files contain the key. Vue usage at `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue:10` (`{{ $t("label.popular_menu_items") }}`) resolves correctly in all 5 locales — no raw-label leak.

**Round 1 misclassification cause** (post-mortem): Round 1 auditor checked `lang/fr/all.php`, `lang/en/all.php`, `lang/ar/all.php` (PHP server-side translation files) and correctly noted absence. They missed that this Vue component uses the JS `$t()` Vue-I18n loader, which resolves against `resources/js/languages/*.json` — a separate translation namespace. Confirmed false-positive — no fix required, no commit needed.

**Dismissal recorded**: Wave-Z Sprint 5C commit message (`d424f8402`) explicitly documents the dismissal in its trailing note.

---

## 2) Z4-P1-02 — Non-deterministic OSS display order — HEALED

**File checked**: `app/Services/OrderStatusScreenOrderService.php`
- `list()`  L77: `$query->orderBy('queue_number', 'asc')->orderBy('id', 'asc');`
- `listForBranch()` L135: `$query->orderBy('queue_number', 'asc')->orderBy('id', 'asc');`

Both methods have a deterministic FIFO sort. The Sprint 5C choice of `queue_number` (rather than the R1-recommended `order_datetime`) is **stronger** — `queue_number` is the customer-visible token displayed on the wall; sorting by it guarantees the token sequence on screen matches the printed receipt sequence (a fast-food UX invariant). `id` tiebreaker covers the edge case of two orders sharing a queue number across kiosk + POS within the same second. Comment annotation `[Sprint 5C Z4-P1-02 2026-05-16]` traces the fix to the right ticket.

**Tests run**:
- `tests/Feature/Sentinels/OssAdminBranchPolicySentinelTest.php` — 3/3 green (`OK (3 tests, 6 assertions)`)
- `tests/Feature/OSSReadOnlyTest.php` — 1/1 green (`OK (1 test, 2 assertions)`)

**Healed. Close.**

---

## 3) Deferred V1.0.1 items — confirm file:line unchanged

| Finding | R1 file:line | R2 file:line | Status |
|---|---|---|---|
| Z4-P2-03 stale prune | `OrderStatusScreenOrderService.php:53-65, 111-120` | `OrderStatusScreenOrderService.php:53-65, 118-127` (lines slightly shifted by the 4-line orderBy comment block — semantically identical) | Defer confirmed |
| Z4-P2-04 cross-branch pop | `OrderStatusScreenOrderService.php:84` | `OrderStatusScreenOrderService.php:91` (shifted by 7) | Defer confirmed |
| Z4-P2-05 branch enumeration | `routes/api.php:1099-1104`, `OrderStatusScreenController.php:75-100` | Unchanged | Defer confirmed |
| Z4-P2-06 AR i18n missing | `lang/ar/all.php` | Unchanged | Defer confirmed |

All four deferred items have **no behavior change** between Round 1 and Round 2. Line shifts in items 03/04 are purely the side-effect of the Sprint 5C 5-line comment block inserted at L72-77 — the actual flagged code is byte-identical.

---

## 4) RED-team pass — Wave Z heal side-effect scan on OSS

**Sweep**: Commits between `c3ba89863` (R1 HEAD) and `56204f052` (R2 HEAD) that touched OSS surface or its data dependencies.

- `d424f8402` (5C) — **only** OSS file touched is `OrderStatusScreenOrderService.php` (the heal itself). No side-effect risk.
- `56204f052` (5D) — `auth token revoke on relogin (Z6-01)` — touches Sanctum kiosk token plumbing. OSS public endpoint uses **no auth token** (`/api/frontend/oss-order` is `throttle:120,1` unauth, per `routes/api.php:1099-1104`). Admin endpoint uses `permission:order-status-screen` middleware (session-cookie auth, not Sanctum). **No coupling — OSS unaffected.**
- Sprint 2A/2B/3C delivery enrichment (`a8b363dd6`, `5f48856f9`, `c3ba89863`) — touches `SimpleOrderResource`. OSS uses **`CDSOrderDetailsResource`** (verified `OrderStatusScreenController.php:104, 119`), not `SimpleOrderResource`. No coupling.
- Order model (`app/Models/Order.php`) — `git log` shows no commits between R1 and R2 HEAD on this file or `CDSOrderDetailsResource` / `CDSPopularItemResource`. **No regression vector.**

**No Wave Z heal regressed OSS.**

---

## 5) New issues discovered

### NEW-Z4-01 (P3) — EN locale serves French translation for `popular_menu_items`
**File**: `resources/js/languages/en.json:958`
```json
"popular_menu_items": "Articles à préparer",
```
The French phrase `"Articles à préparer"` is the value in the **EN** file. Other JSONs translate correctly (`de.json` → German, `bn.json` → Bengali, `ar.json` → Arabic). An EN-locale customer wall will render the French string in the popular-items header.

**Severity rationale**: P3 not P1 because (a) FR is the default V1 locale for Le Cayenne, (b) no raw-key leak (resolved string is human-readable), (c) the rest of EN OSS strings (`preparing`, `ready`, `oss_main_aria`, `oss_popular_region_aria`) are correctly translated in EN. Visual impact only affects English-deployed branches.

**Fix (V1.x i18n cleanup)**: change EN value to `"Popular menu items"` or `"Items to prepare"`.

**Defer**: Single-line edit; bundle with the Z6/Z10 i18n cleanup PR or the broader cash-session EN parity work already in flight (`Z1-NEW-001` per `d424f8402`).

---

## 6) Acceptance evidence

- `git rev-parse HEAD` → `56204f052b14e97a635638d3a546c156a805b1ce` (matches mission spec).
- `grep -rn 'popular_menu_items' resources/js/languages/` → 5 hits ✓
- `OrderStatusScreenOrderService.php` line 77 & line 135 contain `->orderBy('queue_number', 'asc')->orderBy('id', 'asc')` ✓
- `OssAdminBranchPolicy` 3/3 green ✓
- `OSSReadOnlyTest` 1/1 green ✓
- No frozen-zone files touched in Wave Z heals on OSS surface ✓ (no fiscal, no PricingService, no BranchScope core, no IdempotencyKey).
- No new console.* warnings introduced; no SimpleOrderResource leak into OSS.

---

## 7) Convergence verdict

**GO for V1 ship (OSS)**:
- 0 P0
- 0 P1 (both healed/dismissed)
- 0 new P2
- 1 new P3 (`NEW-Z4-01` EN translation typo — bundle with V1.x i18n cleanup)
- 4 P2 deferred to V1.0.1 (3 file:line stable, 1 shifted by Sprint 5C comment only)

OSS surface is **production-ready** for V1. Pre-existing P3 hygiene items (`P3-Z4-07` through `P3-Z4-10` from Round 1) remain valid backlog candidates for a V1.x design-system pass.

---

**Report path**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/wave-z-2026-05-16-claudemax/round-2/Z4-findings.md`
