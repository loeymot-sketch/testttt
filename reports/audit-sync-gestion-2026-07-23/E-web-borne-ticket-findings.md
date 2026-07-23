# Dimension E — Web + Borne + Ticket Cuisine (adversarial, READ-ONLY)

HEAD `b8084b310` · local backend UP (8000, DB 2933 orders) · PHP 8.2.30
Method: reproduce on REAL data → dispute each finding → verdict.

## Evidence gathered

### PHP↔JS parity (KitchenTicketSymbolicFormatter ↔ kdsSymbolic.js) — AIRTIGHT
- Exported 600 real `order_items` (snapshot+instruction+resolved name), rendered
  via PHP, replayed the JS twin through `vite-node`. **0 mismatch** across
  `mainLine / supplements / menuLine / fritesSauceSymbol / extraSauceNames /
  isMenuItem / isDrinkItem`.
- 10 **adversarial synthetic** instructions (digit-before-"sauce", leading note+".",
  "SAUCE:", multi-line "TACOS M\n…Sauce : A, B", "Sauce frites:") → **0 divergence**.
  The one asymmetry (PHP lookbehind `(?<![\p{L}])` vs JS consuming `(?:^|[^\p{L}])`)
  never diverges — both `slice(1)` the same tail group.
- Automated suites re-run green: **86 vitest + 17 PHPUnit**.

### Ticket rendering correctness (real orders)
- **Tacos** item 5484 → `G | TAC | P | STO | ALG AND`: **no size** (M dropped),
  meats present, the 2 sauces (incl. Algérienne + extra Andalouse) **folded together
  on Line 1**, **no separate "+ Sauce supplémentaire" line**. Exactly owner spec.
- Item 4726 → `CAY | P | STO | ALG KTP` + `+ Cornichon`, `+ Cheddar` — supplement
  (Cheddar) **appears**; sauce folded.
- Supplement `Champignons` (5650, 0,90€) renders as `+ Champignons` (paid, not folded
  into crudités). Menu/frites sauce → Line-2 `MENU : SYM` logic present & tested.

### Web money-path — affiché == scellé
- 12 recent `source_surface='web'` orders: **0 line-level mismatch**
  (`total_price == price*qty + extras + variations − discount`); order `total` == Σ lines.
- Backend `FrontendOrderService.php:580` enforces `expected_total` (rejects
  |server−declared| > 0,01); web `api.js:597` sends it. A placed order ⇒ equality proven.
- Formule/boissons priced **1,90** in web `data/menu.js` (f-frites, f-boisson, canettes).

## Findings

### F-E1 — "Ticket perd le NOM de la sauce" — DISPUTED → historical, NOT live
- Repro: **10/600** items render generic nameless `+ Sauce supplémentaire` (name lost).
  ALL have `instruction=null` **AND** generic `extra_name`. Incl. web orders 5648-5650
  (2026-07-22).
- Dispute: formatter behaviour is **correct** — documented retro-compatible fallback for
  unparsable input. Current live web `api.js` writes the name TWICE: into the extra name
  (`"Sauce supplémentaire (Andalouse)"`, l.413) AND the instruction (`"Sauces en plus :
  Andalouse"`, l.497). Borne `KioskWizardComponent` emits FR i18n `sauces_extra` =
  **"Sauces en plus : {list}"** → caught by the regex. Verified renders: with either signal
  → `SAN | P | MAY AND` (folded) or `+ Sauce supplémentaire (Andalouse)` (named). Only the
  double-null legacy case loses the name.
- Verdict: **root cause = stale deployed bundle / pre-fix orders, not the formatter.**
  Owner action: guarantee the deployed web+borne bundle is current (recurring = deploy lag).

### F-E2 — Borne reachable, formule steps present — INFO
- VPS `/kiosk/idle` → **HTTP 200, not login-gated** (SPA shell "Le Cayenne").
- Formule cascade steps `frites_style / boisson / frites_sauce` present in KioskWizard
  (the 3-page split machinery). Full visual 3-page flow **UNVERIFIED** (browser blocked).

### F-E3 — Non-FR locale sauce recovery gap — LATENT / V2, low
- AR `sauces_extra` = "صلصات إضافية: {list}" is **not** matched by the formatter's FR/EN
  regex → sauce name would fall back to generic in Arabic locale. EN ("Extra sauces:") IS
  caught. V1 is FR-locale locked (ADR-007) ⇒ not a V1 defect; flag for V2 multilingual.

## Limitations (honest)
- **Browser automation infra-blocked**: 3 Chrome browsers connected; harness requires
  interactive selection via AskUserQuestion (unavailable to a subagent). Could NOT: place a
  live end-to-end order, nor visually capture the wizard "Incluse" badge / formule 3-page
  split / Pixel-7 mobile drawer / legal pages. Live web + VPS checked via server-side
  WebFetch — SPA shells clean (no raw labels/`{{}}`/undefined, a11y "Aller au contenu"
  skip-link present).
- No DB writes; nothing modified. Money-path proven on existing sealed orders, not a new one.

## Verdict
Ticket-cuisine engine (parity + tacos + multi-sauce fold + supplements) and web money-path
are **production-sound on current code**. No live P0/P1 in this dimension. The recurring
sauce-name complaint traces to deploy/bundle currency (F-E1), not the formatter. Visual
surfaces (badges, mobile drawer, formule 3-page, legal) remain to be confirmed once browser
access is available.
