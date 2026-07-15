# a11y dimension — VERIFIER reproduction (2026-07-09)
Target source: /Users/1millnonstop/Downloads/web

## 1 a11y-unlabelled-promo-inputs — CONFIRMED P2
- flows.jsx:110 `<input value={promo} ... placeholder="Ex: CAYENNE10"/>` (heading `<h5>// Code promo</h5>` line 108, not associated)
- funnel.jsx:286-290 `<input value={ctx.promoInput||''} ... placeholder="Ex: CAYENNE10"/>` (heading `<h3>// Code promo</h3>` line 284)
- Negative proof: `grep htmlFor|<label flows.jsx` = 0 hits in flows.jsx; funnel.jsx labels (497-582) are for card/auth fields only, NOT promo. funnel promo input has NO aria-label (funnel.jsx:242 aria-label belongs to the ADDRESS input).
- WCAG 4.1.2 Level A. Placeholder != accessible name.

## 2 a11y-unlabelled-note-textareas — CONFIRMED P2
- flows.jsx:119 `<textarea className="lc-notes" ... aria-describedby="cart-notes-counter"/>` — describedby only, no name.
- funnel.jsx:300-306 `<textarea className="lc-notes" ... aria-describedby="notes-counter"/>` — describedby only, no name.
- Neither has aria-label / associated <label>. WCAG 4.1.2 Level A.

## 3 a11y-cartdrawer-no-focus-trap — CONFIRMED P2
- flows.jsx:57 `<div role="dialog" aria-modal="true" aria-label="Panier" ...>`; inert set ONLY when closed (line 22 `node.inert=!open`); keydown handler (line 32) handles Escape ONLY — no Tab/Shift-Tab cycling; background not inert while open (overlay line 56).
- Contrast: WebModal DOES trap Tab (components.jsx:253-265) — CartDrawer does not reuse it. Confirmed no `e.key==='Tab'` in flows.jsx.

## 4 a11y-contrast-wizard-modifier — CONFIRMED P2
- wizard-v2.jsx:572 `<button ... color:'var(--orange)', fontSize:10.5, fontWeight:700 ...>Modifier</button>`
- --orange=#FF5A1F (styles.css:8). L(#FF5A1F)=0.2867. Contrast vs white=3.12:1, vs --cream #FAF7F2 (styles.css:16)=2.91:1. 10.5px bold = small text → needs 4.5:1. WCAG 1.4.3 AA FAIL.
- --orange-text #C2410C (styles.css:10, 4.86:1) already exists and is used by siblings elsewhere.

## 5 a11y-dialogs-generic-name — CONFIRMED P3
- WebModal fallback aria-label 'Boîte de dialogue' (components.jsx:286).
- No label/labelledBy: screens-v3.jsx:163, wizard-v2.jsx:454, wizard-v2.jsx:652.
- Correctly labeled: account-v2.jsx:120 (Mon compte), upsell.jsx:72 (Compléter ta commande). Exactly 3 fall through.

## 6 a11y-card-div-mouse-only — CONFIRMED P3
- screens.jsx:36 `<div className="lc-card-item" onClick={onClick}>` no role/tabIndex/onKeyDown.
- Mitigated: inner real <button> name control (screens.jsx:62, aria-label "Voir <name>") + fav <button> (screens.jsx:54). Keyboard reaches action → no user blocked. P3 correct.

## 7 a11y-decorative-orange-and-multi-h1 — CONFIRMED P3
- styles-v2.css:43-45 `.lc-why-num { font-size:14px; color:var(--orange); }` renders numbers `{w.n}` at screens.jsx:186 → ~3.1:1 at 14px small text (decorative).
- Multi-h1: page h1 (screens.jsx:99) still mounted while account modal h1 (account-v2.jsx:145/227/256) is open over Home → two h1 simultaneously. Both factual.
