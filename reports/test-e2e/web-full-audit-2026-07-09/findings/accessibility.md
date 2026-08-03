# Le Cayenne web standalone — ACCESSIBILITY (WCAG 2.1 AA) audit — 2026-07-09

Target: /Users/1millnonstop/Downloads/web  (React + Babel-in-browser static site)
Method: grep/Read only. Every finding has file:line + reproduced grep evidence.

## GOOD (verified, no finding)
- Landmarks: skip-link (index.html:46 `<a href="#main">`), `<main id="main">` (index.html:135),
  `<header className="lc-nav">` (components.jsx:125), `<nav className="lc-nav-links">`
  (components.jsx:131), `<footer className="lc-footer">` (components.jsx:185). Complete.
- WebModal (components.jsx:240-295): role="dialog" + aria-modal="true" + Tab focus TRAP
  (getFocusable + shift/Tab cycling 253-265) + focus-in-on-open + restore-to-opener-on-close
  (270-284) + Escape-to-close + aria-labelledby/aria-label. Exemplary.
- Icon-only buttons ALL carry aria-label: modal close components.jsx:290, cart close flows.jsx:61,
  trash flows.jsx:89, qty −/+ flows.jsx:81/83, wizard qty flows wizard-v2.jsx:671/673, back buttons
  account-v2.jsx:225/254 & upsell.jsx:108 & wizard-v2.jsx:584, clear-search screens.jsx:428,
  favourite screens.jsx:54 (also aria-pressed), burger components.jsx:152 (aria-expanded/controls).
- Images: EVERY <img> has an alt attribute. Informative imgs use alt={item.name} / descriptive
  (screens.jsx:40, screens.jsx:123 hero "Sandwich Cayenne signature — Le Cayenne", screens-v3.jsx:166,
  wizard-v2.jsx:602/656). Redundant thumbnails beside a visible product NAME correctly use alt=""
  (flows.jsx:73, funnel.jsx:109, upsell.jsx:91, wizard-v2.jsx:488/517) — decorative choice is correct.
- Palette remediated: dedicated AA text tokens --orange-text #C2410C (4.86:1), --green-text #0C6B31
  (6.64:1), --red-text #A8142A (5.92:1) with inline heal comments (styles.css:10/25-27). Yellow text
  (#FFD93D) is used only on DARK backgrounds (.lc-wallet ink gradient styles.css:530, .lc-app-cta
  var(--ink) styles-v5.css:178, on var(--ink) chips account-v2.jsx:110, funnel.jsx:318) → high contrast.
- OTP inputs labelled + keyboard nav (account-v2.jsx:260-270 aria-label `Chiffre N` + Backspace focus).
- Tablist semantics account-v2.jsx:134-136 (role=tablist/tab/aria-selected). aria-live error/status
  regions throughout (funnel.jsx:295, account-v2.jsx:275, components.jsx:141 cart badge role=status).
- Custom role="button" span (account-v2.jsx:257 "Modifier") has tabIndex=0 + onKeyDown Enter/Space.

## FINDINGS

### P2 a11y-1 — Unlabelled text inputs (promo) — placeholder only, no accessible name
- flows.jsx:110  `<input value={promo} ... placeholder="Ex: CAYENNE10"/>`  (cart drawer)
- funnel.jsx:286 `<input value={ctx.promoInput||''} ... placeholder="Ex: CAYENNE10"/>` (checkout)
No `<label htmlFor>`, no aria-label, no aria-labelledby. The visible `<h5>// Code promo</h5>`
(flows.jsx:108) / `<h3>// Code promo</h3>` (funnel.jsx:284) is a heading, NOT programmatically
associated. Placeholder is not an accessible name (disappears on input, unreliable AT support).
WCAG 4.1.2 Name/Role/Value (Level A), 3.3.2 Labels or Instructions, 1.3.1.
Repro: `grep -n "<label" flows.jsx funnel.jsx` → no label references acc-promo / these inputs.

### P2 a11y-2 — Unlabelled textareas (kitchen note) — aria-describedby but no NAME
- flows.jsx:119  `<textarea className="lc-notes" ... aria-describedby="cart-notes-counter"/>`
- funnel.jsx:300 `<textarea className="lc-notes" ... aria-describedby="notes-counter"/>`
aria-describedby points at the char-counter (a DESCRIPTION), giving no accessible NAME. No label/
aria-label. Screen reader announces an unnamed edit field. WCAG 4.1.2 (Level A), 1.3.1.

### P2 a11y-3 — CartDrawer modal does not trap Tab focus (unlike WebModal)
- flows.jsx:57 `<div ref={panelRef} tabIndex={-1} role="dialog" aria-modal="true" aria-label="Panier" ...>`
Focus mgmt is partial: focus moves in on open (flows.jsx:28-30), restores on close (36-37),
Escape closes (32), panel is `inert` when CLOSED (22). BUT there is NO Tab-cycling trap: while OPEN
the background page (nav/footer/<main>) is NOT inert, so Tab escapes the drawer to content hidden
behind the 0.4 overlay. WebModal implements the trap (components.jsx:253-265); this drawer does not.
WCAG 2.4.3 Focus Order / dialog authoring practice (aria-modal=true implies confinement).

### P2 a11y-4 — Color contrast: orange "Modifier" control on light panel < 4.5:1
- wizard-v2.jsx:572 `<button ... style={{ ... color: 'var(--orange)', fontSize: 10.5, fontWeight: 700 ...}}>Modifier</button>`
--orange = #FF5A1F. Computed luminance L=0.287 → contrast 3.12:1 on white, 2.91:1 on cream
(--cream #FAF7F2). Rendered on the light WebModal review panel. 10.5px = SMALL text → requires
4.5:1. FAIL. This is an interactive control. WCAG 1.4.3 (AA). The sibling "Inclus"/"Étape" labels
correctly use --gray-3 (#6F6A60 ~5:1).

### P3 a11y-5 — Product card outer div is mouse-only (mitigated by inner button)
- screens.jsx:36 `<div className="lc-card-item" onClick={onClick}>` — no role/tabIndex/onKeyDown.
NOT keyboard-blocking: the heal at screens.jsx:59-62 moved the real control to an inner
`<button aria-label="Voir {name}" onClick>`; the outer div click is a redundant mouse enhancement.
Keyboard users reach the item via that button + the fav button. Left as P3 (belt-and-braces:
the outer div could also get aria-hidden/pointer redundancy note, but no user is blocked).

### P3 a11y-6 — Three dialogs fall back to generic name "Boîte de dialogue"
WebModal used WITHOUT label/labelledBy → aria-label fallback "Boîte de dialogue" (components.jsx:286):
- screens-v3.jsx:163 ItemDetailModal
- wizard-v2.jsx:454 wizard (composition)
- wizard-v2.jsx:652 wizard (direct-add)
Each contains the product name as an inner heading; passing labelledBy would name the dialog.
Has A name (passes 4.1.2), but non-descriptive → weak WCAG 2.4.6. (account-v2.jsx:120 &
upsell.jsx:72 correctly pass label.)

### P3 a11y-7 — Decorative orange numbers small-text contrast + multi-h1 while modal open
- styles-v2.css:45 `.lc-why-num { font-size:14px; color: var(--orange); }` on light card ~3.1:1 (<4.5).
  Non-essential "01/02/03" numbering (decorative), so P3.
- Heading structure: each ROUTE renders exactly one h1 (screens.jsx:99/393/661/926, funnel.jsx:639),
  but an open AccountFlow/Wizard modal adds its own h1 (account-v2.jsx:145/227/256) on top of the
  page h1 → 2 h1s simultaneously while a modal is open. Minor (section-scoped h1 tolerated). P3.
