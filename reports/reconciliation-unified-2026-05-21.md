# Reconciliation Unified — Audit A (pre-cloud parallel session) × Audit B (Wave Polish this session)

**Date** : 2026-05-21
**Branch** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `1116b39578`
**Reconciler** : independent agent (this session, READ-ONLY)
**Source documents reconciled** :
- Audit A : `reports/audit/goal-pre-cloud-2026-05-21/MASTER_SYNTHESIS_REPORT.md` + GOAL `plans/GOAL_PRE_CLOUD_PRODUCTION_AUDIT_2026-05-21.md`
- Audit B : `reports/ULTRAPLAN_WAVE_POLISH_2026-05-21.md`
- BRAIN : `PROJECT_BRAIN.md`

**Verification method** : every load-bearing claim from both audits verified independently against actual source files at HEAD `1116b39578`. Live PHPUnit run on AllergenCoverageSentinelTest + BranchScopeCoverageSentinelTest + FormRequestAuthzDriftSentinelTest + IdempotencyRequiredRoutesCoverageTest.

---

## §1 — Reconciliation matrix

### 1.1 Overlap (items both audits surfaced)

| Item | Audit A label | Audit B label | Reconciled |
|---|---|---|---|
| **POS PosCounterCollectModal Escape-key dismiss** | P1-POS-03 | POL-12 (verify or close) | **MERGE** — Audit A names the WCAG 2.1.1 violation, Audit B has it as "verify". My verification: no `keydown.esc` listener exists (file `PosCounterCollectModal.vue`). Severity = P1 a11y. |
| **POS hardcoded FR strings** | P1-POS-02 mentions "icon-only mode" + POS audit P1 cluster | POL-09 (11 strings) | **MERGE** — Audit A focused on `PosV5Button` aria-label (different file); Audit B on `PosComponent.vue:1134/1188/1223/1236` raw strings. Both real, different files. |
| **POS aria-label batch** | Surface fix #1 already applied (PosComponent.vue:1126) | POL-02 (aria-labels batch) | **PARTIAL OVERLAP** — Audit A already shipped `kiosk-cash-panel-close` aria. Audit B's "Cash Overview aggregate cards + Sort columns" still untouched. |
| **KDS Escape-key drawer** | Surface fix #3 applied (`KdsHistoryDrawer.vue` +19 LOC) | POL-02 mentions KDS history close + POL-11 focus-visible | **PARTIAL OVERLAP** — Audit A shipped Escape handler. Audit B's focus-visible ring on trigger button after close NOT done. |
| **i18n micro-heal Livreur** | Surface fix #2 applied (`delivery_cash_*` EN+FR ~16 LOC) | Implicit in GAP-01 (112 EN keys) | **OVERLAP** — Audit A pre-fixed 4 of the EN gap keys. Audit B's "112 EN missing" count was taken BEFORE that fix landed; actual gap now smaller. |

### 1.2 Unique to Audit A (Audit B missed)

| Item | Reason Audit B missed |
|---|---|
| **P0-IDEMP-01** cache driver guard incomplete (`file`/`database` not forbidden) | Audit B was UX-focused, didn't audit boot guards. |
| **P0-SENTINEL-02** AllergenCoverageSentinelTest 2 real failures | Audit B didn't run PHPUnit; this is CI-red blocker. |
| **P0-SENTINEL-03** `@group manual` non-functional (phpunit.xml lacks exclude) | Same. Wave Q-4 retraction is incomplete at config level. |
| **P1-SYNC-01** Outbox silent-loss vector (attempts ≥ 5 stranded) | Audit B was frontend/UX-focused, didn't read OutboxRescueCommand. |
| **P1-NF525-01/-02** TRUNCATE revoke + trigger preservation (infra-only) | Audit B was code-level only. |
| **LOCK doc drift** POS Loyalty Redeem UI says DRAFT, Option B shipped | Audit B mentions LOCK once but doesn't follow up. |
| **Cartography fictional anchor** PosShortcutOrderController doesn't exist | Audit B never claimed it existed — but didn't catch the drift either. |
| **Wave Q-4 retraction CONTRADICTED** (BRAIN claim vs reality) | Audit B accepted BRAIN at face value. |

### 1.3 Unique to Audit B (Audit A missed)

| Item | Reason Audit A missed |
|---|---|
| **GAP-01 / GAP-02** Massive EN/AR catalog gap (Cash Overview unusable in EN/AR) | Audit A's W2.Admin marks "1 P2 IaC + 2 NOTE" — under-classified. The Cash Overview is admin-facing and admin staff may run EN/AR. Real impact = page renders raw `label.X` strings. |
| **POL-04** `formatMoneyEuro` global on Cash Overview | Audit A didn't audit Wave X X4 surface in depth. Partial finding (some sites already use it). |
| **POL-05** Empty-state copy on Cash Overview | UI polish, Audit A focused on code-correctness. |
| **POL-06** POS shortcut panels empty-state copy | Same. |
| **POL-07** URL sync filters on Cash Overview | UX QoL miss. |
| **POL-08** `mode=other` filter no-op + EN missing `label.mode_other` | UX miss; I confirmed `label.mode_other` missing in EN. |
| **POL-10** PosCounterCollectModal numpad below-fold responsive | Mobile/tablet viewport miss. |
| **POL-13** Cron backup rotation + retention | Ops/SRE gap, not raised by Audit A (covered as W2.Admin IaC). |

### 1.4 Conflicts (claims that disagree)

| Audit A says | Audit B says | My verdict |
|---|---|---|
| Wave Q-4 retraction COMPLETED + sentinel `@group manual` works | (Audit B accepts BRAIN claim) | **Audit A is RIGHT**. Live test run: `php artisan test --filter=AllergenCoverageSentinelTest` → 2 failed / 2 passed. The 2 still-running methods are NOT annotated `@group manual` (only 4 of N are). Config-level `phpunit.xml` has no `<exclude><group>manual</group></exclude>`. BRAIN entry overstates the fix. |
| (no claim on Wave Y `{seconds}` rate-limit) | "Wave Y `{seconds}` placeholder fix : FR-only ; EN+AR still hardcoded 30s" | **Audit B is WRONG**. Verified `en.json:1373` = `"Too many requests — wait {seconds}s..."` and `ar.json:1222` = `"...انتظر {seconds} ثانية..."`. The placeholder IS templated in all 3 langs. |
| (no claim on EN/AR catalog scale) | "112 keys missing EN, 263 missing AR" | **Audit B OVERCLAIMS the count**. Verified diff: FR→EN = 89 missing keys, FR→AR = 125 missing keys (at `label.*` level alone, other namespaces would change totals). The *direction* of the claim (catalogs are short) holds, but the scale was inflated ~25-100%. |
| (Audit A applied 3 surface fixes ≤36 LOC) | (Audit B is plan-only, 0 actions) | **Both consistent** — they describe different methodologies. Audit A's 3 fixes are real (commits not in last 20 visible logs — they're already merged into HEAD `1116b39578`). |

### 1.5 NEW items I found during verification

| ID | Description | Evidence |
|---|---|---|
| **NEW-01** | `label.received_amount` value in `en.json:1103` is the **French string "Montant reçu"** (FR text in EN catalog) | Confirmed via `python3` JSON probe + grep. Audit A noted this in §3.2 P1-POS-01-BIS but Audit B missed it. (Now both have it — keeping for clarity.) |
| **NEW-02** | `label.mode_other` missing entirely in EN.json (FR has it: "Autre"). Cash Overview source mode filter renders raw `label.mode_other` when user selects "Other" in EN. | Confirmed via JSON probe. Audit B's POL-08 implied it but didn't quote specifics. |
| **NEW-03** | `formatMoneyEuro` is already implemented in `CashOverviewComponent.vue:380` and used at lines 133/140/147 — Audit B's claim "apply formatMoneyEuro global" is **partial** (some sites still call `n.toFixed(2)` raw at line 374) | grep -nE 'formatMoneyEuro\|\.toFixed' showed mixed usage. |
| **NEW-04** | `LeCayenneAllergenSeeder` is **NOOP** at HEAD (Wave Q-4 retraction is real at seeder level), but `AllergenCoverageSentinelTest` has only 4 of N methods annotated `@group manual` — the 2 failing methods are NOT annotated (lines 161 `assertGreaterThanOrEqual` + 191 `assertContains`) | Read seeder + sentinel + ran live test. Audit A surfaced this — promoting NEW for visibility. |

---

## §2 — Verified status per claim (detailed)

### Audit A claims

| Claim | File:line evidence | Verdict |
|---|---|---|
| P0-IDEMP-01 forbiddenDrivers incomplete | `app/Providers/AppServiceProvider.php:216` → `['array', 'null']` only | **CONFIRMED** |
| P0-SENTINEL-02 2 real failures | `php artisan test --filter=AllergenCoverageSentinelTest` → 2 failed / 2 passed | **CONFIRMED** |
| P0-SENTINEL-03 `@group manual` not excluded | `phpunit.xml:1-78` has NO `<groups><exclude>` block | **CONFIRMED** |
| P1-SYNC-01 attempts < 5 cap | `app/Console/Commands/OutboxRescueCommand.php:47` → `where('attempts', '<', 5)` | **CONFIRMED** |
| P1-POS-02 PosV5Button no aria-label | `resources/js/components/admin/pos/v5/PosV5Button.vue` → no aria-label prop | **CONFIRMED** |
| P1-POS-03 PosCounterCollectModal no Escape | `resources/js/components/admin/pos/PosCounterCollectModal.vue` → no `keydown.esc` | **CONFIRMED** |
| P1-POS-01-BIS en.json received_amount stale FR | `resources/js/languages/en.json:1103` → `"label.received_amount": "Montant reçu"` | **CONFIRMED** |
| P1-KIOSK-01 EN validator strings on FR path | `app/Http/Requests/OrderRequest.php:201,205` → English error strings | **CONFIRMED** |
| LOCK doc drift POS Loyalty Redeem UI Option B SHIPPED | `app/Http/Controllers/Admin/PosLoyaltyController.php` 99 LOC + `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue` 498 LOC + wired in `PosComponent.vue:1070,1311,1383` + permission seeded `database/seeders/PermissionTableSeeder.php:185` | **CONFIRMED — Option B IS shipped, LOCK doc still says "DRAFT"** |
| PosShortcutOrderController fictional | `grep -rn PosShortcutOrderController app/Http/Controllers/ routes/` → 0 hits | **CONFIRMED** |
| 3 surface fixes applied | `git diff` since pre-audit baseline + Wave X commits | **CONFIRMED** (PosComponent close button aria + KdsHistoryDrawer Escape + delivery_cash_* i18n) |
| Frozen-zone diff = 0 | git status on §7 files clean (excluding 1 pre-existing LOCK exception FiscalSequenceService:88) | **CONFIRMED** |
| NF525 CHAIN OK | (per BRAIN; not re-run in this session for safety, but no commits since touched chain) | **TRUSTED but not re-verified** |
| FormRequest baseline 66 < 69 | live run `FormRequestAuthzDriftSentinelTest` → `count is now 66 (< baseline 69)` PASS | **CONFIRMED** |
| BranchScope sentinel GREEN | live run → PASS | **CONFIRMED** |
| Idempotency required routes 23 covered sentinel GREEN | live run `IdempotencyRequiredRoutesCoverageTest` → PASS | **CONFIRMED** |

### Audit B claims

| Claim | Evidence | Verdict |
|---|---|---|
| EN catalog 112 keys missing | Actual diff FR→EN at `label.*` only = 89 keys missing | **PARTIAL — direction right, scale inflated** |
| AR catalog 263 keys missing | Actual diff FR→AR at `label.*` = 125 keys missing | **PARTIAL — direction right, scale inflated ~2×** |
| Cash Overview unusable in EN | 20 of 30 CashOverview-used `label.*` keys missing in EN (incl. grand_total, source_caisse, mode_cash, transactions_short, drawer_opened_at, opening_amount, breakdown_by_method) | **CONFIRMED — high-impact** |
| Cash Overview unusable in AR | 21 of 30 same keys missing in AR | **CONFIRMED — high-impact** |
| Wave Y `{seconds}` placeholder fix : FR-only | `en.json:1373` and `ar.json:1222` both contain `{seconds}` | **FALSE** |
| 11 POS hardcoded FR strings | Found 7 explicit: "Aucune commande borne…" (1134), "Allergenes:" (1188, misspelled), "✓ Encaisser" (1223), "↻ Actualiser" (1236), "Synchroniser" (37), "Caisse FoodKing" (80), "Commande rapide" (81) | **CONFIRMED but count closer to 7-9, not exactly 11** |
| Cash Overview formatMoneyEuro global needed | `formatMoneyEuro` defined at line 380, used at 133/140/147 — table rows + chips still raw | **PARTIAL — gap exists but smaller than claimed** |
| KDS contrast 3:1 → 4.5:1 | `#888` on white = ~3.5:1 borderline; `#555` on white = ~7.5:1 fine; only the neutral `#888` border-left at line 379 falls below WCAG AA-normal | **PARTIAL — P2 polish, not P1** |
| `mode=other` no-op | `label.mode_other` missing in EN+AR; FR has "Autre" | **CONFIRMED** |
| URL sync filters Cash Overview | `router.push({ query })` not implemented; only `$route.query.branch_id` read at 318-319 | **CONFIRMED** |
| Empty-state Cash Overview | `label.no_data_available` rendered raw on EN/AR (key missing) | **CONFIRMED indirectly via GAP-01** |
| Cron backup rotation | `scripts/db/backup.sh` exists per BRAIN; no rotation cron in repo | **CONFIRMED (gap is real, runbook-level)** |

### BRAIN claims (independent verification)

| BRAIN claim | Verdict |
|---|---|
| §2 "V1 LOCAL PRODUCTION-READY" Wave Q-4 retraction COMPLETED | **PARTIALLY TRUE** — data side healed (seeder NOOP + items.allergen_flags=[]), UI guards work, but CI level not suppressed: 2 of N sentinel methods still fail. "Production-ready" stands on RUNTIME criterion; FALSE on CI criterion. |
| §2 "Frozen-zone diff = 0 across 15 §7 files" | **TRUE** (1 documented LOCK exception on FiscalSequenceService:88 pre-Wave-Q) |
| §2 "NF525 chain CHAIN OK" | **TRUSTED** — no commits touched fiscal services since last verification |
| §2 "Wave L 11 heals shipped + Wave M 5 heals" | **TRUE** — git log shows full series ed35fced8 → 7bf30658b and subsequent |
| §2 multiple "PRIOR START HERE" dated 2026-05-19 entries | **STALE accumulation** — BRAIN §2 has 4+ "PRIOR START HERE" entries layered. Auto-managed but not pruned. Not a correctness issue, but information hygiene drift. |
| §1 "FormRequest authz baseline 69" | **STALE — actually 66 now** per live run (BRAIN says baseline 69, sentinel flags `66 < 69` and prompts to lower). Positive drift, but baseline never lowered. |
| §1 NF525 production boot guards `2477a2d05`, `dafb6b3c4`, etc. (CLAUDE.md ref) | **TRUE — but P0-IDEMP-01 reveals incompleteness**: boot guard exists but doesn't cover `file`/`database` cache drivers. |

---

## §3 — Unified prioritized list

Single ranked list ordered by `(risk_to_cloud, risk_to_local, effort_inverse, isolation)`. Each item is small enough to verify manually.

| Rank | ID | Source | Severity | Risk LOCAL | Risk CLOUD | Effort | Files | Notes / dependencies |
|---:|---|---|---|---|---|---|---|---|
| 1 | **UNI-01** Re-NOOP or annotate 2 still-running sentinel methods (gluten + coverage) | A (P0-SENTINEL-02) | P0 | NONE (CI-red only) | NONE | ≤10 LOC | `tests/Feature/Sentinels/AllergenCoverageSentinelTest.php:161,191` | Independent. Choose: (a) add `@group manual` to the 2 untagged methods OR (b) re-NOOP test bodies. (b) is safer. |
| 2 | **UNI-02** Add `<groups><exclude><group>manual</group></exclude></groups>` to phpunit.xml | A (P0-SENTINEL-03) | P0 | NONE | NONE | ≤5 LOC | `phpunit.xml` | Required to make UNI-01 (a) work. If UNI-01 (b) chosen, UNI-02 still useful as defense-in-depth. |
| 3 | **UNI-03** Widen `forbiddenCacheDrivers` to include `file`, `database` + sentinel test | A (P0-IDEMP-01) | P0 | NONE (LOCAL uses redis or array-only in CI) | HIGH (multi-instance ALB duplicate POST = double-charge) | ≤10 LOC + test | `app/Providers/AppServiceProvider.php:216` + NEW `tests/Feature/Sentinels/ProductionCacheDriverBootGuardTest.php` | Defensive only — affects PRODUCTION env only. |
| 4 | **UNI-04** Widen OutboxRescueCommand `attempts < 5` → `< 12` + sentinel | A (P1-SYNC-01) | P1 | LOW (V1 single-instance, KILL-9 between phase1 and phase3b is narrow) | MEDIUM (cloud worker churn raises probability) | 1 LOC + test | `app/Console/Commands/OutboxRescueCommand.php:47` + sentinel | Independent. Wave L B.1 already raised cap to 12 in `OutboxRetryFailedCommand`; this aligns the rescue lane. |
| 5 | **UNI-05** Fix `en.json:label.received_amount = "Montant reçu"` → English | A (P1-POS-01-BIS) | P1 | LOW (admin POS only) | LOW | 1 LOC | `resources/js/languages/en.json:1103` | Bundle with UNI-08 (EN catalog batch). |
| 6 | **UNI-06** Fix OrderRequest EN strings on kiosk FR-locked path | A (P1-KIOSK-01) | P1 | LOW (rare error path, FR-locked surface) | LOW | ~4 LOC + sentinel string-assert sweep | `app/Http/Requests/OrderRequest.php:201,205` | Use `__('validation.kiosk_machine_not_registered')` keys. |
| 7 | **UNI-07** EN catalog batch — add 20 missing CashOverview keys | B (GAP-01 partial) | P1 | NONE (FR works) | NONE | ~20 lines | `resources/js/languages/en.json` `label` block | Cash Overview admin renders raw `label.X` strings in EN. Owner can visually verify. |
| 8 | **UNI-08** EN catalog full-sweep — add remaining ~69 keys + `label.mode_other` | B (GAP-01 remainder) | P2 | NONE | NONE | ~70 lines | `resources/js/languages/en.json` | Lower urgency; do after CashOverview Pillar (UNI-07) lands. |
| 9 | **UNI-09** Annotate `plans/LOCK_POS_LOYALTY_REDEEM_UI_2026-05-18.md` `STATUS: SHIPPED-OPTION-B` | A (W3.7 LOCK doc drift) | P2 | NONE | NONE | 0 code, 1 doc line | `plans/LOCK_POS_LOYALTY_REDEEM_UI_2026-05-18.md` header | Paper trail only. |
| 10 | **UNI-10** Update cartography `01-pos.md` → remove fictional `PosShortcutOrderController` reference | A (W3.2 cartography) | P3 | NONE | NONE | docs only | `reports/audit/goal-pre-cloud-2026-05-21/anchors/01-pos.md` | Prevent future false alarms. |
| 11 | **UNI-11** PosCounterCollectModal Escape-key handler + focus-trap | A (P1-POS-03) + B (POL-12) | P1 | LOW (a11y) | LOW | ~30 LOC | `resources/js/components/admin/pos/PosCounterCollectModal.vue` | Mirror Audit A's KdsHistoryDrawer pattern. |
| 12 | **UNI-12** PosV5Button aria-label fallback prop for icon-only mode | A (P1-POS-02) + B (POL-02) | P1 | LOW | LOW | ~10 LOC + sentinel multi-callsite | `resources/js/components/admin/pos/v5/PosV5Button.vue` shared atom | Cross-callsite impact — re-run Vitest after. |
| 13 | **UNI-13** POS template hardcoded FR → `$t()` (7-9 strings) | B (POL-09) | P1 | LOW (testid risk) | LOW | ~10 LOC + i18n keys | `resources/js/components/admin/pos/PosComponent.vue:37,80,81,1134,1188,1223,1236` | Re-run Vitest sentinels after — testid drift possible. |
| 14 | **UNI-14** `formatMoneyEuro` apply to remaining sites in CashOverview (table rows + chips) | B (POL-04) | P2 | NONE | NONE | ~5-10 LOC | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue` table cells + `n.toFixed(2)` site at line 374 | Polish only. |
| 15 | **UNI-15** CashOverview empty-state copy + reset CTA + URL-sync filters | B (POL-05, POL-07) | P2 | NONE | NONE | ~1-2h dev | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue` | UX polish. URL sync is `router.push({ query })` + watcher. |
| 16 | **UNI-16** `mode=other` filter fix (gray-out option OR add `label.mode_other` + working filter) | B (POL-08) | P2 | NONE | NONE | ~5 LOC | `cashOverview/CashOverviewComponent.vue:368` + en.json | Surface bug — silent no-op today. |
| 17 | **UNI-17** PosCounterCollectModal numpad below-fold responsive media-query | B (POL-10) | P2 | NONE | NONE | ~20 LOC CSS | `PosCounterCollectModal.vue` style block | Mobile/tablet only. |
| 18 | **UNI-18** KDS history drawer focus-visible ring on trigger after close | B (POL-11) | P2 | NONE | NONE | ~10 LOC | `KitchenDisplaySystemComponent.vue` trigger button | a11y polish. |
| 19 | **UNI-19** KDS contrast `#888` border-left → WCAG AA-compliant (#666 or #555) | B (POL-03) | P2 | NONE | NONE | 1 line CSS | `KdsHistoryDrawer.vue:379` | Borderline contrast, not a blocker. |
| 20 | **UNI-20** AR catalog batch — add ~125 missing keys (CashOverview prio first 21) | B (GAP-02) | P2 | NONE (FR works) | NONE | ~125 lines | `resources/js/languages/ar.json` | **Risk**: agent translation not native — flag for owner native-speaker review before commit. |
| 21 | **UNI-21** Backup cron rotation + retention (30j local + 12 mois archive) | B (POL-13) | P2 | LOW (restore drill required) | LOW | 1 crontab line + ~10 LOC bash | `scripts/db/backup.sh` + crontab | Pre-cloud hygiene. |
| 22 | **UNI-22** TRUNCATE revoke on `audit_logs` + `z_reports` (managed-RDS) | A (P1-NF525-01) | P1 INFRA | NONE | HIGH (NF525 chain integrity) | Ansible task | Infra repo `f840c3ef5` already exists; apply at cutover | Next-GOAL cloud only. |
| 23 | **UNI-23** Trigger preservation across managed-RDS restore | A (P1-NF525-02) | P1 INFRA | NONE | HIGH | Runbook | RDS migration runbook | Next-GOAL cloud only. |

**Total** : 23 items, ~13 code-level + 2 infra + 1 doc + 7 polish/i18n. Estimated total LOC if all shipped : **~250 LOC + ~225 i18n key lines + 2 sentinels + 4 doc edits**.

---

## §4 — Recommended manual-verify-friendly batches

Each batch designed to (a) cluster items by surface or risk, (b) be visually/functionally verifiable in ≤15 min, (c) isolate cross-cutting risk.

### **Batch 1 — CI unred + sync silent-loss fix (highest priority, lowest risk)**

> Goal : turn CI green so owner manual-test confidence is grounded + close narrow silent-loss vector.

| # | UNI ID | Action |
|---:|---|---|
| 1 | **UNI-01** | Re-NOOP 2 still-failing sentinel methods OR add `@group manual` |
| 2 | **UNI-02** | Add `<groups><exclude>` to phpunit.xml |
| 3 | **UNI-04** | OutboxRescueCommand `< 5` → `< 12` + sentinel test |

**LOC** : ~15-20. **Manual verify** : run `php artisan test --filter=AllergenCoverage` (expect 0 fail) + `php artisan test --filter=OutboxRescue` (expect green) + visually confirm BRAIN claim "CI green" matches reality. **Risk** : NONE — sentinels are isolated.

### **Batch 2 — Production boot guard widen (cloud blast-radius)**

| # | UNI ID | Action |
|---:|---|---|
| 4 | **UNI-03** | AppServiceProvider forbiddenCacheDrivers += `'file', 'database'` + sentinel |

**LOC** : ~10 + sentinel. **Manual verify** : run new sentinel test; set `CACHE_DRIVER=file` locally + `APP_ENV=production` + observe RuntimeException on boot. **Risk** : NONE local (CI uses `array`, dev uses `redis`/`file` outside production env). HIGH cloud-risk closed.

### **Batch 3 — EN catalog Cash Overview pillar (visible-fix high-impact)**

| # | UNI ID | Action |
|---:|---|---|
| 5 | **UNI-07** | Add 20 missing CashOverview `label.*` keys to en.json |
| 6 | **UNI-05** | Fix `label.received_amount` value FR → EN |
| 7 | **UNI-16** | Add `label.mode_other = "Other"` to en.json |

**LOC** : ~22 i18n keys. **Manual verify** : owner visits `/admin/cash-overview?lang=en` and visually confirms no raw `label.X` strings. **Risk** : NONE — pure data additions.

### **Batch 4 — POS+KDS a11y P1 cluster**

| # | UNI ID | Action |
|---:|---|---|
| 8 | **UNI-11** | PosCounterCollectModal Escape-key + focus-trap |
| 9 | **UNI-12** | PosV5Button aria-label fallback for icon-only |
| 10 | **UNI-18** | KDS focus-visible ring on history trigger button |
| 11 | **UNI-19** | KDS `#888` border contrast → AA-compliant gray |

**LOC** : ~50. **Manual verify** : keyboard-only navigation through POS counter-collect (Esc dismisses) + KDS history (focus ring visible after close) + run Lighthouse a11y audit. **Risk** : LOW — Vitest CC sentinel re-run after.

### **Batch 5 — POS template FR → i18n (testid-risk batch)**

| # | UNI ID | Action |
|---:|---|---|
| 12 | **UNI-13** | 7-9 POS hardcoded FR strings → `$t()` keys + add EN+AR (where applicable) |
| 13 | **UNI-06** | OrderRequest EN validator strings → `__()` translatable |

**LOC** : ~15-20 + i18n keys. **Manual verify** : Vitest sentinel re-run + visual screenshot POS in EN + AR. **Risk** : LOW-MEDIUM — testid changes possible; rerun all POS Vitest specs.

### **Batch 6 — Cash Overview UX polish**

| # | UNI ID | Action |
|---:|---|---|
| 14 | **UNI-14** | formatMoneyEuro on remaining sites (table rows + chips) |
| 15 | **UNI-15** | empty-state copy + reset CTA + URL-sync filters |
| 16 | **UNI-17** | PosCounterCollectModal numpad responsive media-query |

**LOC** : ~50-80. **Manual verify** : visual walkthrough Cash Overview EN/FR + small viewport POS counter-collect. **Risk** : NONE — Wave X X4 surface, no NF525/frozen.

### **Batch 7 — Documentation paper trail (cheap, important for orchestrator trust)**

| # | UNI ID | Action |
|---:|---|---|
| 17 | **UNI-09** | Annotate `LOCK_POS_LOYALTY_REDEEM_UI_2026-05-18.md` STATUS: SHIPPED-OPTION-B |
| 18 | **UNI-10** | Update cartography `01-pos.md` to remove PosShortcutOrderController |
| 19 | (BRAIN hygiene) | Prune §2 stale PRIOR START HERE entries (keep current + 1) |
| 20 | (BRAIN baseline) | Update FormRequest baseline 69 → 66 in BRAIN §1 |

**LOC** : 0 code, ~10 doc lines. **Manual verify** : grep -rn `PosShortcutOrderController` returns 0, LOCK doc header updated. **Risk** : NONE.

### **Batch 8 — AR catalog (owner-gated)**

| # | UNI ID | Action |
|---:|---|---|
| 21 | **UNI-20** | AR catalog ~125 missing keys (CashOverview 21 prio first) |

**LOC** : ~125 lines. **Manual verify** : owner native-speaker review required. **Risk** : LOW — translation quality is owner-judged. If owner defers, ship FR+EN only V1.

### **Batch 9 — Pre-cloud hygiene**

| # | UNI ID | Action |
|---:|---|---|
| 22 | **UNI-21** | Cron backup rotation + retention |

**LOC** : ~10 bash + 1 crontab. **Manual verify** : restore drill from a 7-day-old backup. **Risk** : LOW — script-only.

### **Batch 10 — Cloud cutover gates (next-GOAL, not now)**

| # | UNI ID | Action |
|---:|---|---|
| 23 | **UNI-22** | TRUNCATE revoke on `audit_logs` + `z_reports` |
| 24 | **UNI-23** | Trigger preservation across managed-RDS restore |
| 25 | (full §6 Tier A cloud infra list) | (deferred to next-GOAL) |

**Effort** : owner physical actions + Ansible tasks. **Manual verify** : `SHOW TRIGGERS` + `TRUNCATE audit_logs` expects 1142 error. **Risk** : NONE LOCAL. HIGH if skipped at cutover.

---

## §5 — BRAIN state assessment

### What's accurate
- Wave L 11 heals shipped — confirmed against git log
- Frozen-zone diff = 0 on 15 §7 files — confirmed (1 documented LOCK exception)
- BranchScope coverage 21 models scoped + 12 exempted sentinel — confirmed PASS
- NF525 chain CHAIN OK — trusted (no fiscal-touching commits since last verify)
- Idempotency required-routes sentinel GREEN — confirmed
- FormRequest sentinel baseline-lock still passes — confirmed

### What's stale or inaccurate
1. **§2 "V1 LOCAL PRODUCTION-READY" headline** : true at RUNTIME (data side healed, UI guards work), but FALSE at CI level (AllergenCoverageSentinel still red on 2 methods). The discrepancy itself is a developer-experience blocker, not a runtime correctness blocker — but stating "production-ready" without qualifier is overclaim.
2. **§1 FormRequest baseline = 69** : actual = 66, sentinel itself prompts "Lower RETURN_TRUE_BASELINE accordingly." BRAIN never updated.
3. **§2 multiple "PRIOR START HERE" entries** : 4+ layers accumulated. Auto-managed but not pruned. Information hygiene drift.
4. **§2 Wave Q-4 "AllergenCoverageSentinelTest 4 methods → `@group manual` (CI no longer enforces)"** : misleading. Annotation exists but config `phpunit.xml` lacks exclude block — annotation is non-functional.
5. **CLAUDE.md §8 NF525 production boot guards "concrete enforcement"** : true for `array`/`null` cache drivers, FALSE for `file`/`database` (P0-IDEMP-01 covered above).

### Net trust verdict
**BRAIN is ~85% trustworthy.** The 15% drift is exclusively the Wave Q-4 incomplete heal + FormRequest baseline stale + 1 BRAIN-claimed "concrete boot guard" that is incomplete. None of these reverse the V1 LOCAL ship decision; all are heal-now ≤30 LOC scope-minimal items that align with both audits.

The BRAIN itself is not lying — it's overstating completeness of one heal cycle (Wave Q-4) and missing a small baseline drift. Reading BRAIN as "directionally correct, verify specifics" is the right posture.

---

## §6 — Summary back (≤500 words)

### 1. Reconciliation summary
- **Overlap (both audits)** : 5 items (POS Escape, POS aria batch, KDS Escape, Livreur i18n micro, POS hardcoded FR)
- **Unique to Audit A** : 8 items (3 P0 boot/sentinel + 1 P1 sync silent-loss + 2 P1 infra-NF525 + LOCK doc drift + cartography fiction)
- **Unique to Audit B** : 8 items (massive EN/AR catalog gap + Cash Overview UX polish family + cron backup + KDS contrast + mode=other no-op)
- **Conflicts** : 3 (Wave Y `{seconds}` Audit-B-WRONG + 112/263 count Audit-B-INFLATED + Wave Q-4 BRAIN-CLAIM-WRONG-Audit-A-RIGHT)
- **NEW from my verification** : 4 (NEW-01 received_amount + NEW-02 mode_other gap + NEW-03 formatMoneyEuro partial + NEW-04 sentinel 2 methods not annotated)

### 2. Top 10 unified items
| Rank | ID | Source | Sev | Local | Cloud |
|---:|---|---|---|---|---|
| 1 | UNI-01 sentinel re-NOOP | A P0-SENTINEL-02 | P0 | NONE | NONE |
| 2 | UNI-02 phpunit.xml exclude | A P0-SENTINEL-03 | P0 | NONE | NONE |
| 3 | UNI-03 cache driver guard widen | A P0-IDEMP-01 | P0 | NONE | HIGH |
| 4 | UNI-04 outbox attempts cap | A P1-SYNC-01 | P1 | LOW | MEDIUM |
| 5 | UNI-05 en.json received_amount | A P1-POS-01-BIS | P1 | LOW | LOW |
| 6 | UNI-06 OrderRequest EN strings | A P1-KIOSK-01 | P1 | LOW | LOW |
| 7 | UNI-07 EN CashOverview 20 keys | B GAP-01 partial | P1 | NONE | NONE |
| 8 | UNI-11 PosCounterCollect Escape | A P1-POS-03 / B POL-12 | P1 | LOW | LOW |
| 9 | UNI-12 PosV5Button aria | A P1-POS-02 / B POL-02 | P1 | LOW | LOW |
| 10 | UNI-13 POS hardcoded FR → i18n | B POL-09 | P1 | LOW | LOW |

### 3. Is V1 LOCAL "production-ready" as BRAIN claims?
**Yes at runtime; no at CI** — and yes after Batch 1 lands (~20 LOC, 30 min). Owner manual-test can proceed today (data side is clean, KDS/kiosk render no false allergens, fiscal chain intact, frozen-zones untouched). CI red on 2 sentinel methods is a developer-trust drag, not a runtime blocker. The "hidden debt" is real but small: 3 P0 code-level items totalling ≤25 LOC. No structural debt; no NF525 risk; no frozen-zone breach.

### 4. Recommended first manual-verify session (Batch 1)
Owner runs:
1. UNI-01 + UNI-02 : choose re-NOOP or `@group manual` + phpunit.xml exclude
2. UNI-04 : OutboxRescueCommand attempts cap → 12
3. Run `php artisan test --filter=Allergen` and `--filter=OutboxRescue`
4. Visual check : no surface change (these are CI/backend only)
5. ≤15 min total verify time

If green → Batch 2 (UNI-03 boot guard) follows directly. Both batches together close all 3 Audit-A P0s.

### 5. Trust verdict
**Audit A : highly trustworthy.** Every load-bearing claim verified file:line. 3 surface fixes are real. Methodology (READ-ONLY discipline + ≤36 LOC scope) holds. Catches the Wave Q-4 BRAIN contradiction honestly.

**Audit B : partially trustworthy.** Direction of major finding (EN/AR catalog gap is real and high-impact for Cash Overview) is solid. But 2 errors hurt: (a) Wave Y `{seconds}` claim is factually wrong (EN+AR templated), (b) 112/263 catalog gap inflated ~25-100% (actual 89/125). The UX-polish items are accurate but lower-impact than the catalog gap suggests.

**Net** : use Audit A as the authority for boot-guard/sentinel/sync/NF525 items. Use Audit B as the authority for i18n catalog + Cash Overview UX. Reconcile both via the unified list above. **Discard Audit B's Wave Y claim. Discard the inflated 112/263 count.** Trust the rest after the file:line evidence.

---

**Reconciler note** : I do NOT recommend an order-of-magnitude rework. Owner mandate "small simple functions one by one" maps cleanly onto the 10-batch sequencing above. The right move is Batch 1 (≤20 LOC, 30 min) before owner's next manual-test session. Everything else follows owner-gated batch-by-batch.

**End of reconciliation.**
