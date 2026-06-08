# SUPERVISOR AUDIT — Dashboard Excellence corrections (adversarial)

**Date:** 2026-06-08 · **Subject:** Adversarial supervisor re-audit of the 5-wave GOAL_DASHBOARD_EXCELLENCE
fix campaign (commits `4313f4547` W1, `2e61ee229` W2, `8596eb472` W3, `b95dac18a` W4, `2decb8633` W5,
`625482726` dev-bundles) on branch `heal/deployed-dashboard-fixes-2026-06-08` — **UNPUSHED, UNMERGED**.

**Stance:** every correction WRONG until proven right. No row enters as DEFECT/RISK until the orchestrator
*reproduces* it. HOLDS verdicts also carry empirical proof. **Operating DB `foodking` untouched** —
tripwire 2673 rows / chain head `daf60671` unchanged. Served target `:8769` (clone `foodking_dash_e2e`),
bundle freshness confirmed.

## Method
6 parallel read-only adversaries (A=W1, B=W2, C=W3, D=W4-UI, E=W5, F=cross-cutting security/fiscal/regression/backend).
Orchestrator personally reproduced the P0-candidate zone (W4 net-new backend export) by execution and
live-verified representative HOLDS rows on `:8769` with screenshots. Anti-hallucination: file:line +
reproduction + evidence mandatory for every DEFECT/RISK.

**Severity:** P0 data/fiscal/security · P1 user-visible · P2 UX-quality · P3 cosmetic.
**Verdict:** HOLDS · DEFECT (fix) · RISK (latent/owner) · GOLD-PLATING (out of V1) · DISPROVEN (false positive).

---

## THE TABLE — one row per correction / finding

| # | Wave | Correction / finding (file:line) | Verdict | Sev | Smart proof (reproduced) | Remediation |
|---|------|----------------------------------|---------|-----|--------------------------|-------------|
| A1 | W1 | `SiteTableSeeder.php:27,62` time `h:i A`→`H:i` | HOLDS | — | WT HEAD=`H:i`; `AppLibrary` defaults `env('TIME_FORMAT','H:i')` ×4; residue only a legit 12h dropdown option `timeFormatEnum.js:4`; `php -l` clean | — |
| A1c | W1 | live `site_time_format` on deployed box | RISK | P3 | Seeder runs only at install/reseed; deployed box keeps existing Setting until owner data-action (SoT = DB setting → .env via `SiteService` on settings-save) | **G-DATA-1** owner sets 24h on live box |
| A2 | W1 | `lang/fr/validation.php` full FR | HOLDS | — | `php -l` clean; FR 129 ≥ EN 116 keys → 0 missing → no EN fallback; 0 placeholder mismatches | — |
| A3 | W1 | `EmployeeRequest.php` drop EN `messages()` | HOLDS | — | Removed override held only `role_id.required` EN string; now FR `required`+`attributes.role_id='rôle'` (EN→FR upgrade) | — |
| A4 | W1 | Item price `flat_price`→`currency_price` (×3 comp) | HOLDS | — | `Item.php` no accessor → both resource-only, identical provenance; all 3 resources emit BOTH; `(float)` cast → never blank. P1 "blank price" disproven | — |
| A5 | W1 | `ItemPreviewComponent.vue:352` formatPrice→Intl | HOLDS | — | Old=literal no-op; new=real Intl fr-FR EUR → "1,50 €" | — |
| A6 | W1 | `CreditBalanceReportComponent.vue:91` →`currency_balance` | HOLDS | — | Controller:47 `CreditBalanceUserResource::collection`; resource:29 emits `currency_balance` | — |
| A7 | W1 | `CashSessionReportListComponent.vue` formatMoney→Intl | HOLDS | — | Genuine Intl fr-FR EUR + null='—' guards on cells + header totals | — |
| A8 | W1 | `fr.json` `tax_rate`→"Taux de TVA" | HOLDS | — | Valid JSON; no duplicate key (object_pairs_hook scan) | — |
| B-d1 | W2 | "W2 not in served bundles / invisible" | **DISPROVEN** | (was P1) | `grep -rl` → `enc-total-banner`/`totalPending`/`en attente d'encaissement` in **admin-shell.js**, `hasVariance` in **admin-reports.js**; + LIVE :8769 screenshot renders all 3 W2 features. Adversary B grepped wrong tree (worktree-shadow) | none — correction is live |
| B1 | W2 | Encaissement "Client borne" (kiosk source) | HOLDS | — | `source_surface` correct field; clone kiosk rows carry `user.name="soak-kiosk-…"` which the label hides; LIVE screenshot: every kiosk card shows "Client borne" | — |
| B2 | W2 | Encaissement aging badge (elapsedShort port) | HOLDS | — | Byte-faithful port from PosOrdersTracker (same divisor/thresholds/`pos.tracker.now`); source not frozen; LIVE: badge renders | P3 watch: raw "269h" on artificially-old clone data; real pending are min/h |
| B3 | W2 | Encaissement totalPending banner | RISK | P2 | Sums only the 200-capped fetched list (endpoint `->limit(200)` api.php:836). Clone true=43 291,50 € (1252) vs shown 5 767,00 € (first 200). LIVE: banner renders 5 767,00 € | Vision-benign for single-restaurant V1 (won't hit 200 uncollected). **G-DEC-1**: rename to reflect cap, or fetch true sum, or accept |
| B4 | W2 | Cash-session variance detail (Attendu/Compté/Motif) | HOLDS | — | Not dead UI: fields mapped `CashSessionReportController:136-139`; `hasVariance` ≥0.01 threshold exactly aligned with `varianceClass`; clone variance row renders, balanced rows hide | — |
| C-D | W3 | `PosOrderShowComponent.vue:63` token `<li>` gated `&& order_type===DELIVERY` | **DEFECT** | P2 | Token is a **kiosk/online** ref (its own comment L55-62); clone: TAKEAWAY=2030 tokens, KIOSK=2, **DELIVERY=0**. Gate hides token on the ~2032 orders that have one, shows only the class that never does. Orchestrator re-read L63 + confirmed | **FIX**: drop `&& order.order_type===…DELIVERY`, keep `v-if="displayedToken"` (self-regression in unpushed code) |
| C1 | W3 | Dashboard reorder Realtime↑Overview | HOLDS | — | Clean 2-line sibling swap, no grid breakage; LIVE: "Suivi en direct" above "Vue d'ensemble" | — |
| C2 | W3 | `SlaAlertsComponent` <24h bucketing | HOLDS | — | `time_preparing` is MINUTES (`DashboardService:501` diffInMinutes), 1440=24h exact; 15-min floor backend-enforced; NaN-safe; badge keyed only on actionable | — |
| C3 | W3 | `LastZReportWidget` localizedStatus + Z link | HOLDS | — | Route `admin.transactions.list` exists; status whitelist covers real `ZReport.status` {open,closed}; unknown→`String()` no raw leak | — |
| C4 | W3 | `RealtimeReportComponent` loading guard | HOLDS | — | `.finally()` lifts placeholder on success+error; no permanent spinner | — |
| C5 | W3 | `PosOrderShow` title + order-type enum fallback | HOLDS | — | Named enum refs (not literals); POS=15/KIOSK=25 (`OrderType.php`), COUNTER_DEFERRED=6 (`PosPaymentMethod:19`); `\|\| '—'` correct | — |
| C6 | W3 | `PosOrderListComponent.vue:44` RETURNED filter | HOLDS | — | `orderStatusEnum.RETURNED=22` matches `OrderStatus.php`; single entry | — |
| D1 | W4 | Stock synthetic "⚠ Ruptures (N)" bucket | HOLDS | — | Live catalog 45 unique ids, no dup `:key`; `__ruptures__` can't collide; counts true ruptures (is_available=false) only; `pickDefaultBucketKey` skips it; 9 Vitest pass | — |
| D2 | W4 | Ingredients `typeLabel` + "produit(s)"/"Non utilisé" | HOLDS | — | Maps attribute/extra/addon; backend `IngredientService` emits exactly those 3 prefixes; `v-if="typeLabel"` hides unknowns | — |
| D3 | W4 | Historique chips + empty state + sessionStorage | HOLDS | — | Chips via `hasActiveFilter`; date AND/OR mismatch unreachable (range picker sets both); key `historique:dateRange` unique | — |
| D4 | W4 | `orderHistory.js` export action | HOLDS | — | Path `admin/order-history/export`→`/api/...` matches `route:list`; LIVE: 200 + real XLSX (114KB→6.3KB filtered), `responseType:'blob'`, 401 JSON unauth (no SPA-HTML) | — |
| D7 | W4 | `rail_ruptures`/`usage_none` not in en.json | RISK | P3 | intlify warnings in en-default test harness; V1 single-locale FR (ADR-007) both resolve | V2 backlog (en parity) |
| D9 | W4 | Export dropdown UI gate `pos-orders` (no `pos`) | RISK | P3 | Narrower than backend `pos-orders\|pos`; not a hole (read-only data), single-operator V1-moot | optional: widen UI gate to match backend |
| Fbe1 | W4 | `OrderHistoryController::export` authz | HOLDS | P0-cleared | Identical gate to proven index()/show(); route in admin group `api.php:295` behind `auth:sanctum`. DYNAMIC: unauth GET→302 login (370B), not 200 XLSX | — |
| Fbe2 | W4 | `OrderHistoryExport` branch isolation | HOLDS | P0-cleared | `list()` queries scoped `Order::with()` (no `withoutGlobalScope`); crafted `?branch_id`→∅ via BranchScope; admin sees all by design | — |
| Fbe3 | W4 | `OrderHistoryExport` NF525 exposure | HOLDS | P0-cleared | Read-only `FromCollection`; `fiscal_sequence_no` (accountant need, blank unsealed) behind gate; zero audit_logs/z_reports writes; already exposed by `SimpleOrderResource:70` (no new leak) | P3: `'N° fiscal'` heading hardcoded FR (no lang key) |
| Fbe-chef | W4 | export gate effectiveness (real role blocked) | HOLDS | P0-cleared | **CLONE DB reproduced:** Chef role = `dashboard,kitchen-display-system,order-status-screen` only (`has_pos=0,has_pos_orders=0`) → gate 403s a Chef; Branch Manager + POS Operator hold both → can export. Gate is meaningful, not just defense-in-depth | — |
| F-T2 | all | frozen-zone integrity | HOLDS | P0-cleared | Independent `git diff 0c0183ee4..HEAD` over full §7 set = **0 lines**; build commit recompiled only Mix bundles, hand-written `pos-wizard.js` not in its file list | — |
| F-T3 | W1 | receipt label `.toLowerCase()` (ReceiptComponent/PosOrderReceipt) | GOLD-PLATING | — | `buildNf525Footer` emits only 3 already-lowercase literal keys (all present in fr.json); `.toLowerCase()` can't break/leak → harmless no-op. Receipt components NOT in §7 frozen set | none (cosmetic-neutral; could be reverted, no benefit either way) |
| F-T4 | all | `fr.json` integrity (5 waves) | HOLDS | — | Valid JSON; **0 duplicate keys**; only 6 value-only changes, none consumed via `$t` by any frozen/POS/kiosk surface (`label.tax_rate` only in Tax-settings admin; receipt uses data field `item.tax_rate`); 0 hits in frozen `pos-wizard.js` | — |
| F-T5 | W1 | currency payload presence | HOLDS | — | All 5 W1 components → unconditional resource fields (`SimpleItemResource:37`/`ItemResource:76`/`CreditBalanceUserResource:29`); CashSession uses client-side Intl (no API field) → no blank-price vector | — |
| F-T6 | all | served bundle freshness (independent) | HOLDS | — | Served `:8769` admin-shell/pos-app/app.js hashes match HEAD mix-manifest; contain Ruptures/Client borne/depuis le début | — |
| E-D1 | W5 | `RoleDisplayHelperTest.js` runs in NO runner | DEFECT→**HEALED** | P2 | Vitest globs `tests/js/**/*.spec.js`; `tests/Unit/` is PHPUnit `*Test.php` → ran nowhere (orphan, WP-06 class). **HEAL:** `git mv` → `tests/js/roleDisplay.spec.js`; `npx vitest run` → **6 tests pass** | DONE — now collected + green |
| E-R1/2 | W5 | brand-color sweep (appService ×6, eod PDF ×3) | RISK | owner | Verified semantically safe: appService = SweetAlert `confirmButtonColor` `#696cff→#F4501E` (cancel gray untouched); eod = PDF header/title/accent `#ff006b→#F4501E` (data/money untouched); compiled correctly. Deliberate-vs-accidental = owner | **G2** owner confirm or revert |
| E-V1 | W5 | `roleDisplay.js` helper | HOLDS | — | `ROLE_LABEL_KEYS` byte-matches `RoleTableSeeder` (incl 8th role "Stuff" as unknown-fallback); all 7 `label.*` exist; null-safe | — |
| E-V2 | W5 | `safePhone`/`roleLabel` on 4 list/show comps | HOLDS | — | `safePhone` reuses pre-existing SSOT `phoneDisplay.js`; can't produce "nullnull"; insertions confirmed inside `methods:` in all 4 files (no dangling) | — |
| E-V3 | W5 | `TimeSlotListComponent` FR weekday labels | HOLDS | — | id→key map matches `dayEnum` exactly incl Sunday `id:0`; fallback to enum name; all 7 `day_*` keys present | — |
| E-V4 | W5 | Encaissement pending badge | HOLDS | — | Endpoint byte-identical to 3 shipped callers (resolves, no silent-404); `fetchPendingCount` in `methods:`+`mounted()`; `.catch()` keeps cockpit alive; LIVE: badge "200" matches `counter-collect/pending` count | — |
| C-D (heal) | W3 | `PosOrderShowComponent.vue:63` token gate | DEFECT→**HEALED** | P2 | Reverted `&& order.order_type===…DELIVERY`; restored original `v-if="displayedToken"` (dedup guard lives in the computed L625). delivery_time/title conditionals (correct) left intact | DONE — token shows again on the ~2032 orders that carry one |

---

## Actionable ledger (pre-E/F)
- **DEFECT P2 — C-D** (token gating backwards): self-regression in unpushed code → FIX candidate (non-frozen, 1-condition revert).
- **RISK P2 — B3** (totalPending capped at 200): vision-benign V1 → owner decision G-DEC-1.
- **RISK P3 — A1c** (deployed-box time setting): owner data-action G-DATA-1.
- **RISK P3 — D7/D9, Fbe3**: en.json parity (V2), UI gate widen (optional), N° fiscal lang key (polish).

## Owner gates
- **G-DATA-1** (P3): set live deployed `site_time_format` to 24h — owner data-action; code/seeder doesn't retro-migrate.
- **G-DEC-1** (P2): totalPending banner cap — rename / true-sum / accept (vision says accept for V1).

## Notes
- 401 console batch on first dashboard nav = stale pre-login token→auto-logout, NOT a defect: in-page authed fetch 200 (total_sales "31 773,90 €", pending 200).
- Worktree-shadow trap bit Adversary B's grep (it noted `.env`→operating `foodking` confusion). Always inspect `.claude/worktrees/wave3-deployed-heal/...`.
