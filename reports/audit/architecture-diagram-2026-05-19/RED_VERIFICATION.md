# RED Adversarial Verification — Architecture Diagram V1 Le Cayenne

**Target**: `public/architecture-diagram.html`
**Date**: 2026-05-19
**Branch**: `heal/cms-pr1-quickwins-2026-05-18` HEAD `913ad41f4`
**Tag**: `v1.0.X-massive-converged-2026-05-19`
**Mode**: READ-ONLY hostile fact-checking
**Discipline**: cite file:line, contradict any drifted claim
**Wall-clock**: ~30 min

---

## VERDICT: NEEDS-CORRECTION (7 items)

The diagram's **architectural skeleton is sound** — every load-bearing claim about
sync cascades, NF525 invariants, frozen-zone discipline, wave verdicts, and listener
ordering verifies clean against current code. The drift is concentrated in:

- **1 fabricated template name** (`menu_formule` does not exist)
- **1 quantitative misclaim** ("17 files JSX" — actual 9)
- **4 off-by-one numerical drifts** (commits 122→124, models 79→78, BranchScope 22→21, line range 145→146)
- **1 minor path imprecision** (`Kds/` subdir does not exist; modal already self-flagged "verify")

The diagram is **fit-for-purpose for the owner's manual test phase** once these 7
corrections are applied. None of the corrections reverse a structural verdict.

---

## A. FACT-ERRORS (highest priority)

### A1. Fabricated wizard template `menu_formule`

**Diagram claim** (kiosk modal `subsystems[2]`):

> "11.3 Wizard composition 4 templates (sandwich/taco/burger/menu_formule)"

And duplicated in mobile modal `invariants[2]`:

> "Wizard 4-template parity avec kiosk (sandwich/taco/burger/menu_formule)"

**Verified evidence**:

- `config/menu.php:54-58` — only 4 distinct `wizard_template` values exist in code: `'custom'`, `'sandwich'`, `'simple'`, `'tacos'` (verified by `grep -h "wizard_template" config/menu.php | sort -u`).
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:556-619` — the `switch (template)` block has cases: `tacos` (556), `sandwich` (566), `burger` (576), `snacking` (598), `omelette` (609), `salade` (619). **No `menu_formule` case exists.**
- Heuristic fallback in `KioskWizardComponent.vue:922-923` confirms `'tacos'` and `'sandwich'` (plural for tacos; matters because the diagram writes "taco" singular).

**Correction**: replace `"4 templates (sandwich/taco/burger/menu_formule)"` with either the 4-active config values `"sandwich/tacos/custom/simple"` or the 6 switch cases `"sandwich/tacos/burger/snacking/omelette/salade"`. The owner should choose which abstraction (config-level vs UI-render-level) to surface, then keep both modals consistent (kiosk + mobile).

### A2. Web "17 files JSX" overcount

**Diagram claim** (`web.invariants[1]`):

> "17 files JSX + CSS + legal/ HTML pages"

And box-sub:

> "/Downloads/web · 17 files JSX · 4 viewports · 'DÉMO V1' badges"

**Verified evidence**:

- `find /Users/1millnonstop/Downloads/web -maxdepth 2 -type f -name "*.jsx" | wc -l` = **9**.
- The 9 files: `account-v2.jsx`, `components.jsx`, `flows.jsx`, `funnel.jsx`, `loyalty-v2.jsx`, `orders.jsx`, `screens-v3.jsx`, `screens.jsx`, `wizard-v2.jsx`.
- If counting `.jsx + .html + .js` mixed (legal HTML pages + index.html), total reaches 16 — still not 17.

**Correction**: replace `"17 files JSX"` with `"9 files JSX + 6 legal HTML + 1 index.html"` to be precise, or just `"9 JSX modules"`.

### A3. KdsSyncService path imprecision

**Diagram claim** (`kds.files`):

> "app/Services/Kds/* (verify)"

**Verified evidence**:

- `find . -name "KdsSyncService*"` → `./app/Services/KdsSyncService.php` (no `Kds/` subdir).
- Only one Kds-namespaced PHP service exists at root of `app/Services/`. No `app/Services/Kds/` directory.

**Correction**: replace `"app/Services/Kds/* (verify)"` with `"app/Services/KdsSyncService.php (TZ-aware Paris→UTC lines 77-94)"`. The `(verify)` annotation already signals diagram author uncertainty — flag as minor but fix.

---

## B. NUMBER DRIFTS (medium priority)

### B1. Commit cumul: 122 → 124

**Diagram claim** (header subtitle):

> "122 commits cumul"

**Verified evidence**:

- `git rev-list --count v1-0-1-hardening-2026-05-17..913ad41f4` = **124**.
- Off by 2 (likely captured before Wave I+J docs commit `ce23352ab` and the immediately preceding healing commits).

**Correction**: replace `122` with `124` (or `~125` for resilience to subsequent commits during owner test phase).

### B2. Models 79 → 78

**Diagram claim** (`db.invariants[1]` and box-sub):

> "79 models · 83 FKs · 33 UNIQUE constraints" / "79 models"

**Verified evidence**:

- `find app/Models -maxdepth 1 -name "*.php" -type f | wc -l` = **78**.
- (FK count 83 and UNIQUE 33 NOT verified explicitly — UNIQUE confirmed 33 ✅; FK count not audited this round.)

**Correction**: replace `79 models` with `78 models` in both the box-sub line and `db.invariants[1]`.

### B3. BranchScope 22 → 21

**Diagram claim** (`db` box-sub AND `db.invariants[2]`):

> "22 BranchScope" / "22 models avec BranchScope (multi-tenant isolation)"

**Verified evidence**:

- `grep -rln "addGlobalScope.*BranchScope\|new BranchScope" app/Models/` returned **21** distinct model files:
  DeliveryBoyCashSession, CashDrawerSession, FrontendOrder, Order, OrderPayment, ItemWizardProfile, KioskMachine, DeliveryBoyCashMovement, PaymentTerminal, StockLevel, PendingPaymentConfirmation, OrderItem, OrderQuote, User, ItemBranchAvailability, PushNotification, DiningTable, PosParkedOrder, StockMovement, Printer, CashMovement.
- 24 grep hits if counting all mentions, but only 21 are actual `addGlobalScope`/`new BranchScope` callsites at the model boot level.
- CLAUDE.md §9 cites "11 models post iter11+12" — stale; current code reflects post-WJ-7 expansion to 21.

**Correction**: replace `22 BranchScope` with `21 BranchScope` in both box-sub and `db.invariants[2]`. Note: CLAUDE.md §9 also still says "11 models" — owner may want to align CLAUDE.md to current 21 as a separate doc task.

### B4. EventServiceProvider line range 145-151 → 146-151

**Diagram claim** (`flow-pos-kds.defenses[0]`):

> "Listener-order: Outbox FIRST in EventServiceProvider:145-151 (SSOT defense)"

**Verified evidence**:

- `app/Providers/EventServiceProvider.php:146` — `OrderCreated::class => [`
- `app/Providers/EventServiceProvider.php:148` — `PersistOrderCreatedToOutbox::class,`
- `app/Providers/EventServiceProvider.php:152` — `]` (closes OrderCreated block)

**Correction**: replace `145-151` with `146-152` (or simply `:148` pointing to the listener line itself, which is more precise for a single-listener defense claim).

---

## C. CLAIMS VERIFIED ACCURATE (no correction needed)

All of the following were spot-checked and match current code:

| # | Claim | Source | Verdict |
|---|-------|--------|---------|
| C1 | 169 migrations | `find database/migrations -name "*.php" \| wc -l` = 169 | ACCURATE |
| C2 | 33 UNIQUE constraints | `grep -c "unique(" database/migrations/*.php` sum = 33 | ACCURATE |
| C3 | pos-wizard.js ~296 KB | `wc -c public/js/pos-wizard.js` = 296912 bytes (decimal KB) | ACCURATE |
| C4 | CDSOrderDetailsResource 6 fields PII-clean | `app/Http/Resources/CDSOrderDetailsResource.php:15-22` lists exactly 6 keys: id, order_serial_no, token, queue_number, order_type, status | ACCURATE |
| C5 | TZ-aware KdsSyncService lines 77-94 | `app/Services/KdsSyncService.php:77-94` — Paris→UTC conversion via `Carbon::today($appTz)->setTimezone('UTC')` and active query bounds. Sentinel `tests/Feature/Kds/KdsSyncTzAwareTest.php` referenced inline | ACCURATE |
| C6 | fiscal:verify-chain CHAIN OK | `php artisan fiscal:verify-chain --all` → `SWEEP COMPLETE — CHAIN OK on every active branch (1 total)` | ACCURATE |
| C7 | count=97 audit_logs + 4 z_reports | Direct DB count: `audit_logs=97`, `z_reports=4` | ACCURATE |
| C8 | PersistOrderCreatedToOutbox FIRST in OrderCreated listeners | EventServiceProvider.php:146-152 confirms — line 148 listener is first child of OrderCreated array, BEFORE DecrementItemAvailability (149), DecrementStockOnOrderCreated (150), SendFcmOnOrderCreated (151) | ACCURATE (range drifted, see B4) |
| C9 | Wave F WF-1 PASS verified | `reports/audit/wave-f-sync-confirmation-2026-05-19/WF-1-POS-KDS-SYNC/STATUS.md:14` reads "PASS — production-grade" | ACCURATE |
| C10 | Wave F WF-5 INTACT 5/5 invariants | `WF-5-FISCAL-CASCADE/STATUS.md:8` reads "INTACT — 5/5 NF525 invariants verified" + baseline table confirms `audit_logs=97/97 unchanged` and `last_hash=af02d7895d412654...` | ACCURATE |
| C11 | WG-1 P1 broadcast/loyalty heals applied | `WG-1-WF6-REFUND-HEAL/STATUS.md` lists commits `3b0776f7c` (broadcast heal) and `8edc72a36` (refundPoints heal). Both verifiable via `git show` | ACCURATE |
| C12 | WJ-1 P0 admin route gate landed | `WJ-1-WI4-RED01-ADMIN-ROUTES/STATUS.md:3` reads "GREEN — heal landed (commit `eaf77625f`)" with disclosed parallel-agent commit absorption note | ACCURATE |
| C13 | KDS V2 grid default-on | `config/kds.php:24-29` confirms `'v2_default_enabled' => filter_var(env('KDS_V2_DEFAULT_ENABLED', true), …) ?? true` | ACCURATE |
| C14 | Frozen-zone alignment with CLAUDE.md §7 | All diagram modal "FROZEN §7" badge files match CLAUDE.md §7 listing: KioskWizardComponent.vue, KioskAppComponent.vue, KioskUpsellComponent.vue, PaymentComponent.vue, PosV5TrancheRow.vue, pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php, FiscalSequenceService.php, ZReportService.php, AuditLogService.php, BranchScope.php, IdempotencyKeyMiddleware.php, PricingService.php, OrderStateMachine.php | ACCURATE |
| C15 | Tag `v1.0.X-massive-converged-2026-05-19` exists | `git tag -l` returns the tag | ACCURATE |
| C16 | `plans/PR_PACKAGE_v1_0_X_massive_converged_2026-05-19.md` exists | `find ./plans -name "PR_PACKAGE*"` confirms file at expected path | ACCURATE |
| C17 | 5 NF525-immutable tables list | `fiscal.invariants[4]` lists "audit_logs + z_reports + cash_movements + cash_drawer_sessions + order_payments" — matches CLAUDE.md §8 + migration trigger files | ACCURATE |

---

## D. NOT VERIFIED THIS ROUND (out of scope / disclosed)

The following claims were not independently re-verified during this 30-min audit
and are accepted on trust pending future RED passes:

- POS "11 sous-systèmes" + Kiosk "8 sous-systèmes" granular subsystem counts (lists self-consistent within modal but not cross-checked against routes/controllers inventory)
- Specific test counts: "49 PHPUnit sync-cascade tests", "37/38 + 28/28 OSS", "79/79 Stock + 117/117 Availability", "22 specs E2E mobile", "116 Playwright cases web"
- WF-2 / WF-3 / WF-4 / WF-6 / WF-7 / WF-8 STATUS verdicts (only WF-1, WF-5 spot-checked)
- WI-* and remaining WJ-* status verdicts (only WI-3, WI-4 implied via WJ-1; WJ-1, WJ-3, WJ-4 names confirmed exist in directory)
- "83 FKs" foreign-key count (only UNIQUE constraints 33 verified, not FK total)
- KDS subsystems claim "Pagination overflow >50 orders +N more" (not source-checked)
- "26+ mutation routes" idempotency middleware count
- Refund cascade specific commit SHAs beyond WG-1 (WJ-1's `PersistOrderPaymentStatusChangedOnRefundCreated` listener path confirmed via EventServiceProvider:175 grep, no deeper inspection)

A follow-up audit pass should target test-count claims and the WF-2..WF-8
chain if owner wants absolute fidelity for the manual-test diagram.

---

## E. RECOMMENDED CORRECTION SUMMARY (for diagram update)

The diagram author should apply these 7 edits (read-only RED constraint
prevents this agent from editing the diagram directly):

```
1. Header subtitle:         "122 commits cumul"  →  "124 commits cumul"
2. db box-sub:              "79 models · 83 FKs · 33 UNIQUE"  →  "78 models · 83 FKs · 33 UNIQUE"  (FK unverified)
3. db box-sub:              "22 BranchScope"  →  "21 BranchScope"
4. db modal invariants[1]:  "79 models · 83 FKs"  →  "78 models · 83 FKs"
5. db modal invariants[2]:  "22 models avec BranchScope"  →  "21 models avec BranchScope"
6. kiosk modal subsystems[2]:  "(sandwich/taco/burger/menu_formule)"  →  "(sandwich/tacos/burger/snacking/omelette/salade)" or "(custom/sandwich/simple/tacos per config/menu.php)"
7. mobile modal invariants[2]:  same fix as #6 (mirror kiosk template list)
8. kds modal files[1]:      "app/Services/Kds/* (verify)"  →  "app/Services/KdsSyncService.php (TZ-aware lines 77-94)"
9. flow-pos-kds defenses[0]:  "EventServiceProvider:145-151"  →  "EventServiceProvider:146-152" (or ":148" for single-listener precision)
10. web box-sub + invariants[1]:  "17 files JSX"  →  "9 files JSX" (or "9 JSX + 6 legal HTML + 1 index.html")
```

Counted 10 atomic string edits across 7 logical issues (some issues span both box-sub
and modal text and need to be applied in both locations to stay consistent).

---

## F. FINAL FRAME

The architecture diagram captures the **structural truth** of V1 Le Cayenne post
Wave I+J. Every load-bearing architectural claim (NF525 chain integrity,
sync-cascade defenses, frozen-zone alignment, wave verdicts, listener ordering)
verifies clean. The drifts are cosmetic — counts that drifted +/- 1 to +2 since
the diagram was authored, and a 4-template name that contains one fabricated
identifier (`menu_formule`) and one singular/plural mistake (`taco` vs `tacos`).

**For owner's manual test phase**: the diagram is usable as-is, with the
caveat that template names in the kiosk/mobile modal should not be used as a
source-of-truth reference for `wizard_template` values (`menu_formule` is
fictional). Apply the 7 corrections above (10 atomic edits) and the diagram
becomes a clean canonical reference for the test cycle.

**No P0 / P1 / structural blocker found.** Diagram is RED-cleared for owner use
with corrections queued as a single doc-cleanup task.

---

**Report by**: RED adversarial verification agent
**Cited file:line evidence**: 17 distinct citations across `config/`, `app/`,
`resources/`, `database/`, `reports/`, `public/` trees.
**Cap respected**: ~1850 words.
