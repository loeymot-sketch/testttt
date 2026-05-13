# AUDIT_PLAN — Mobile design full E2E vs Kiosk parity — 2026-05-11

**Mission verbatim (owner)** : *"passe un test e2e à toute la partie de design complet de l'app par raisonnement fort pour tout corriger ! comparant avec kiosk pour data et design"*

**Run id** : `mobile-design-full-2026-05-11`
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10`
**Mode** : test-e2e skill — GStack capture + Adversarial supervisor — loop until 2 identical GREEN rounds (or 3-cycle cap)
**Workers** : `1` (locked)
**Iteration cap** : 3 healing cycles

---

## 0. Clarifications to surface BEFORE round-1 capture

Two parity scenarios in the user brief are written in ways that GStack agents will guess at without an answer. Surface these to the owner; the plan ships a working default but the owner can swap.

### CLARIF-1 — Palette parity directionality
Mobile palette `--orange #FF5A1F`, `--yellow #FFD93D`, `--ink #0A0A0A` is the *new* design (memory note `project_kiosk_design_refresh_2026-05-10`). Kiosk is in a **frozen zone** (§7 CLAUDE.md, `KioskWizardComponent.vue` / `KioskAppComponent.vue` / `KioskUpsellComponent.vue`).

**Working assumption (default if owner silent)** : "parity" means **mobile palette is the source of truth** (post-refresh), Wave E captures kiosk side-by-side as **visual reference only** — divergences logged as P3 (cosmetic), not P0/P1. No fix proposed on kiosk side without explicit LOCK plan.

**Alternative interpretation** (would reverse priority) : mobile must match kiosk legacy palette → would require Wave A-D recolor — out of scope this round.

### CLARIF-2 — Member badge parity
Mobile profile card renders `#FK-12345 · IKYES B.` (member id + initial). Kiosk `KioskLoyaltyComponent` only renders points balance + register/lookup forms in current production (§9 CLAUDE.md). **Restated as PARITY-3-bis** : *loyalty points-display parity* — mobile points pill (`347 pts`) ↔ kiosk balance screen value, captured for both surfaces, must reconcile to the same mocked seed (347).

Both clarifications are flagged in the plan; round-1 proceeds with the working defaults unless owner answers.

---

## 1. Pre-flight setup (run before round-1)

1. Mobile server : `php -S 127.0.0.1:8081 -t /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/` — **verified UP, HTTP 200**.
2. Kiosk server : Laravel `:8000` — **verified UP, HTTP 200**.
3. Helper present : `tests/e2e/helpers/mega-audit-snap.js` — **verified present** (no copy required).
4. Reports dir : `reports/test-e2e/mobile-design-full-2026-05-11/round-1/` — **already created** (sibling to FINDINGS_SCHEMA + REVIEWER_PROTOCOL).
5. Captures cible roots :
   - `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-A/`
   - `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-B/`
   - `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-C/`
   - `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-D/`
   - `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-E/`
6. Mobile loyalty bootstrap helper present : `tests/mobile-e2e/utils/waitForLoyaltyReady.js` — **verified**.

---

## 2. Reuse signal (anti-duplication)

GStack sub-agents : **fork existing specs**, don't rebuild from scratch.

| New spec | Fork from | Why |
|---|---|---|
| `tests/e2e/test-e2e-mobile-design-full-wave-A.spec.js` | `tests/e2e/audit-mobile-wave-A-2026-05-11.spec.js` | Splash detection + `[data-screen-label]` waits already proven |
| `tests/e2e/test-e2e-mobile-design-full-wave-B.spec.js` | `tests/e2e/audit-mobile-wave-B-2026-05-11.spec.js` | 13 category chips iteration + ScreenItem entry |
| `tests/e2e/test-e2e-mobile-design-full-wave-C.spec.js` | `tests/e2e/audit-mobile-wave-C-2026-05-11.spec.js` | Wizard click-chain + Cart capture |
| `tests/e2e/test-e2e-mobile-design-full-wave-D.spec.js` | `tests/e2e/audit-mobile-wave-D-2026-05-11.spec.js` | Profile + Loyalty + Modals selectors |
| `tests/e2e/test-e2e-mobile-design-full-wave-E.spec.js` | `tests/e2e/audit-kiosk-cycle5-2026-05-07.spec.js` + `audit-kiosk-multiproduct-kds-journey.spec.js` | Kiosk idle + wizard navigation + loyalty screen |

**Naming rule** : new specs MUST use `test-e2e-mobile-design-full-wave-X` slug so capture dirs match `test-e2e-mobile-design-full-wave-X/`. Adversarial supervisor reads dir name from `state_artifact` field.

---

## 3. Wave structure (5 waves, parallel-friendly)

### Wave A — Onboarding visuel (Splash → OTP)
- **Spec** : `tests/e2e/test-e2e-mobile-design-full-wave-A.spec.js`
- **Config** : `tests/mobile-e2e/playwright.config.js` — baseURL `:8081`, iPhone 14 390x844, no globalSetup. **Do NOT run with root playwright config.**
- **Captures dir** : `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-A/`
- **States (10)** :
  - 01-splash-fresh (fresh storage, splash auto-advance 1800ms)
  - 02-onb1 (palette discovery card)
  - 03-onb2 (paiement card)
  - 04-onb3 (fidélité card)
  - 05-onb4 (commencer CTA)
  - 06-login-empty (phone input visible, +33 prefix)
  - 07-login-typed-french-mobile (e.g. 0642799884 typed)
  - 08-otp-empty (6 digit boxes)
  - 09-otp-typed-1234 (4-digit OTP fills, auto-advance check)
  - 10-home-post-login (greeting + tab bar visible)
- **Assertions** :
  - lc-display (Anton font) present on onb headlines
  - Dots progression (4 dots) visible on onb1-4
  - Palette tokens `--orange`, `--yellow`, `--ink`, `--paper`, `--cream` resolvable on `:root`
  - No raw label leak (`Label.*`, `kiosk.*`, `lecayenne.*` in visible text)
- **Acceptance** : 10 quartets emitted, 0 console error (allowlist permitted), 0 page error, `[data-screen-label]` visible across all 10 states.

### Wave B — Catalog + Wizard mobile (Home → Menu 13 cats → Item Detail wizard 8-step → Cart)
- **Spec** : `tests/e2e/test-e2e-mobile-design-full-wave-B.spec.js`
- **Config** : `tests/mobile-e2e/playwright.config.js` (same as Wave A).
- **Captures dir** : `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-B/`
- **States (16)** :
  - 01-home-greeting-am (greeting BONJOUR or BONSOIR depending on `Date()` — log which)
  - 02-home-featured-tacos-xxl (featured card visible, slug match)
  - 03-home-loyalty-banner (points pill 347 + CTA)
  - 04-home-categories-grid-13 (13 chips visible)
  - 05-menu-all-items
  - 06-menu-filter-tacos
  - 07-menu-filter-burgers
  - 08-item-tacos-xxl-step1-viandes (0/4)
  - 09-item-tacos-xxl-step2-sauce (Ketchup picked, free)
  - 10-item-tacos-xxl-step3-supplement-oeuf (Œuf checked → +1,00 €)
  - 11-item-tacos-xxl-step4-menu (Menu picked → +3,00 €)
  - 12-item-tacos-xxl-step5-frites-style-cheddar (Cheddar fondu → +1,00 €)
  - 13-item-tacos-xxl-step6-frites-sauce (1st sauce free, 2nd sauce → +0,50 €)
  - 14-item-tacos-xxl-recap (total 18,00 € visible)
  - 15-cart-1-line (after add, total 18,00 €)
  - 16-cart-empty (after trash)
- **Pricing decomposition (assert exactly)** :
  ```
  Tacos XXL base ................................ 12,50 €
  + Œuf (supplement) ..........................   1,00 €
  + Menu (formule) ............................   3,00 €
  + Cheddar fondu (frites-style) ..............   1,00 €
  + 1ère sauce (Ketchup) .................... gratuite
  + 2ème sauce (BBQ) ..........................   0,50 €
  ────────────────────────────────────────────────────
  Total = 18,00 €
  ```
- **Assertions** :
  - 13 catégories rendues exactement (count check)
  - qty stepper clamp min=1
  - empty-state copy ≥ 20 chars + primary CTA visible
  - Featured card slug attribute = `tacos-xxl`
- **Acceptance** : 16 quartets, 0 page error, total 18,00 € observable on both recap (state 14) and cart (state 15).

### Wave C — Payment + Confirm + Orders
- **Spec** : `tests/e2e/test-e2e-mobile-design-full-wave-C.spec.js`
- **Config** : `tests/mobile-e2e/playwright.config.js`.
- **Captures dir** : `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-C/`
- **States (12)** :
  - 01-modal-pay-choice (clic Payer from cart, ModalPayChoice 2 options visible)
  - 02-modal-pay-counter (caisse flow → confirm)
  - 03-screen-confirm-success (order success + +25 points banner)
  - 04-modal-points-gain-confetti (overlay visible, dismissable)
  - 05-modal-pay-card-flow (Stripe flow entry)
  - 06-screen-stripe-placeholder (CB form placeholder, no real Stripe)
  - 07-screen-stripe-error-empty (validation feedback if any)
  - 08-back-to-cart-from-stripe (back nav)
  - 09-screen-orders-active-tab (1+ active order post-confirm)
  - 10-screen-orders-historique-tab
  - 11-screen-order-detail-active (line items + total + status pill)
  - 12-screen-order-detail-history
- **Assertions** :
  - cart total === recap total === confirm total (numeric_integrity)
  - status pill rendered on active orders
  - modal `aria-modal=true` + ESC close
  - ModalPointsGain confetti renders without console error
- **Acceptance** : 12 quartets, numeric_integrity P0 = 0, ESC closes modals.

### Wave D — Profile + Loyalty multi-sections + WizardRedeem + Modals
- **Spec** : `tests/e2e/test-e2e-mobile-design-full-wave-D.spec.js`
- **Config** : `tests/mobile-e2e/playwright.config.js`.
- **Captures dir** : `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-D/`
- **Bootstrap** : use `tests/mobile-e2e/utils/waitForLoyaltyReady.js` to seed account + history + consent before captures.
- **States (18)** :
  - 01-screen-profile (user card + loyalty preview + menu rows + logout)
  - 02-screen-loyalty-hero-qr (HERO QR default)
  - 03-screen-loyalty-hero-barcode-toggle
  - 04-screen-loyalty-points-section
  - 05-screen-loyalty-actions-rapides (Apple + Google Wallet buttons)
  - 06-screen-loyalty-plastic-card-section
  - 07-screen-loyalty-tab-mes-points
  - 08-screen-loyalty-tab-recompenses
  - 09-screen-loyalty-tab-historique
  - 10-screen-loyalty-infos-rgpd-section
  - 11-wizard-redeem-step1-preview (clic redeem)
  - 12-wizard-redeem-step2-timing
  - 13-wizard-redeem-step3-success
  - 14-modal-card-link
  - 15-modal-wallet-apple-v0-notice
  - 16-modal-wallet-google-v0-notice
  - 17-modal-opt-out-confirm
  - 18-modal-points-gain-confetti (re-capture from loyalty context)
- **Assertions** :
  - Loyalty points (347 mocked) consistent profile preview + loyalty screen
  - QR persist via storage (refresh keeps QR)
  - WizardRedeem 3-step idempotency (re-trigger same reward = no double-debit)
  - RGPD opt-out clears consent (storage check)
  - Rewards tiers visible : `[100, 250, 500, 1000, 2000]` (or whatever wallet-spec defines)
- **Acceptance** : 18 quartets, no double-debit, opt-out clears consent.

### Wave E — Kiosk parité visuelle référence (READ-ONLY, frozen zone respect)
- **Spec** : `tests/e2e/test-e2e-mobile-design-full-wave-E.spec.js`
- **Config** : **ROOT** `playwright.config.js` (Laravel globalSetup, kiosk session, baseURL `:8000`, desktop viewport). **DO NOT use mobile config.**
- **Captures dir** : `tests/e2e/__screenshots__/test-e2e-mobile-design-full-wave-E/`
- **Frozen-zone notice** : Wave E is **read-only**. Captures only. Findings about kiosk surface land as P3 (cosmetic, parity-reference) unless they reveal a P0 regression IN the kiosk (which would block on a separate LOCK plan, not this round).
- **States (10)** :
  - 01-kiosk-idle-screen (KioskAppComponent, palette discovery)
  - 02-kiosk-categories-welcome (post-start)
  - 03-kiosk-cat-309-assiettes
  - 04-kiosk-wizard-product-step-sauce
  - 05-kiosk-wizard-product-step-supplements
  - 06-kiosk-wizard-recap (any Tacos XXL combo if available — fallback Tacos standard)
  - 07-kiosk-loyalty-input (KioskLoyaltyComponent step 1)
  - 08-kiosk-loyalty-register
  - 09-kiosk-loyalty-balance (points display)
  - 10-kiosk-confirmation-screen
- **Assertions** (parity-reference only) :
  - Palette tokens captured for diff (`computedStyle(:root)` dump in DOM HTML)
  - Anton font (lc-display) presence on kiosk if any (probably absent)
  - Loyalty balance value captured for CLARIF-2 reconcile
- **Acceptance** : 10 quartets emitted, no spec failure (test runs to green even if findings exist).

---

## 4. Cross-surface parity scenarios

All parity scenarios are **observation + log**, not auto-fix. They land as findings; orchestrator decides per CLARIF-1 / CLARIF-2 direction.

### PARITY-1 — Tacos XXL composition total
- Mobile Wave B state 14 (recap) **must** display `18,00 €`.
- Mobile Wave B state 15 (cart) **must** display `18,00 €`.
- Mobile Wave C state 03 (confirm) **must** display `18,00 €`.
- Kiosk Wave E state 06 (wizard recap) — if a Tacos XXL combo is reachable, total **should** also be `18,00 €` (config-driven on kiosk side, may differ if kiosk pricing rules diverge — flag as P0 if difference > 0,01 €).
- **Decomposition reference** : see Wave B § Pricing decomposition.

### PARITY-2 — Palette tokens (per CLARIF-1 default working assumption)
- Mobile palette source of truth :
  ```
  --orange  #FF5A1F
  --yellow  #FFD93D
  --ink     #0A0A0A
  --paper   #FFFFFF
  --cream   #FAF7F2
  ```
- Wave A state 02 / Wave B state 04 / Wave D state 02 : assert tokens present on `:root` via DOM HTML inspect.
- Wave E state 01 / Wave E state 03 : capture kiosk computed styles for **side-by-side reference**.
- Findings : mobile drift = **P1**. Kiosk drift vs mobile = **P3** (informational only).

### PARITY-3-bis — Loyalty points value reconcile (replaces original PARITY-3 member badge)
- Mobile Wave D state 04 : loyalty points pill shows seeded value (target `347`).
- Mobile Wave D state 01 : profile preview pill **must** match (same `347`).
- Kiosk Wave E state 09 : loyalty balance screen **must** show the same seeded value if the seed flows through (likely separate seed — if kiosk shows different value, log P3 reference only).
- Failure mode : mobile profile ≠ mobile loyalty = **P0** (numeric_integrity within same surface). Mobile ≠ kiosk = **P3** (cross-surface, expected divergence in V0).

### PARITY-4 — Loyalty rewards tiers
- Source of truth : `mobile/data/wallet-spec.js` (or wherever rewards array is defined — confirm during round-1).
- Mobile Wave D state 08 : rewards tab displays `[100, 250, 500, 1000, 2000]` (or whatever spec emits).
- Kiosk : tiers not directly rendered in `KioskLoyaltyComponent` balance screen → **observation only**, no parity assertion required.
- Failure mode : mobile rewards diff vs spec file = **P1**.

---

## 5. Cross-wave invariants (all waves)

Adversarial supervisor checks these per state across all 5 waves :

1. **0 raw label** : DOM regex `Label\.[A-Za-z0-9_.]+|kiosk\.[a-z_.]+|^0undefined$|NaN\s*€` → P1 if found in visible text.
2. **0 white-on-white** : alpha-blending PNG sweep — < 95% pixels above 240/240/240 → P2.
3. **0 console error** outside allowlist (REVIEWER_PROTOCOL § Allowlist) → P1.
4. **0 page error** (React unhandled exception, Babel parse fail) → P0.
5. **A11y baseline** : role/tabindex/onKeyDown on interactive cards ; aria-live on dynamic counter/total ; aria-disabled on disabled CTA → P2 (P1 if blocks primary path).
6. **Palette drift** : per PARITY-2 working assumption → P1 mobile / P3 kiosk.
7. **Numeric integrity** : per PARITY-1 → P0 if cart ≠ recap ≠ confirm.

---

## 6. Convergence criteria

- Two consecutive rounds with `verdict: GREEN` (per FINDINGS_SCHEMA) across all 5 waves.
- Set-equality on finding IDs and statuses round-to-round.
- `open_P0 == 0` AND `open_P1 == 0` summed across waves.
- All 4 cross-wave invariants (1, 3, 4, 7) at zero.

If round-3 (cap) does not converge, orchestrator escalates to owner with finding diff vs round-2.

---

## 7. Out-of-scope

- **Real Sanctum auth** : mobile V0 standalone — auth stub via `LC.storage.setAuth({token:'test'...})`.
- **Real Stripe payment** : placeholder form only — no card capture, no PSP roundtrip.
- **Backend integration** : V0 mobile is standalone — no API mock work in this round.
- **Push notifications** : Phase 11, out of scope.
- **Wallet pkpass real signing** : V0 uses stub SVGs in `ModalWalletV0Notice`.
- **Kiosk modifications** : Wave E is read-only; any kiosk P0 found is documented but not fixed in this round.
- **POS / KDS / OSS surfaces** : not in mobile design audit scope. Cross-surface sync = Phase 6 deferred.
- **Pixel-diff vs prior baselines** : qualitative-only this round (visual hash drift downgraded to P3 informational).

---

## 8. Run order (orchestrator)

1. GStack-A captures Wave A → emits 10 quartets.
2. GStack-B captures Wave B → 16 quartets. (parallel-safe vs A)
3. GStack-C captures Wave C → 12 quartets. (parallel-safe vs A/B)
4. GStack-D captures Wave D → 18 quartets. (parallel-safe vs A/B/C)
5. GStack-E captures Wave E → 10 quartets. (parallel-safe IF kiosk server doesn't share session state — verify before parallel)
6. Adversarial-A reviews Wave A artifacts → `round-1/wave-A-findings.json`.
7. Same for B / C / D / E in parallel.
8. Orchestrator aggregates → if all 5 `verdict: GREEN` and set equality with round-N-1 → STOP. Else → patch + round-2.

**Total states target** : 66 quartets per round (10+16+12+18+10).
**Total findings target** : ~70-80 expected on round-1 across all severities (per advisor sizing).

---

## 9. Risk register

| Risk | Severity | Mitigation |
|---|---|---|
| Wave E parity ambiguity blocks GStack-E from selecting fixes | HIGH | CLARIF-1 + CLARIF-2 surfaced at top; working defaults locked. |
| Mobile V0 + kiosk seed mismatch breaks PARITY-3-bis | MED | Restated as cross-surface P3, mobile-internal P0 only. |
| Tacos XXL combo not reachable on kiosk Wave E | MED | Fallback : capture any wizard recap, log "Tacos XXL not in kiosk catalog" as P3 informational. |
| 66 quartets × 5 reviewers > token budget on a single round | MED | Each adversarial agent scoped to ONE wave; reviewer pulls only its dir. |
| Babel transform errors slip past `pageerror` listener | LOW | mega-audit-snap already captures `pageerror` (verified existing). |
| Frozen-zone kiosk patch tempted by GStack-E | LOW | Wave E explicitly read-only in plan + CLAUDE.md §7 enforcement. |

---

## Out-of-band (not in plan execution)

Adversarial supervisor is **not** a Playwright spec — it's an Agent invocation per wave that reads the artifact quartet (PNG vision + DOM + console + network) and emits `wave-<W>-findings.json`. The orchestrator (Claude main) reads all 5 JSONs, computes aggregate verdict, and decides loop continuation.

---

**End of plan.** GStack agents : fork existing wave specs (§ 2 Reuse signal), rename, retarget capture dir, append Wave-E parity captures. Owner answers CLARIF-1 + CLARIF-2 OR plan proceeds with stated defaults.
