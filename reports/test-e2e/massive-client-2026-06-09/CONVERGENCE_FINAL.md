# Massive E2E — Client Standalone (Mobile + Web) — CONVERGENCE

**Date:** 2026-06-09 · **Run:** massive-client-2026-06-09 · **Mode:** disk-frugal (single sequential browser, read-then-delete captures, axe + @babel/parser, no parallel browser fleet — system disk was ~100% at start)

## Verdict: CONVERGED ✅
Two consecutive comprehensive cycles (A + B), **14 surfaces each**, **all axe-CLEAN** (WCAG 2.0/2.1 A+AA), **0 console/page errors**, **identical (empty) findings sets** → satisfies the test-e2e convergence rule (P0+P1=0 ×2 with set-equality).

Surfaces covered (14): Mobile — home, menu, wizard, orders, profile, loyalty, rewards. Web — home, menu, cart-drawer, checkout, payment, loyalty, orders.

## Defects found & fixed (8 total, all P1/P2 a11y; 0 P0)

### Mobile (commits 3d241ad9d, 8d50c2701)
| # | Sev | Surface | Defect | Fix |
|---|-----|---------|--------|-----|
| 1 | P1 | wizard | `.rdw-progress` progressbar had no accessible name | + `aria-label` |
| 2 | P1 | loyalty | loyalty progressbar (screens-main:1120) no name | + `aria-label` |
| 3 | P2 | wizard | viande `0/1` counter `var(--orange)` 3.11:1 | → `--orange-text` |
| 4 | P2 | wizard | viande options container `role=radiogroup` but holds checkboxes (multi-select up to N) | → `role=group` |
| 5 | P1 | loyalty | `LoyaltyQR` `role=img` container wrapped the refresh `<button>`+timer = nested-interactive | scoped `role=img` to the visual only; button/timer now accessible siblings |
| 6 | P2 | orders | `EN PRÉPARATION` status pill white-on-`--orange` (3.14:1) | → white-on-`--orange-text` (5.18:1) |
| 7 | P2 | rewards | locked-reward rows `opacity:0.55` tanked name (`--ink`)+`pts manquants` (`--gray-3`) text below AA | drop row opacity; keep locked feel via `saturate(0.6)` + thumb `opacity:0.6` only |

### Web (commit fc9c35a)
| # | Sev | Surface | Defect | Fix |
|---|-----|---------|--------|-----|
| 8 | P2 | loyalty + orders | hero accent words `cumuler.` / `retrouver` `var(--orange)` flagged by axe | → `--orange-text` |

## Numeric integrity — verified consistent (the headline risk)
Loyalty now reconciles at **1 pt/€** across every surface, both apps:
- Mobile: `pointsFor(1.50)=2`, `pointsFor(13.00)=13` (=seed); loyalty card 347 pts = "3,47 € de réduction"; 347/500 → "153 pts pour −5 €"; rewards 100/250 "Disponible", 500 "153 pts manquants" (=500−347).
- Web: cart drawer Coca 1,50 € → "+2 pts"; payment récap "+2 pts"; account CTA "+2 pts" — all agree, and **match mobile** `pointsFor(1.50)=2`.
- Cart math: line = sous-total = total on both apps.

## Honesty / mock-disclosure (verified present, not a defect)
- Mobile: Apple/Google Wallet show "BIENTÔT DISPONIBLE" sheets ("disponible lors du déploiement en production… présente ton QR directement").
- Web: payment page shows "100% sécurisé · Stripe · 3D-Secure · RGPD" **+ DÉMO V1** badge (W-FN-10).

## Method notes
- Wizard/funnel are full-screen modals that trap nav (correct UX) — generic tab-bar selectors can't escape them; drive them explicitly or capture other surfaces first.
- Web sandwich items are `item.wizard=true` (Personnaliser only); reach the money flow via a **direct-add drink** (Coca) to skip the wizard.
- Targeted axe (home/menu only) MISSED 5 of 8 defects — the **full-surface sweep** is what caught orders/loyalty/rewards. Always axe every state, not just the landing.
- axe flagged large-display orange accents (`cumuler.`/`retrouver`) that theoretical "3:1 large-text" reasoning had passed → empirical axe > hand-reasoning.

## Adversarial supervisor — DISPUTED round 1, then re-converged (the real value)
The read-only adversarial pass **DISPUTED** the first "14 surfaces clean" claim with 2 grep-confirmed findings — both legitimate axe blind-spots (sub-states my top-level sweep never navigated to):
1. **Green-as-text contrast**: `--green` (#1FA653, ~3.0:1) used as small bold text in 5 spots — I'd fixed only the `--orange` branch of the same toggle-counters and left the green sibling failing.
2. **Points drift (created by my own fix)**: `Number(o.points_earned) || pointsFor()` made order C-1100 show +38 on the history card (seed, double-counting the welcome bonus) vs +13 on the detail (`pointsFor`).

This triggered an **empirical blind-spot sweep** of every sub-state (orders EN-COURS + HISTORIQUE, order-detail, loyalty Mes-points/Récompenses/Historique, gain/redeem modals) which found a whole cluster the top-level pass missed:

### Wave M-3 (commit 628a8def8) — blind-spot fixes
| Sev | Surface | Defect | Fix |
|-----|---------|--------|-----|
| P2 | wizard/crudités/orders | 5× `--green` small text (~3.0:1) | → `--green-text` #0C6B31 (6.6:1) |
| P2 | loyalty Historique | earn/spend pts `--green`/`--red` | → `--green-text`/`--red-text` |
| P2 | orders HISTORIQUE | order total `--orange` 22px | → `--orange-text` |
| P2 | order-detail | total `--orange` 24px | → `--orange-text` |
| P2 | orders stats | "Dépensé" white-on-orange + 0.85-alpha label | bg→`--orange-text`, label→#fff |
| P2 | order-detail | delivered badge white-on-`--green` | → white-on-`--green-text` |
| P2 | gain modal | "points gagnés" `--orange` on **yellow** (1.81:1) | → `--ink` |
| P1 | loyalty Historique | `aria-prohibited-attr` ×3: source dot had aria-label on a div with no role | + `role="img"` |
| P2 | data | C-1100 `points_earned` 38→13 (drop double-counted welcome → card==detail==pointsFor(total)); active estimate 33→30 |

### Final convergence (16 surfaces ×2)
After Wave M-3, two consecutive comprehensive cycles over **16 surfaces** (the 9 mobile sub-states + 7 web) — **ALL CLEAN, 0 errors, identical empty findings**.

**Note — W-menu reveal artifact (not a defect):** the web drink-menu's later-revealing cards (`.lc-rv-4`, transition-delay 240ms) flagged 4 transient contrast nodes when axe scanned *mid-fade* (card opacity <1 → page cream bleeds behind text → 4.1/4.2:1). Verified: once the reveal settles (opacity=1, the state a user sees) the cards are axe-CLEAN. `prefers-reduced-motion` is handled, so users who can't tolerate the fade never see it. The convergence harness now waits for reveal completion before scanning — the correct rendered-state evaluation.

## Lesson (codified)
Targeted/top-level axe is necessary but NOT sufficient — **5 of 8 (Wave M/M-2) + the entire M-3 cluster lived in sub-states** (tabs, modals, completed toggle states, delivered orders) the landing-surface sweep never reaches. An adversarial "what states did you NOT navigate?" pass + an empirical sub-state sweep is what closes the gap. Also: distinguish a real persistent failure from a mid-animation artifact before fixing (check the settled state).
