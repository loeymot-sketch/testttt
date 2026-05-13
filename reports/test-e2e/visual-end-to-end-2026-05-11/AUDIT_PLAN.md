# AUDIT_PLAN — Visual End-to-End test-e2e 2026-05-11

**Run id**: `visual-end-to-end-2026-05-11`
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10`

## Mission (owner verbatim FR)
« passe utilise gstack team et adversaire pour analyser tout l'affichage de l'app de page d'accueil à derniere page de payement, pour une seconde confirmation couvre des vue super dev qui voi avec une 3eme oeil de dev master ! »

## Scope: 3 waves, 3-tier review

### Wave A — Kiosk PRIMARY (home→payment customer flow)
- **Spec**: `tests/e2e/test-e2e-visual-e2e-2026-05-11-wave-A-kiosk.spec.js`
- **Screenshots**: `tests/e2e/__screenshots__/test-e2e-wave-A-kiosk/`
- **Context**: 1 × kioskPage (loginAsKiosk), viewport 1080×1920 portrait
- **States (24)**: 01-idle-fresh → 02-lang-fr → 03-lang-en → 04-order-type → 05-categories → 06-cat-switch → 07-tacos-focused → 08-wizard-viande-empty → 09-wizard-viande-filled → 10-wizard-sauce → 11-wizard-menu → 12-wizard-extras → 13-wizard-recap → 14-cart-tacos → 15-cat-cart-indicator → 16-frites-simple → 17-cart-2-lines → 18-cart-qty-plus → 19-upsell → 20-payment-modal → 21-payment-loading → 22-confirmation → 23-thank-you → 24-return-idle
- **Wallclock**: ~9 min

### Wave B — POS SECONDARY (operator home→receipt)
- **Spec**: `tests/e2e/test-e2e-visual-e2e-2026-05-11-wave-B-pos.spec.js`
- **Screenshots**: `tests/e2e/__screenshots__/test-e2e-wave-B-pos/`
- **Context**: 1 × posPage (loginAsPosOperator), viewport 1440×900 landscape
- **States (19)**: login-empty → login-filled → catalog-default → cat-sidebar → tile-tap → wizard-viande → wizard-sauce → wizard-extras → wizard-recap → cart-1-line → cart-multi → cart-qty-edit → payment-cb → payment-especes → payment-tpe → success-toast → receipt-modal → receipt-print → return-catalog
- **Wallclock**: ~8 min

### Wave C — Edge states (third-eye fuel)
- **Spec**: `tests/e2e/test-e2e-visual-e2e-2026-05-11-wave-C-edge.spec.js`
- **Screenshots**: `tests/e2e/__screenshots__/test-e2e-wave-C-edge/`
- **Contexts**: 2 (kiosk + POS in describe blocks)
- **States (15)**: cart-empty / backdrop-dismiss / modal-stacking / mid-flow-back / rapid-double-click / slow-network / payment-failure-simulated / i18n-EN-deep / pos-cart-empty / wizard-escape / payment-cancel / receipt-print-fail / pos-double-tap / pos-back-from-payment / pos-rapid-qty-spam
- **Wallclock**: ~7 min

**Total**: 58 states × 4 quartet = 232 artifacts per round.

## 3-tier review system (headline ask)

### Tier 1 — GStack team (capture + reason)
- 1 agent per wave (3 total)
- Writes spec, runs it, emits artifact quartet, reasons across 5 lenses (Architect/Tester/A11y/SRE/DBA)
- Output: `round-N/wave-W-gstack-findings.json`

### Tier 2 — Adversarial supervisor
- 1 agent per wave (spawned after GStack done)
- VISUAL FIRST priority. REVIEWER_PROTOCOL.md 12 categories
- Output: `round-N/wave-W-adversarial-findings.json`

### Tier 3 — Master Dev third-eye (headline)
- 1 agent per wave (spawned after Adversarial done)
- Reads tier-1 + tier-2 first. MUST extend, not duplicate
- **8 architect_categories** (mandatory enum):
  1. `design-system` — component variants, spacing scales, color token slots
  2. `information-architecture` — cognitive load, action hierarchy, primary CTA discoverability
  3. `brand-consistency` — Le Cayenne `#F4501E` palette adherence, chrome consistency
  4. `a11y-deep` — focus order, keyboard flow, screen-reader semantic structure
  5. `responsive` — tablet kiosk + POS landscape narrow viable
  6. `microinteraction` — transition/loading/error/success quality
  7. `defensive-edge` — back-button mid-flow, rapid double-click, network slowdown
  8. `cross-surface-coherence` — kiosk vs POS as same product
- Output: `round-N/wave-W-masterdev-findings.json` with mandatory `architect_category` field

## Severity escalation rules

- Adversarial P2 vs Master Dev P0 → final = P0 (Master Dev seniority binds)
- Master Dev P0 on a state Adversarial marked clean → final P0, **auto-blocks loop**
- Allowlist (REVIEWER_PROTOCOL.md) applies to all 3 tiers; Master Dev may NOT override

## Frozen zones

- `public/js/pos-wizard.js` — CAPTURE-ONLY. Wave B states 06-09 may capture but spec must NOT modify pos-wizard.js. Any defect → LOCK_<id>.md required before fix.
- Kiosk wizard `resources/js/components/frontend/kiosk/*` — auditable
- `playwright.config.js` workers=1 invariant
- NF525 backend — read-only

## Out-of-scope

- Mobile app `:8081` (covered by mobile-wizard-e2e-2026-05-11)
- KDS, OSS, Admin dashboards (not payment flows)
- Dine-in V1 (feature flag disabled — captured ONCE as evidence)
- Delivery / online ordering
- Real Stripe/TPE wire (simulated state captures)

## Convergence

A round is GREEN when, for every wave (A, B, C):
- Merged findings: `open_P0 == 0` AND `open_P1 == 0`
- All 3 tier verdicts = GREEN
- No tier-3 finding contradicts tier-1/tier-2 clean signal at P0/P1

Audit converges when 2 consecutive rounds are GREEN with set-equality on finding IDs.

## Wallclock budget per round

| Wave | Capture | Adversarial | Master Dev | Total |
|---|---|---|---|---|
| A | 9 | 5 | 6 | 20 min |
| B | 8 | 4 | 5 | 17 min |
| C | 7 | 4 | 5 | 16 min |
| **Round total** | 24 | 13 | 16 | **~53 min** |

Convergence target: 2-3 rounds → ~2.5h end-to-end.

— END OF AUDIT_PLAN —
