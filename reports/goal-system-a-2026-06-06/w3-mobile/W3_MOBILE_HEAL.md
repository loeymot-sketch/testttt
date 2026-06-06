# W3_MOBILE_HEAL — System-A standalone mobile (W3 heal, P1 a11y + honesty)

> **Date**: 2026-06-06 · **Agent**: MOBILE-EXEC (fresh per-system heal) · **Viewport**: 390×844 (iPhone 13, scripted Playwright, deviceScaleFactor 2)
> **Server**: `http://127.0.0.1:8087/index.html` (already running, reused) · **Scope**: EXACTLY the 4 W2 P1 clusters — button-name, nested-interactive, contrast+progressbar, démo disclosure. **Loyalty ratio (G1) untouched.**
> **Method**: per fix → file:line edit → fresh axe-core @390 (full ruleset) re-run on 8 surfaces → screenshots re-captured + Read back → card INTERACTION test. 2 consecutive identical axe cycles (anti-flake).
> Paths relative to worktree root `testttt/.claude/worktrees/pre-cloud-exec/`.

---

## 1. Executive result

| Bar (GOAL §0.4) | Baseline (W2) | Post-heal (W3) |
|---|---|---|
| axe **critical** (8 surfaces) | **4** (button-name) | **0** |
| axe **serious** (8 surfaces) | **54** (nested-interactive 42 · contrast 10 · progressbar-name 2) | **0** |
| card whole-tap preserved | — | **YES** (interaction-verified) |
| +/personnalise independent | — | **YES** (interaction-verified) |

- Aggregate post-heal (2 consecutive runs, identical): **critical=0, serious=0**, moderate=228 (dominated by `region` no-landmark — explicitly out of scope, see note below), minor=0.
- **Honest tradeoff note (menu moderate 16 → 150)**: the W2 baseline had menu moderate=16; the W3 card flatten raised it to 150 (`region ×147 · landmark-unique ×2 · heading-order ×1`). This is **caused by the flatten** (not pre-existing): removing each card's single `role="button"` widget means the card body content is no longer enclosed in one ARIA widget, so the pre-existing **`region` (content not inside a landmark) family now fires per-element instead of once-per-card**. It is the same tradeoff the web tree accepted (HEAL T1-09). All 134 new moderates are the `region`/`landmark` family — **no new rule type appeared**, and `region` is moderate, explicitly out of the 0-critical/serious bar and out of scope this loop (P2 — wrap screen bodies in `<main>`). The serious nested-interactive ×41 is gone; the trade is serious→moderate, which is a net a11y improvement against the GOAL bar.
- Raw post-heal axe JSON: `reports/goal-system-a-2026-06-06/w3-mobile/mobile-axe-post.json`.
- Card interaction result: `reports/goal-system-a-2026-06-06/w3-mobile/card-interaction-result.json`.
- Screenshots: `reports/goal-system-a-2026-06-06/w3-mobile/shots/` (Read back, see §6).
- Harness: `reports/goal-system-a-2026-06-06/w3-mobile/harness/{w3-verify.spec.js,w3.config.js}`.
- Regression: loyalty specs 01/03/04/07 still GREEN after LoyaltyQR restructure.

---

## 2. Per-surface axe (post-heal)

| Surface | critical | serious | moderate |
|---|---|---|---|
| 07 Home | 0 | 0 | 30 |
| 08 Menu | 0 | 0 | 150 (region ×147 · landmark-unique ×2 · heading-order ×1) |
| 10 Cart | 0 | 0 | 19 |
| 11 Confirmation | 0 | 0 | 8 |
| 12 Orders | 0 | 0 | 2 |
| 14 Loyalty | 0 | 0 | 16 |
| 09 Item Wizard (Viandes) | 0 | 0 | 3 |

(orderDetail/stripe captured for honesty read-back; not in the 8-surface axe baseline list.)

---

## 3. Fixes (file:line · before → after · proof)

### [P1-A11Y-1] button-name critical ×4 — home featured heart buttons
- **File**: `mobile/screens-main.jsx:157` (ScreenHome featured cards).
- **Before**: `<button style={...}><I.Heart/></button>` — icon-only, no accessible name (×4).
- **After**: added `type="button" aria-label={`Ajouter ${it.name} aux favoris`}` + `onClick` (stopPropagation + toast "Favoris — bientôt disponibles", mirroring the item-detail heart at `:365`).
- **Proof**: home post-heal axe critical=0 (was 4 button-name).

### [P1-A11Y-2] nested-interactive serious ×42 — menu cards ×41 + loyalty QR ×1
- **File A**: `mobile/screens-main.jsx:244` (ScreenMenu card) + `:254` (name).
  - **Before**: outer `<div role="button" tabIndex={0} aria-label onKeyDown class="lc-tap" onClick>` WRAPPING an inner `<button>` (+/personnalise at `:262-263`) → axe serious ×41.
  - **After**: mirrored the web `ItemCard` HEAL T1-09 pattern — outer is now a **plain `<div onClick>`** (dropped role/tabIndex/aria-label/onKeyDown/lc-tap; a plain div onClick is NOT an interactive ARIA node, so the inner button is no longer nested). The item **NAME** promoted to a real `<button aria-label={`Voir ${name} — ${price} €`} onClick={stopPropagation+go('item')}>` with web's transparent style-reset → the focusable control keyboard users reach for the detail view. The +/personnalise button left as-is (already had stopPropagation + aria-label, now a sibling).
- **File B**: `mobile/components/LoyaltyQR.jsx:56`.
  - **Before**: outer container `role="img" aria-label` WRAPPED the interactive "Régénérer" `<button>` → axe serious ×1.
  - **After**: outer container is a plain `<div data-testid="loyalty-qr" data-payload>` (test attrs preserved); `role="img"`+`aria-label` moved DOWN to wrap ONLY the QR/barcode graphic. Regen button is now a sibling of the image, not a descendant of `role="img"`.
- **Proof**: menu serious=0, loyalty serious=0. **Whole-card tap preserved + verified** (see §4).

### [P1-A11Y] color-contrast ×10 + aria-progressbar-name ×2
Two classes, fixed at the failing nodes (not blanket); **`--orange` token untouched** (brand fill preserved); `--orange-text:#C2410C` (already defined, 4.86:1) reused for small text on light.
- **Class A — orange small text on light → `--orange-text`**:
  - Menu price `screens-main.jsx:258`; Cart line price `:657`; Confirm ticket total `:754`; Wizard `0/1` status `screens-item-steps.jsx:338`. (Fallback ScreenItem `0/1` at `screens-main.jsx:406` aligned too for consistency.)
- **Class B — white text on orange FILL ≈ 3:1 → ink text (option b, keeps vivid brand orange; mirrors `.lc-btn--yellow` dark-on-bright)**:
  - Cart checkout `.lc-btn` `screens-main.jsx:715`; Orders status pill `:847`; Confirm "Suivre" `.lc-btn` `:772`.
- **Confirm "CAYENNE" logo** (`shared.jsx` Logo accent `#C2410C`) sits on the **yellow** confirm bg (#FFD93D) → failed. Fixed per-caller: `screens-main.jsx:726` passes `accent="var(--ink)"` to the Logo (shared default left intact for white-bg callers).
- **Progressbar names**: `screens-main.jsx:1120` (loyalty) + `screens-item-steps.jsx:238` (wizard `.rdw-progress`) — added `aria-label` (`aria-valuetext` alone does not satisfy aria-progressbar-name).
- **Proof**: all 8 surfaces serious=0 (contrast 0, progressbar-name 0).

### [P1-HONESTY] démo disclosure on stripe + confirm + orderDetail
Reused the existing wallet/opt-out disclosure pattern (orange/ink pill + cream/ink box).
- **Stripe** `screens-modals.jsx:81` (after price): cream box + orange "DÉMO" pill + "Paiement & commande non synchronisés — prototype de démonstration. Aucune carte n'est débitée, aucune commande n'est envoyée en cuisine."
- **Confirm** `screens-main.jsx:734` (under "envoyée"): ink/yellow pill "DÉMO — COMMANDE NON SYNCHRONISÉE".
- **orderDetail** `screens-modals.jsx:262`: orange/ink pill "DÉMO — NON SYNCHRONISÉ" **above** the NF525 line. **Fiscal text "Reçu fiscal NF525" left intact (badge only, per contract; NF525-in-customer-app = owner gate G6).**
- **Proof**: §6 screenshot read-back — all three legible, palette intact.

---

## 4. Card interaction test (fix #2 — not axe-only)

Scripted Playwright, real clicks @390 (`harness/w3-verify.spec.js`):
- **(a) card body tap** → clicked the card's thumbnail region (a plain div inside the outer `onClick`) → screen became **`09 Item Wizard Viandes`**. ✅ whole-card tap opens the detail/wizard.
- **(b) +/personnalise independence** → a direct-add item's `+` button (`aria-label="Ajouter … au panier"`): screen **before=`08 Menu`, after=`08 Menu`** → the inner button added to cart and did **NOT** trigger the card's `go('item')` navigation. ✅ independent.

Both assertions pass in 2 consecutive runs. Result file: `card-interaction-result.json`.

---

## 5. NOT touched (hard constraints honored)
- Loyalty ratio / "10 pt par €" label (`screens-main.jsx:1333`) / dead `earn_ratio=10` — **untouched** (owner gate G1).
- NF525 fiscal text — **not removed/reworded** (badge only).
- Prices / item count — untouched (G2/G3).
- `--orange` token + shared `.lc-btn--orange`/`.lc-pill--orange` classes — untouched (only inline failing nodes changed).
- P2/P3 (region ×90, heading-order, OTP demo-code, home top-edge) — out of scope.

---

## 6. Screenshot read-back (visual verdict)

| Shot | Verdict |
|---|---|
| `shots/02-menu.png` | Card layout fully intact; prices in darker orange (still brand hue, AA); +/arrow buttons unchanged. No break, no Cayenne red. |
| `shots/03-cart.png` | "VALIDER MA COMMANDE" now ink-on-orange (legible); line price darker orange; 1pt/€ banner untouched. |
| `shots/04-stripe.png` | DÉMO disclosure (orange pill + cream box) clear & legible; mock card form below; palette intact. |
| `shots/05-confirm.png` | "LE CAYENNE" logo now ink on yellow (legible); "DÉMO — COMMANDE NON SYNCHRONISÉE" badge visible; total darker orange; "SUIVRE" ink-on-orange. |
| `shots/07-orderDetail.png` | "DÉMO — NON SYNCHRONISÉ" badge above NF525 line; **NF525 fiscal text unchanged**. |
| `shots/08-loyalty.png` | QR card + countdown + regen button render correctly post-restructure; progressbar 347/500 intact; ratio label NOT in viewport (untouched). |
| `shots/01-home.png` | featured hearts now have names (visual unchanged). |
| `shots/09-wizard.png` | viandes step; `0/1` status darker orange; progress bar intact. |

**Visual verdict: PASS** — no layout break, no raw label, no double-layer, palette (noir/orange/jaune/blanc) intact, all démo badges legible.

---

## 7. Honesty notes
- orderDetail "EN PRÉPARATION" status pill (white-on-orange, `screens-modals.jsx`) renders on a white bg here but was **NOT** in the 8-surface axe baseline (orderDetail isn't an axe surface). It is visually bright/legible; out of the flagged-node scope this loop. Flagged for a future pass if orderDetail is added to the axe matrix.
- The stripe "VISA" badge (`screens-modals.jsx:85`, small orange on white) was not axe-tested (stripe not in axe surfaces); left untouched to stay scope-minimal.
