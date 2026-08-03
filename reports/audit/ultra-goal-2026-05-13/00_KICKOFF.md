# 00 — KICKOFF (Phase 0 Bootstrap)

**Date** : 2026-05-13 03:50 CEST
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**HEAD** : `8ce246be2` (`docs(goal): integrate Operating Discipline §-1 into plan`)
**Operator** : Claude Opus 4.7 (1M context), autonomy total, owner offline
**Goal** : `plans/ULTRA_GOAL_FULL_SYSTEM_AUDIT_2026-05-13.md`

---

## §1 Toolchain & environment

| Component | Version | Status |
|-----------|---------|--------|
| PHP | 8.2.30 | ✓ |
| Node | v18.20.7 | ✓ |
| npm | 8.19.4 | ✓ |
| MySQL | 9.6.0 (Homebrew) | ✓ |
| Branch | feature/mobile-app-le-cayenne-2026-05-10 | ✓ |
| Dev server | http://127.0.0.1:8000 200 OK | ✓ |
| Graphiti MCP | ok (neo4j connected) | ✓ |

## §2 Backups created

| Item | Location | Size / Hash |
|------|----------|-------------|
| Git backup branch | `backup/pre-ultra-goal-2026-05-13` | — |
| DB dump | `storage/backups/ultra-goal-2026-05-13/foodking-pre-goal.sql` | 5.5 MB, md5 `8dcdb0e0dac6942359e4bb684f223ca4` |

## §3 DB baseline metrics

| Metric | Value | Note |
|--------|-------|------|
| `item_categories` total | 18 | (10 active + 8 archived) |
| Active cats | 10 | 9 visible + 1 hidden (cat 315 channels='[]') |
| Hidden via channels='[]' | 1 | cat 315 frites-accompagnements |
| `items` active | 37 | post-reset Le Cayenne |
| `items` archived | 49 | legacy soft-deleted |
| `item_wizard_profiles` published | 7 | 5 bols + 2 frites |
| `item_wizard_steps` | 22 | composer steps |
| `item_attributes` | 11 | variations groups |
| `item_variations` | 909 | individual choices |
| `orders` total | 185 | historical |
| `order_items` total | 341 | historical |
| `audit_logs` | 26 | NF525 chain rows |
| `z_reports` | 0 | no Z closed yet |
| Max `fiscal_sequence_no` branch=1 | 293 | 131 orders with seq → 162 orders without (54 unaccounted + ?) **flagged for A1/A11** |
| `domain_events` | TBD | check in A1 |

### Active cats list (verified)
| ID | Name | Slug | Channels |
|----|------|------|----------|
| 306 | Tacos | tacos | NULL |
| 315 | Frites & Accompagnements | frites-accompagnements | `[]` (hidden) |
| 316 | Desserts | desserts | NULL |
| 317 | Boissons | boissons | NULL |
| 318 | Suppléments | supplements | NULL |
| 344 | Sandwich Cayenne | sandwich-cayenne | NULL |
| 345 | Galette | galette | NULL |
| 346 | Sandwich Classique | sandwich-classique | NULL |
| 347 | Bols Gourmands | bols-gourmands | NULL |
| 348 | Frites | frites | NULL |

✓ Matches plan §4.1 expected state.

---

## §4 Frozen-zones baseline diff (vs `main`)

Per-file diff line counts vs `main` :

| File | Diff lines | Note |
|------|-----------|------|
| `public/js/pos-wizard.js` | 304 | Composer-aware additions iter12+ (P0-15 BRAIN backlog) |
| `public/css/pos-wizard.css` | 0 | Clean |
| `resources/views/admin-pos-v4.blade.php` | 171 | Wiring iter12+ |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 2668 | Iter1-14 hardening accumulated |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | 1298 | Same |
| `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | 168 | Iter12 enhancements |
| `app/Services/Fiscal/FiscalSequenceService.php` | 0 | Clean |
| `app/Services/Fiscal/ZReportService.php` | 714 | Heal iter cycles + chain hardening |
| `app/Services/Fiscal/AuditLogService.php` | 312 | Append-only hardening |
| `app/Services/Pricing/PricingService.php` | 740 | SSOT extensions |
| `app/Models/Scopes/BranchScope.php` | 0 | Clean |
| `app/Http/Middleware/IdempotencyKeyMiddleware.php` | 250 | Iter11 hardening |
| `app/Domain/Order/OrderStateMachine.php` | 157 | State machine extensions |

**Interprétation** : la branche `feature/mobile-app-le-cayenne-2026-05-10` accumule le hardening de tous les cycles iter1-14 + audit waves (POS audit 2026-05-09, kiosk wave 2026-05-08, etc.) — c'est du diff *expected/documented*, pas une violation de discipline. **PROJECT_BRAIN.md §2 "0 lignes diff vs main"** était stale ou référait à une version antérieure du release branch ; CORRECTION pending dans Phase 15.

**Pour ce goal** : la référence frozen-zones n'est PAS `main` mais `HEAD@phase0` (snapshot pris ici). Tout diff supplémentaire introduit par le goal sur ces fichiers = potentiel breach.

Baseline frozen-zones diff archivée : `reports/audit/ultra-goal-2026-05-13/frozen-zones-baseline.diff` (6782 lignes total).

---

## §5 Test baselines

### Vitest (frontend unit + sentinels)
- **Total** : 6 failed / 1381 passed / 3 skipped (1390 tests, 219 files)
- **Duration** : 13.83s
- **Pre-existing failures (audit later in correct axis)** :
  1. `tests/js/observabilityOutboxRoute.spec.js` — OutboxOverviewComponent compiles + mounts (build smoke) → **A3 sync axis**
  2. `tests/js/sentinels/f008KioskPaymentReconcileQueue.spec.js:30` — `confirmBackendPayment` regex assertion → **A6 kiosk axis** (frozen — investigate without modifying)
  3. `tests/js/userReportedBlockersRuntime.spec.js` — banner suppression on POS/kiosk shells → **A5 POS axis**
  4. `tests/js/kdsBackoffOn5xx.spec.js` — KdsSyncService self-heal after network error → **A7 KDS axis**
  5. `tests/js/sentinels/cspMigratedToHttpHeader.spec.js` — master.blade.php CSP meta fallback-only → **A2/A9 backend/admin**
  6. `tests/js/kioskFormatPrice.spec.js` — fallback safe locale/currency → **A6 kiosk axis**

### PHPUnit (backend)
- **Total** : 20 failed / 2 incomplete / 29 skipped / 1863 passed (1914 tests)
- **Duration** : 237.47s
- **3 visible failures in PricingServiceTest (1€ delta pattern)** :
  1. `manual_discount_applied_in_pos_context` → got 8.0 expected 9.0 (1€ missing)
  2. `delivery_charge_added_to_total_after_tax` → got 13.5 expected 14.5 (1€ missing)
  3. `insert_rows_contain_branch_id_and_order_id` → tax_amount 0.91 vs 1.0 (looks like tax-inclusive vs tax-exclusive formula switch)
- **Remaining 17 failures** : enumerated in `phpunit-baseline-full.log` (background re-run)
- **A2 backend services axis priority** — Pricing SSOT may have regressed

### Playwright baseline visual capture
- Running spec `tests/e2e/ultra-goal-baseline-capture.spec.js` (9 surfaces)
- Captures so far : 4/9 (kiosk-idle, kiosk-order-setup, kiosk-categories, login)

---

## §6 Visual baseline observations (Read tool analyzed)

### 01-kiosk-idle.png ✓ (acceptable, 1 minor)
- ✓ FoodKing logo + "Bienvenue !" headline visible
- ✓ "À emporter — Je récupère ma commande" card (V1 dine-in désactivé correct — feedback memory)
- ✓ Orange play button + branding intact
- ✓ "CHOISISSEZ UNE OPTION POUR COMMENCER" CTA prompt
- ✓ Theme toggle + clock icons
- ⚠️ **Minor** : subtitle "Commandez en quelques touches" partiellement obscurci par l'ombre du "Bienvenue !" — contrast fail / readability fail. **Track P2 — A6 kiosk visual**.

### 02-kiosk-order-setup.png ❌ NEW FINDING
- ❌ **404 "Page Non Trouvée"** — route `/kiosk/order-setup` doesn't exist or is unreachable from headless context.
- Need to verify if route was renamed (`/kiosk/order-type` or similar) or genuinely missing.
- **Track P1 — A6/A11 cross-surface E2E (kiosk flow may break)**.

### 03-kiosk-categories.png ⚠️ (redirected to idle)
- Image identical to 01-kiosk-idle — visit to `/kiosk/categories` returned the idle screen.
- Likely **expected behavior** : categories route requires active order context (cookie/state); without one, redirects to idle.
- Need confirmation in A6 kiosk flow audit.

### 04-login.png ✓ (clean)
- ✓ FoodKing logo in header
- ✓ "Connexion" button top-right
- ✓ "Bon Retour" card with email + password fields
- ✓ "Se Souvenir De Moi" + "Mot De Passe Oublié" links
- ✓ Big orange "Connexion" CTA
- ✓ No raw labels visible
- ✓ Branding intact

---

## §7 Findings to track for axes (initial seed)

| # | Source | Track to | Severity | Note |
|---|--------|----------|----------|------|
| KICK-01 | Baseline visual | A6 kiosk | P2 | "Bienvenue !" subtitle overshadowed |
| KICK-02 | Baseline visual | A6 + A11 | P1 | `/kiosk/order-setup` → 404 (route missing or renamed) |
| KICK-03 | Vitest | A3 sync | P1 | OutboxOverviewComponent build smoke fail |
| KICK-04 | Vitest | A6 kiosk (frozen, audit only) | P1 | F-008 KioskPaymentReconcileQueue sentinel |
| KICK-05 | Vitest | A5 POS | P2 | userReportedBlockers banner suppression POS+kiosk shells |
| KICK-06 | Vitest | A7 KDS | P1 | KdsSyncService backoff on 5xx self-heal |
| KICK-07 | Vitest | A2/A9 | P2 | CSP HTTP header migration sentinel |
| KICK-08 | Vitest | A6 kiosk | P2 | kioskFormatPrice locale fallback |
| KICK-09 | DB | A1 + A11 | **P0?** | Branch 1: 179 orders / 131 have seq / max=293 → gap of 162 fiscal_sequence_no integers in chain (potential NF525 violation, needs investigation: soft-deleted? alloc-then-abort?) |
| KICK-10 | BRAIN | docs | P3 | BRAIN §2 "0 lignes diff vs main" stale → fix in Phase 15 |
| KICK-11 | PHPUnit | A2 | **P0?** | 20 PHPUnit failures (was "1 unrelated" per BRAIN). 3 visible all in PricingServiceTest, suggests tax-exclusive vs tax-inclusive formula regression. |
| KICK-12 | HTTP probe | A6 + A11 | P1 | `/kiosk/order-setup` returns HTTP 200 but Vue Router shows "404 Page Non Trouvée" — silent SPA catchall masquerade. Real route alias `/kiosk/order-type` also 200. |
| KICK-13 | DB | A1 | P2 | 18 soft-deleted orders on branch 1 — verify NF525 retention (6y required) + composition_snapshot preserved |

These are seed inputs — each per-axis sub-agent will rediscover them with file:line evidence + add their own findings, and adversarial will confirm/dispute.

---

## §8 Plan execution status

| Phase | Status | Note |
|-------|--------|------|
| Phase 0 Bootstrap | **in_progress** | this doc, awaiting PHPUnit + Playwright completion |
| Wave 1 (A1+A2+A3) | pending | will launch parallel after Phase 0 GREEN |
| Wave 2-5 | pending | sequential after Wave N-1 GREEN |
| Phase 12 cross-axis | pending | after A1-A11 GREEN |
| Phase 13 massive E2E | pending | invokes `/test-e2e` skill |
| Phase 14 visual sweep | pending | adversarial visual after Phase 13 |
| Phase 15 final convergence | pending | delivery `FINAL_VERDICT.md` |

---

## §9 Resume tokens

- `RESUME_TOKEN_PHASE_0_BOOTSTRAP_INPROGRESS_20260513-0353`

Update after commit : `RESUME_TOKEN_PHASE_0_BOOTSTRAP_DONE_<ts>`

---

*Auto-generated by Claude Opus 4.7 — Phase 0 bootstrap kickoff.*
