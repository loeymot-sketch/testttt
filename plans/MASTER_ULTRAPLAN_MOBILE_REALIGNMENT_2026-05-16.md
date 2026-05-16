# MASTER ULTRA-PLAN — Mobile App Realignment to New Global System
**Date** : 2026-05-16
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Skill** : `superpower-gstack` (Superpowers + GStack 7-step + 6 virtual roles)
**Mode** : ULTRA-PLAN ONLY — no execution before owner gate
**Author** : Claude Orchestrator (Opus 4.7 1M context) after parallel 6-agent audit

---

## §0 — Executive Summary

The new global system (POS + Borne kiosque + KDS + OSS + Admin + DB) was
restructured on **2026-05-13** (menu reset Le Cayenne, 9 cats) and **2026-05-14**
(heal-light V2, +Burgers +Menu enfant = 11 cats final). The mobile app at
`mobile/` is a **100% standalone HTML+React+Babel runtime** whose local data
layer (`mobile/data/menu.js`) is aligned 1:1 with the post-reset DB seed
commands — **but is NOT wired to the backend**.

The owner perception ("the application is separated, not updated") is correct
at the **integration** level: the mobile app is a perfect simulator, but it
is not yet a client of the new POS/Kiosk/KDS/OSS/Admin system. There are
also **5 P0 wiring blockers** the 6-agent audit surfaced that must be closed
before Phase 6 wireup is approved.

### Verdict at planning time
- 🟢 **Mobile data layer truth** : aligned to DB (11 cats, 11 sauces, 9 supplements @ 0.90 €, 42 items, 4 viandes — all SSOT-correct).
- 🔴 **Mobile↔backend integration** : 0 fetch() calls. Standalone PWA. No Sanctum token wired, no API consumption, no fiscal allocation, no idempotency, no real auth.
- 🔴 **Wizard parity** : Bols (`wizard_template='custom'`) falls through to `'simple'` in `mobile/screens-item-steps.jsx:56-130` — bol composer profile (4 steps base/sauce/supp/drink) NOT rendered.
- 🟡 **Documentation drift** : `config/menu.php` lists 15 sauces / 6 supplements @ €1.00 (pre-reset), STALE vs actual DB state (11 sauces / 10 supps @ 0.90 €). Mobile is aligned to DB, but the config file misleads future devs.
- 🟡 **CONNECTION_PLAN.md** (2026-05-10) claims "13 catégories" — stale vs post-reset reality (11).

### Output deliverable
- This master plan + 6 sub-plans (one per axis).
- Updated `PROJECT_BRAIN.md §4 NEXT PLAN` pointing here.
- Graphiti episode `foodking` group pushed.
- Memory file `project_mobile_realignment_ultraplan_2026-05-16.md`.

---

## §1 — Reconciled Findings from 6 Parallel Agents

| Role | Output | Verdict |
|---|---|---|
| **Architect** | 11 cats (config/menu.php:47-65), 4 templates (sandwich/tacos/custom/simple), composer profiles for Bols+Frites only, `/api/frontend/*` requires `auth:sanctum` + ability `kiosk:order`, `PricingService::calculateOrder()` is the SSOT | Backend surface mapped |
| **DBA** | 42 items DB-seeded, `composition_snapshot` shape frozen (variations/extras/addons), `orders` is canonical table for both `FrontendOrder` + `Order` (both apply BranchScope), 18 models BranchScope-scoped, `loyalty_transactions` + `loyalty_consents` exist (no `loyalty_rewards` table yet), **mobile/data/menu.js fully aligned to DB** | DB ground truth verified |
| **Mobile Auditor** | 8,185 JSX/JS lines, 8 ScreenStep* templates (computeActiveSteps), 100% localStorage, 0 fetch() to backend, client-side pricing via `priceFor()` (mobile/data/menu.js:391-423), wizard `'custom'` UNHANDLED → falls to `'simple'` | Standalone V0 confirmed |
| **Wizard Auditor** | Cross-surface matrix shows MEDIUM drift kiosk↔mobile, POS frozen with hardcoded `[Pain, Galette]` fallback, mobile reads `category.wizard_template` only (not `item.wizard_template`), composer profile parsing absent in mobile | Wizard parity gaps catalogued |
| **Integration Auditor** | Only `kiosk:order` ability exists, no `customer:order` / `mobile:order`, idempotency dual-layer is wired (`X-Idempotency-Key` UUID), NF525 fiscal allocation at `FrontendOrderService::finalizePaidKioskOrder` (l.1130-1167), Pricing SSOT enforced via DB price lookup (FrontendOrderService:354), Stripe customer-facing NOT implemented (TPE only) | API contract gap mapped |
| **Adversarial RED** | 3 P0 + 7 P1 raised. After reconciliation: **2 P0 hold** (slug-only payload, channels filter), **1 P0 invalid** (sauces drift — actually config stale, not mobile), **1 NEW P0 confirmed** (idempotency default-disabled), **5 P1 hold** (wizard label override, sandwich fallback, stale order names, loyalty tables, double-credit risk) | Hostile audit verdict NO-GO without P0 closures |

### Reconciliation of contradictions

**RED P0 "Sauces drift 15 vs 11"** : INVALID as mobile drift.
- `config/menu.php:98-114` lists 15 sauces — STALE pre-reset (was used by old `MenuSeeder`).
- DB canonical (post-heal-light v2) = 11 sauces (Tandoori + Cayenne removed, treated as meat marinade / sandwich name).
- Mobile `data/menu.js:125-137` = 11 sauces — ALIGNED to DB.
- **Real P0** : `config/menu.php` is misleading documentation. Decision needed: rewrite or remove as SSOT (DB seed commands are the actual SSOT post-reset).

**RED P0 "Supplements price drift €1.00 vs 0.90 €"** : SAME pattern.
- `config/menu.php:133-140` lists 6 supplements at €1.00 — STALE.
- DB canonical = 10 supplements at 0.90 € (heal-light v2) + 1 at 2.00 € (Boule gratinée).
- Mobile = 9 generic + 4 bol-specific — ALIGNED.

**RED P0 "Category count 13 vs 11"** : `CONNECTION_PLAN.md:8,13` is stale (2026-05-10 pre-reset). All other sources = 11. Real action : refresh that doc.

---

## §2 — Goals (6 axes)

The owner asked for "all six points" coverage. We organize the realignment as 6 parallel axes (each gets a sub-plan + acceptance criteria). They execute in 3 sequenced waves (Wave A axes 1-2 documentation + data; Wave B axes 3-4 wiring; Wave C axes 5-6 visual + adversarial).

| Axis | Title | Scope | Owner-gate before? |
|---|---|---|---|
| **A1** | Data Layer Truth (SSOT reconciliation) | Refresh `config/menu.php` + `CONNECTION_PLAN.md` + slug↔id verification | NO (pure docs) |
| **A2** | Wizard Parity (mobile catches up to backend canonical) | Bols/Frites composer profile rendering, item.wizard_template priority, viande_count exposure, addon_role label fix | YES (touches mobile render) |
| **A3** | API Surface for Mobile (backend contract) | New `customer:order` ability OR mobile-scoped endpoints, slug-resolution, idempotency default-on for /api/frontend/order, channels documentation | YES (touches backend NF525 path) |
| **A4** | NF525 + Auth + Pricing SSOT (invariant preservation) | Mobile sends composition only (no prices), idempotency UUID on every POST, fiscal_sequence_no flow validated, AwardLoyaltyPointsOnDelivery is sole credit source | YES (NF525 critical) |
| **A5** | Visual Mandate + Assets + UX Parity | Playwright capture mobile on 11 cats × wizards, pricing parity verification mobile UI↔backend preview, raw label sweep | NO (test-only) |
| **A6** | Test / Adversarial / Ship | E2E suite for mobile→backend flow (11 cats), hostile dispute, BRAIN+Graphiti update, GO/NO-GO verdict | YES (final ship gate) |

---

## §3 — Axis A1 : Data Layer Truth Reconciliation

### Goal
Make all documentation files point to the same canonical truth as DB seed commands. **Mobile data is already aligned; we are aligning the docs that aren't.**

### Files in scope (READ-ONLY for code, EDIT-ONLY for docs)
- `config/menu.php` — STALE. Decide: deprecate header (point to seed command as SSOT) OR rewrite to reflect 11 cats / 11 sauces / 10 supplements @ 0.90 €.
- `mobile/CONNECTION_PLAN.md` — STALE "13 categories" + outdated endpoint inventory. Refresh §1 §2 §7.
- `mobile/data/menu.js` — already aligned. Add a TOP-of-file SSOT pointer comment ("DB seed commands `MenuResetLeCayenneCommand` + `MenuHealLightV2Command` are authoritative").
- (NEW) `docs/MENU_V3_SSOT.md` — single canonical source listing every category / item / variation / extra / addon / composer profile with DB id, slug, price, and current state. Generated from artisan command (read-only export).

### Acceptance criteria
- [ ] `config/menu.php` header updated with "**STALE — see DB seed commands**" warning OR fully rewritten to match DB (recommended: deprecate as SSOT, mark for removal V1.0.1).
- [ ] `CONNECTION_PLAN.md` §1 §2 §7 refreshed : 11 cats, 42 items, sauces=11, supplements=10 @ 0.90 €, 4 viandes.
- [ ] `docs/MENU_V3_SSOT.md` created and committed (1 source of truth post-reset+heal-light).
- [ ] `mobile/data/menu.js:1-5` header SSOT pointer added.
- [ ] Test (manual) : `grep -c 'sauces' config/menu.php` matches DB-canonical count; `grep '13 catégories' CONNECTION_PLAN.md` returns 0 hits.

### Risk
- LOW. Pure docs. No frozen-zone touch. No NF525 impact.

### Rollback
- `git revert` the single commit. No data loss.

---

## §4 — Axis A2 : Wizard Parity (mobile catches up to backend canonical)

### Goal
Mobile renders the same wizard surface as kiosk for every category, INCLUDING the
custom composer profiles (Bols 4-step + Frites 1-step). Mobile becomes
composer-aware (per the BRAIN-known P1-1 drink-label fix).

### Files in scope (mobile only — frozen kiosk/POS not touched)
- `mobile/screens-item-steps.jsx` — main work :
  1. Add `'custom'` case in `computeActiveSteps()` (l.56-130) reading `item.composer_profile.steps[]` from API payload.
  2. Add `item.wizard_template` priority (currently reads `category.wizard_template` only at l.59).
  3. Add `item.viande_count` exposure (kiosk uses `detectViandeCount()` heuristic — mobile must read backend field).
  4. Add cascade label override : if step has `addon_role='drink'`, use `composer_step.label` from API, not hardcoded `'Boisson'`.
- `mobile/data/menu.js` — minor :
  5. Add `composer_profile` field to Bols + Frites items (4 steps for bols, 1 step for frites — mirror kiosk DB profile shape) for V0 standalone mode.
- `mobile/screens-main.jsx` — verify cart line composition_summary preserves composer step labels.

### Acceptance criteria
- [ ] Bols Frites/Riz × 4 viandes (8 items) render 4-step wizard (Base / Sauce / Suppléments / Drink).
- [ ] Petite/Grande Frites render 1-step wizard (Style : Nature / +Cheddar +1 € / +Cheddar+Oignons +2 €).
- [ ] Sandwich Cayenne / Galette / Sandwich Classique / Burgers / Tacos render template `sandwich`/`tacos` correctly (no regression).
- [ ] Suppléments / Desserts / Boissons / Menu enfant remain direct-add (no wizard).
- [ ] Cascade label : if backend exposes step.label = "Boisson (optionnel)", mobile shows that EXACT label (not "QUEL MENU ?").
- [ ] Vitest + Playwright filter `mobile-wizard-*.spec.js` 100 % pass on the 6 affected items.

### Risk
- MEDIUM. Mobile-only change. No frozen-zone touch (kiosk Vue / pos-wizard.js / KioskApp untouched).
- Watch : do NOT import kiosk wizard logic; mobile must remain a separate render layer driven by the same API contract.

### Rollback
- `mobile/screens-item-steps.jsx` revert single file. Bols/Frites fall back to 'simple' (current state).

### Dependencies
- Depends on A1 docs alignment so reviewers can verify expected wizard shapes.
- Independent of A3/A4 (V0 standalone can render composer locally from data/menu.js).

---

## §5 — Axis A3 : API Surface for Mobile (backend contract)

### Goal
Backend exposes a coherent customer-facing surface that mobile can consume without breaking NF525 / multi-tenant / idempotency invariants.

### Files in scope
- `routes/api.php` — add `/api/frontend/menu/customer/{branch}` (no `kiosk:order` ability) OR document that mobile reuses `/api/frontend/menu` with new ability.
- `app/Http/Controllers/Frontend/MenuController.php` — add `customer($branchId)` method projecting items with `channels` NULL-tolerant (no `mobile_app` requirement until V1.0.1).
- `app/Http/Requests/OrderRequest.php` — broaden `authorize()` to accept `tokenCan('kiosk:order') || tokenCan('customer:order')`.
- `app/Models/User.php` (or token issuance flow) — document Sanctum token ability assignment for mobile users (`customer:order`).
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` — verify `idempotency.enabled` is **true by default** for `frontend.order.*` routes (RED P0).
- `app/Services/FrontendOrderService.php` — confirm `myOrderStore` accepts integer `item_id` (NOT slug). Mobile must send IDs. Document the contract in OpenAPI / route docblock.
- (NEW) `docs/MOBILE_API_CONTRACT.md` — request/response shapes for menu, order, loyalty, idempotency-key generation, error codes.

### Acceptance criteria
- [ ] Mobile Sanctum token can be created with ability `customer:order`.
- [ ] `OrderRequest::authorize()` accepts both abilities (kiosk OR customer).
- [ ] `/api/frontend/menu/customer/{branch}` returns same shape as `/api/frontend/menu` but accessible with `customer:order` ability.
- [ ] Channels handling : items with `channels=NULL` continue to be returned (no breaking change). Document that mobile sees the same items as kiosk until V1.0.1 introduces channel separation.
- [ ] `X-Idempotency-Key` UUID v4 required on `POST /api/frontend/order` + `/payment-confirm` — middleware enabled by default (verify `config('idempotency.enabled')` default `true` for these routes specifically).
- [ ] PHPUnit filter `MobileApi|OrderRequest|IdempotencyKey` 100 % green.
- [ ] `docs/MOBILE_API_CONTRACT.md` committed with request/response examples for every endpoint mobile consumes.

### Risk
- HIGH (backend changes on NF525 path). Touches OrderRequest authz — must not weaken existing `kiosk:order` enforcement.
- Frozen-zone check : `BranchScope`, `IdempotencyKeyMiddleware`, `PricingService` — verify diff = 0 lines, only **additions** to widen authorization (no rewrites).
- Multi-tenant : mobile Sanctum token must carry a `branch_id` claim or fall back to user's home branch. Verify `User.branch_id` flow.

### Rollback
- Revert OrderRequest authz to kiosk-only. Mobile cannot create orders until A3 redone.

### Dependencies
- Independent of A1/A2. Blocks A4 (NF525 flow validation) and A6 (E2E test).

---

## §6 — Axis A4 : NF525 + Auth + Pricing SSOT (invariant preservation)

### Goal
Every mobile order respects the 3 NF525 invariants (Pricing SSOT, immutable composition_snapshot, monotonic fiscal_sequence_no) PLUS multi-tenant + idempotency + sole-source loyalty credit.

### Files in scope
- `mobile/api/api.js` (NEW) — fetch wrapper with bearer token + base URL + `X-Idempotency-Key` UUID v4 auto-generation.
- `mobile/data/menu.js:391-423` — `priceFor()` function : keep for V0 standalone preview, but mark as **non-authoritative**. Add `serverPriceOf(payload)` async fn that hits `/api/frontend/pricing/preview` for the real total.
- `mobile/screens-main.jsx` (cart screen) — display server-side total once available; show `priceFor()` as instant estimate with a "calcul serveur en cours…" affordance.
- `mobile/screens-onboarding.jsx::ScreenLogin` + `ScreenOTP` — replace mock with real `signupOtp` + `verifyOtp` calls (Sanctum token assignment).
- `mobile/data/loyalty.js` — remove the +25 pts client-side modal credit (mocked); document that backend `AwardLoyaltyPointsOnDelivery` is THE source.
- `app/Services/Pricing/PricingService.php` — **NO TOUCH** (frozen). Verify mobile consumes `calculateOrder` results via `/pricing/preview`.
- `app/Listeners/AwardLoyaltyPointsOnDelivery.php` — verify idempotency sentinel (existing per Integration Auditor l.49-60). No change.
- `app/Services/FrontendOrderService.php::finalizePaidKioskOrder` (l.1130-1167) — verify fiscal allocation fires for mobile-source orders too (source_surface = `'mobile'`).

### Acceptance criteria
- [ ] Mobile order payload contains NO price fields (only `item_id`, `qty`, `variation_ids`, `extra_ids`, `addon_ids`, `wizard_selections`).
- [ ] Server response includes `composition_snapshot` JSON — mobile displays it (server is SSOT for display too, not local recompute).
- [ ] Every `POST /api/frontend/order` from mobile carries `X-Idempotency-Key: <uuid v4>`.
- [ ] Mobile retry of the same key returns `Idempotency-Replayed: true` (cached response).
- [ ] Mobile-source orders allocate `fiscal_sequence_no` on payment-confirm (verify via DB inspection : `SELECT fiscal_sequence_no FROM orders WHERE source_surface='mobile'` — all rows non-null post-payment).
- [ ] Loyalty +25 modal removed from mobile UI ; balance only reflects `AwardLoyaltyPointsOnDelivery` outcomes.
- [ ] `audit_logs` HMAC chain integrity preserved post mobile orders (hash chain verify script PASS).
- [ ] `tests/Feature/MobileNF525Test.php` (NEW) covers : pricing client-injection rejected, idempotency replay, fiscal monotonic, audit chain hash.

### Risk
- CRITICAL. NF525 violations = legal exposure. Triple defense in code review + multi-agent QA + RED dispute mandatory.

### Rollback
- Mobile auth wiring revert. `PricingService` untouched. No fiscal damage rollback needed (only **adding** mobile as a client, not changing logic).

### Dependencies
- Depends on A3 (API surface ready).
- Blocks A5/A6.

---

## §7 — Axis A5 : Visual Mandate + Assets + UX Parity

### Goal
Per CLAUDE.md §6 mandatory : capture every mobile flow that touches the new system surfaces, Read each screenshot, analyze for raw labels / empty states / pricing parity / branding.

### Surfaces to capture (mobile @ port 8081)
1. Home (11 cats visible, correct sort order 1→11).
2. Menu Sandwich Cayenne — wizard 'sandwich' 5-step flow.
3. Menu Galette — same.
4. Menu Sandwich Classique — same.
5. Menu Burgers — same (NEW heal-light V2).
6. Menu Tacos — wizard 'tacos' (1v / 2v / Big).
7. Menu Bols Gourmands — wizard 'custom' 4-step (Base / Sauce / Supp / Drink) — **gate critical**.
8. Menu Frites — wizard 'custom' 1-step (Style).
9. Menu Suppléments — direct-add, no wizard.
10. Menu Desserts — direct-add.
11. Menu Boissons — direct-add.
12. Menu enfant — direct-add (NEW heal-light V2).
13. Cart — composition_summary correct, server total displayed.
14. Pay choice — Stripe placeholder OR card-at-counter flow.
15. Confirm — fiscal_sequence_no displayed, queue number.
16. Orders en cours — active order with KDS sync (real branch_id mobile→KDS via Outbox).
17. Loyalty — balance from API, history paginated, opt-in/opt-out flow.
18. Profile — user data from API.

### Visual analysis criteria (per CLAUDE.md §6.5)
- [ ] No raw labels (`Label.X`, `kiosk.foo`, `0undefined`, `NaN €`).
- [ ] No empty state where data exists.
- [ ] No layout overflow / responsive break.
- [ ] Mobile total === backend `/pricing/preview` result (sample 10 carts).
- [ ] All 11 sauces visible (not 4 or 15).
- [ ] All 10 supplements @ 0.90 € visible (Boule gratinée @ 2.00 € separate).
- [ ] All 4 viandes visible (Poulet mariné/curry/tandoori/crispy).
- [ ] Console clean (0 errors, 404s gated as known pre-existing only).
- [ ] a11y baseline preserved (WCAG 2.1 AA — focus management, role, aria-*).

### Acceptance criteria
- [ ] Playwright spec `tests/e2e/mobile-realignment-2026-05-16.spec.js` produces 18 screenshots, all 18 Read+analyzed.
- [ ] Report `reports/test-e2e/mobile-realignment-2026-05-16/VISUAL_VERDICT.md` with per-surface verdict.
- [ ] Zero P0 visual offender. ≤2 P2 acceptable.

### Risk
- LOW. Test-only.

### Rollback
- Discard captures.

### Dependencies
- Depends on A2 + A3 + A4 (wizard rendering + API responses + pricing flow live).

---

## §8 — Axis A6 : Test / Adversarial / Ship

### Goal
Three layers of verification BEFORE declaring mobile realignment GO :
1. **Technical E2E** — every flow green.
2. **Adversarial RED** — hostile dispute by independent sub-agent.
3. **Owner gate** — final human go.

### Tests to write/run
- **Vitest** : mobile `priceFor()` regression suite ALIGNED to backend `PricingService` golden file (20 fixtures).
- **PHPUnit** :
  - `MobileNF525Test.php` (pricing client-injection, idempotency replay, fiscal monotonic, audit chain).
  - `MobileSanctumAbilityTest.php` (customer:order vs kiosk:order, branch_id claim).
  - `MobileOrderFlowTest.php` (slug→id resolution if needed, composition_snapshot integrity).
- **Playwright** :
  - 11 categories × wizard happy path (sandwich / tacos / custom / simple variants).
  - 3 error paths : network drop with retry, idempotency replay, soft-deleted item attempt.
  - Realtime sync : mobile order → KDS update via Outbox+Pusher (use existing KDS Playwright with cross-tab driver).
- **Sentinels** : verify `php artisan foodking:sentinels-js` 100 % PASS post-realignment.

### Adversarial RED protocol
- Spawn 1 hostile sub-agent (`agents/qa-red-team-prompt.md` RED mode) with this exact framing :
  - "The previous cycle declared mobile realignment GREEN. Find why it's still NOT production-ready."
  - "Dispute every test result. Prove a single P0 the team missed."
- RED must produce file:line citations. No fabricated P0s.
- If RED finds 0 P0 + ≤2 P1 → GO.
- If RED finds 1+ P0 → back to relevant axis, max 3 healing cycles, then escalate owner.

### Acceptance criteria
- [ ] All PHPUnit/Vitest filter green.
- [ ] All Playwright specs green on first run AND second run (stability).
- [ ] Frozen-zone diff = 0 lines on : `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`, `pos-wizard.js`, `pos-wizard.css`, `FiscalSequenceService.php`, `ZReportService.php`, `AuditLogService.php`, `BranchScope.php`, `PricingService.php`, `OrderStateMachine.php`. `IdempotencyKeyMiddleware.php` may have +N lines if A3 added config defaults — **document explicitly**.
- [ ] NF525 audit chain hash verification PASS (script TBD per existing iter14 cycle).
- [ ] BRAIN.md §3 LAST DONE updated with cycle summary.
- [ ] Graphiti `foodking` group : 1 episode pushed with cycle outcome.
- [ ] Memory file written : `project_mobile_realignment_cycle_2026-05-XX.md`.
- [ ] Ship verdict in `reports/audit/mobile-realignment-2026-05-XX/FINAL_VERDICT.md` with explicit GO / NO-GO + evidence pack.

### Risk
- Wall-clock budget : ~5-8 days agent if no escalation, ~2-3 weeks with healing.
- Frozen-zone breach is the biggest risk — must monitor diff after every commit.

### Rollback
- Each axis has its own rollback. Master rollback : revert all commits since master plan execution start, restore DB backup (none needed since no schema change in this plan).

### Dependencies
- A6 is the gate, depends on all axes A1-A5.

---

## §9 — Sequenced execution waves

| Wave | Axes | Parallelizable? | Estimated effort | Owner-gate before? |
|---|---|---|---|---|
| **W1** | A1 (docs reconciliation) | yes (1 Implementer subagent) | 0.5 day | NO |
| **W2** | A2 (wizard parity) + A5 visual baseline | partially (A2 must finish before A5 final) | 1.5 day | YES (A2 touches mobile render) |
| **W3** | A3 (API surface) | yes (1 Implementer subagent) | 2 days | YES (NF525 path) |
| **W4** | A4 (NF525 + pricing flow) | depends on W3 | 2 days | YES (NF525 critical) |
| **W5** | A5 (full visual sweep) + A6 (tests + adversarial) | A5 + A6 partly parallel | 2 days | NO during execution, YES at final ship |
| **W6** | A6 ship gate | sequential | 0.5 day | YES (final) |

**Total wall-clock estimate** : 8.5 day-agent + 4 owner-gate touchpoints (recommend batch-approve W1+W2 first, then W3+W4 after seeing W2 visuals, then W5+W6 after seeing W4 NF525 evidence).

---

## §10 — Risk Register

| ID | Risk | Severity | Mitigation |
|---|---|---|---|
| R1 | Mobile wizard refactor accidentally imports kiosk Vue code | HIGH | Strict file scope : mobile/* only. Reject any PR with kiosk component import. |
| R2 | NF525 fiscal_sequence_no skipped on mobile orders | CRITICAL | A4 acceptance criteria explicit. Adversarial RED check mandatory. |
| R3 | Pricing client-side persists, NF525 drift | CRITICAL | priceFor() marked non-authoritative + server preview required for display. Vitest golden fixtures vs backend. |
| R4 | Idempotency middleware default-disabled in production | HIGH | A3 acceptance : verify `config('idempotency.enabled')` is true. Sentinel test added. |
| R5 | Loyalty double-credit (mobile +25 modal + backend listener) | MEDIUM | A4 : remove modal credit. Listener idempotency sentinel already exists (Integration Auditor l.49-60). |
| R6 | Channel filter mis-seeded → mobile sees empty menu | MEDIUM | A3 : confirm `channels=NULL` returns items. Defer mobile_app channel to V1.0.1. |
| R7 | Slug↔item_id payload mismatch breaks order creation | HIGH | Mobile must send integer `item_id`. Update mobile cart to carry both slug + id (id authoritative). |
| R8 | Sanctum mobile token issuance flow missing → users can't auth | HIGH | A3 : document login endpoint OR create one. SMS provider verification. |
| R9 | Adversarial RED finds late-cycle P0 → 3-loop healing exhausted | MEDIUM | Max 3 heals, then escalate owner per CLAUDE.md §10. |
| R10 | Frozen-zone accidental touch | CRITICAL | `safety-check.sh` pre-commit + diff inspection per commit. Use `/lock-plan` skill if any frozen file MUST be touched. |
| R11 | KDS sync from mobile order silent failure (Branch.status mismatch BRAIN backlog) | MEDIUM | A6 E2E : verify Pusher push reaches KDS for branch_id=1. Workaround : explicit branchId fire if needed. |
| R12 | CONNECTION_PLAN.md drift documentation cycle (becomes stale again) | LOW | A1 commits a SSOT pointer at top of CONNECTION_PLAN.md indicating "see docs/MENU_V3_SSOT.md". |

---

## §11 — Non-Goals (deliberately out of scope)

These are NOT part of this realignment ; they belong to V1.0.1 or later :
- Native build (Capacitor) — Phase 11 in CONNECTION_PLAN.md.
- Stripe customer-facing PaymentIntent (still TPE only).
- Supabase migration — owner already deprioritized in feedback memory `feedback_v1_focus_no_saas_2026-05-08.md`.
- `loyalty_rewards` table creation — backlog B-11.
- `loyalty_physical_cards` — backlog D5.
- Mobile app channel filter `mobile_app` — backlog V1.0.1.
- Cart desync server-side sync — accepted device-local for V1.
- Wallet (Apple/Google Pay) — Phase 11 stub already documented in `mobile/WALLET_PLAN.md`.
- KDS overflow flag UI for mobile-spawned orders — V1.0.1 hardening sprint backlog.

---

## §12 — Owner Gate — Required Decisions Before Execution

Owner must answer these 4 questions before we exit ULTRA-PLAN mode and begin Wave 1 :

### Q1 — Documentation strategy for `config/menu.php`
- **Option A** : rewrite `config/menu.php` to match DB post-reset (11 cats, 11 sauces, 10 supps @ 0.90 €).
- **Option B** : deprecate `config/menu.php` (mark stale + point to DB seed commands as SSOT).
- **Recommendation** : Option B (simpler, no risk of new drift). Followed by full removal in V1.0.1.

### Q2 — Mobile API contract path
- **Option A** : create new `customer:order` Sanctum ability + new `/menu/customer/{branch}` endpoint. Cleaner separation kiosk/customer for analytics.
- **Option B** : broaden `OrderRequest::authorize()` to accept any token with `frontend:*` ability, treat mobile as kiosk variant. Fewer code changes.
- **Recommendation** : Option A (clear audit trail, future-proof for V1.0.1 channel filter).

### Q3 — Pricing display strategy
- **Option A** : mobile shows `priceFor()` instant estimate + server preview overlay ("calculé sur le serveur"). Cleaner UX but two-source code.
- **Option B** : mobile shows ONLY server preview (no client estimate). Slower UX but absolute SSOT.
- **Recommendation** : Option A (V1 ux compromise, V1.0.1 may migrate to B if pricing logic gets complex).

### Q4 — Bols/Frites composer profile delivery mode
- **Option A** : extend `mobile/data/menu.js` with hard-coded `composer_profile` for V0 standalone, mirror DB shape.
- **Option B** : ONLY render composer profile from API response (no V0 standalone fallback for Bols/Frites).
- **Recommendation** : Option A for V0 (preserves standalone mode), then keep both in sync until A3 wireup complete.

---

## §13 — Subplans (to be authored after owner-gate)

If owner approves master plan, the following sub-plans will be authored before execution :
- `plans/SUB_A1_DOCS_RECONCILIATION_2026-05-XX.md`
- `plans/SUB_A2_WIZARD_PARITY_2026-05-XX.md`
- `plans/SUB_A3_API_SURFACE_2026-05-XX.md`
- `plans/SUB_A4_NF525_PRICING_FLOW_2026-05-XX.md`
- `plans/SUB_A5_VISUAL_MANDATE_2026-05-XX.md`
- `plans/SUB_A6_TEST_ADVERSARIAL_SHIP_2026-05-XX.md`

Each sub-plan follows the same structure : Goal / Files / Acceptance / Risk / Rollback / Dependencies / Sequenced tasks. Detail level : ready for Implementer subagent to execute scope-minimal with TDD-first.

---

## §14 — Memory + BRAIN Updates

- **PROJECT_BRAIN.md §4 NEXT PLAN** : pointer to this master plan.
- **Memory** : new file `project_mobile_realignment_ultraplan_2026-05-16.md` indexed in `MEMORY.md`.
- **Graphiti** : push 1 episode `foodking` group with master plan summary + 6 axes + 4 owner-gate decisions.
- **Reference** : cross-link to `mobile/CONNECTION_PLAN.md` (will be refreshed by Axis A1).
- **Decisions log** (this cycle) : reconciled RED P0 contradictions ; mobile data found aligned to DB ; real P0s are integration-level not data-level ; 6-axis plan structure adopted ; owner-gate 4 questions raised.

---

## §15 — Final Verdict (planning phase)

🟡 **CONDITIONAL GO TO EXECUTION** subject to owner answering Q1-Q4.

**Once owner approves, execution proceeds via :**
- GStack pipeline 7-step (Think→Plan→Build→Review→Test→Ship→Reflect)
- Superpowers subagent-driven-development for each axis
- Adversarial RED at end of every wave
- CLAUDE.md §5 LOOP discipline
- Visual mandate §6 mandatory for A2 + A5
- Frozen-zone enforcement §7 absolute
- NF525 invariants §8 protected

— End master plan —
