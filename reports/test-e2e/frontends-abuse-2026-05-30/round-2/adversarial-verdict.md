# ADVERSARIAL VERDICT — Frontends Abuse-E2E Round 2 (skeptic pass)

Date: 2026-05-30
Role: ADVERSARIAL SUPERVISOR (skeptic) — READ-ONLY, no source edited.
Scope: dispute the 2 heals just applied (7 image files + M-001 menu-addon price) and
hunt for MISSED P0/P1 in the captured artifacts (mobile + web).
Method: visual-first (Read PNGs + Read the 7 image files directly) then technical
(grep/Read source). Every finding = file:line OR PNG filename + observed value.

---

## VERDICT HEADLINE

**Both heals CONFIRMED correct AND complete. ZERO new P0/P1 found.**
The standalone checkout-stop is an intentional clean terminus (not a crash).
All cart/recap totals are numerically coherent. Mobile palette compliant.
One honest nuance documented (heal widened the wizard-vs-catalog image gap) —
still the already-disclosed P2 M-002, NOT a regression, NOT a new P1.

---

## 1. HEAL-BY-HEAL DISPUTE

### HEAL 1 — 7 standalone image files overwritten with le-cayenne-v2 photos

**Subject correctness (Read each healed file directly):**
| File | Rendered subject | Expected | Verdict |
|------|------------------|----------|---------|
| supplement_raclette.png | pale-yellow cheese slices w/ rind | raclette cheese | ✓ correct |
| supplement_fromage.png | shredded/grated cheese pile | cheese (Emmental) | ✓ correct (generic shredded cheese, acceptable) |
| supplement_boursin.png | white herbed soft cheese round | boursin (NOT mayo) | ✓ correct |
| supplement_cheddar.png | stacked orange cheese squares | cheddar (NOT cheesecake) | ✓ correct |
| frites.png | golden fries in BLACK "LE CAYENNE" branded box | branded fry box | ✓ correct |
| supplement_oeuf.png | fried egg, yellow yolk | egg | ✓ correct |
| supplement_jambon_dinde.png | pale folded turkey-ham slices | turkey ham (NOT pink pork) | ✓ correct |

No wrong subject anywhere. No accidental swap.

**Two-tree byte-identity (md5):** mobile/assets/menu vs /Users/1millnonstop/Downloads/web/assets/menu —
all 7 IDENTICAL:
- supplement_raclette.png 0f99df01fecc71d9b90a245fe5384e13
- supplement_fromage.png 6a6bab43901a03de51d63c6888169078
- supplement_boursin.png a2e0412d9911dbe2ee9a9ef357015003
- supplement_cheddar.png a42639fd175583160b37d147896493b0
- frites.png 3d425a3f3335c57fd52a042b54acc4aa
- supplement_oeuf.png 7632e934db885dfc929b4b12952f8bcc
- supplement_jambon_dinde.png 97096f874d472201366ed0530239e974

**Collateral / unintended image change (grep menu.js for each filename):**
The healed files are referenced by the WIZARD-step SUPPLEMENTS/FRITES_STYLES arrays
(mobile menu.js:164-169,191-192,198; web menu.js:126-131,150-151,156). No OTHER product
references these filenames unexpectedly. `frites.png` is intentionally shared by
`Nature` frites style + `bb-frites` (both render the branded box — coherent).
`supplement_fromage.png` is used by `sup-emmental` (Emmental→shredded cheese, acceptable);
`supplement_jambon_dinde.png` is used by `sup-jambon` (Jambon→turkey ham, acceptable per
turkey-ham product). No defect.

**In-context render proof:** wizard supplement/drink/menu steps render the real photos
(06-wiz-sandwich-step3/step4, 11-wiz-menu-cascade-*). Captures dated 04:39-04:40 POSTDATE
the 04:30 image heal — captures are post-heal.

**HEAL 1 VERDICT: CORRECT + COMPLETE for its stated scope (7 wizard-step photo files).**

**HONEST NUANCE (document, not a defect to re-litigate):** the heal touched the
WIZARD-step image arrays only. The standalone SUPPLÉMENT *catalog* (ITEMS) uses a
SEPARATE slug→image map (mobile menu.js ITEM_IMG:95-104) that still points stale
`generated_*` blobs for supp-cheddar/raclette/emmental/oeuf/jambon. Catalog screenshot
05-cat-08-suppl-ments.png confirms Cheddar/Raclette/Emmental render placeholder blobs,
while the wizard step shows real photos. The heal thus WIDENED the wizard-vs-catalog gap,
and within the catalog only `supp-boursin` (which maps to the healed file, ITEM_IMG:99)
now renders real while its neighbors stay stale. This is the SAME issue already disclosed
as **P2 M-002** (catalog placeholder divergence). Per task CONTEXT it is owner-classified
P2 "do not re-litigate." NOT a new P1, NOT a regression (the catalog was already on stale
blobs before the heal). Disclosed plainly here for honesty.

### HEAL 2 — M-001 menu-addon price 3.00 → 2.50

**Label consistency across surfaces:**
- Menu step card: mobile screens-item-steps.jsx:525 `price: 2.50` → rendered "+2,50€"
  (06-wiz-sandwich-step3.png shows "Menu complet ... +2,50€"). ✓
- Recap FORMULE row: screens-item-steps.jsx:779 `'Menu (Frites + Boisson) +2,50€'`
  (11-wiz-menu-cascade-step8.png recap shows "FORMULE: Menu (Frites + Boisson) +2,50€"). ✓
- Data layer: mobile data/menu.js:184 FORMULES `f-menu price: 2.50` (heal comment :182). ✓

**Computed-total path (the strong check — code, not just labels):**
`computeTotal` (screens-item-steps.jsx:190) delegates to `lcMenu.priceFor()` with
`formuleId: 'f-menu'`. `priceFor` (menu.js:550-552) adds `FORMULES.find('f-menu').price`
= **2.50**. So the displayed TOTAL uses 2.50, not a hardcoded label. Proven end-to-end:
11-wiz-menu-cascade-step8.png CTA total = **9,40€** = Chicken Burger base 6,90 + 2,50 menu
(Coca + Nature frites included in formule at 0). If the heal had been incomplete (menu.js
still 3.00) the total would have been 9,90 while the label said +2,50 = divergence. It is
NOT. Label, recap, AND total all coherent at 2.50.

**No remaining hardcoded "+3":** grep across mobile/ found `+3€`/`3.00` only in COMMENTS and
docs (screens-main.jsx:296 comment, CONNECTION_PLAN.md:46, MOBILE_APP_BRIEF*.md) — none
customer-visible. data/orders.js:57 `total: 13.00` is an unrelated mock-order total.

**WEB side:** wizard-v2.jsx:93 `Menu complet ... price: 2.50` (already correct).
Visually closed: web-desktop-wizard-sandwich-recap.png shows "ÉTAPE 4 — FAIRE UN MENU?
Menu complet **+2,50€**" with TOTAL **10,00€** = Sandwich Cayenne 7,50 + 2,50. ✓

**HEAL 2 VERDICT: CORRECT + COMPLETE on BOTH surfaces.**

---

## 2. NEW P0 / P1 FOUND

**NONE.**

Checkout-stop (P0-class hunt target) verified clean on all 3 web viewports + mobile:
- web-desktop-payment-stop.png: intentional "COMMENT TU PAIES?" payment-mode step,
  RÉCAP Sandwich Cayenne 7,50 = Total 7,50€. No crash/stack/blank.
- 22-modal-pay-choice.png (mobile): clean "COMMENT TU PAIES?" sheet (Payer à la caisse /
  Payer maintenant), Total 12,40€. No crash.
- 23-confirm-counter-payment.png (mobile): mock "C'EST PARTI! Commande #O-5318 envoyée"
  success screen + loyalty modal. orderId is CLIENT-SIDE generated (screens-main.jsx:733);
  the only fetch() calls are design-canvas screenshot tooling + a COMMENTED Phase-6 promo
  wireup (screens-main.jsx:1338) — NO live backend POST. This is an intentional standalone
  demo terminus, NOT a crash. Satisfies the standalone-stop criterion.

Numeric integrity (displayed total == sum of lines) — spot-checked, all PASS:
- 14-cart-full-multi-line.png (mobile): 7,50 + 3,00(Coca×2) + 8,90 = TOTAL 19,40€ ✓
- web-desktop-cart-full.png: 7,50 + 1,50 = Sous-total/Total 9,00€ ✓
- 11-wiz-menu-cascade-step8.png: 6,90 + 2,50 = 9,40€ ✓
- web-desktop-wizard-sandwich-recap.png: 7,50 + 2,50 = 10,00€ ✓

Palette: mobile cart/wizard/checkout = black/orange/yellow/white, no Cayenne red in chrome ✓.
Web uses red/orange chrome ("LE CAYENNE" logo, headings) — ALLOWED (web has its own
standalone charter per mandate #4; red constraint is mobile-only).

No raw-label leaks, no broken/placeholder images on PRODUCT cards beyond the disclosed
catalog-blob P2, no layout overflow/overlap in the sampled states.

---

## 3. CONFIRMED GENUINELY CLEAN

- 7/7 healed images: correct subject, byte-identical both trees, no collateral breakage.
- M-001: label + recap + computed total all coherent at 2.50 on mobile AND web.
- Checkout-stop: intentional clean terminus on all surfaces (no crash).
- Cart/recap numeric integrity: every sampled total == sum of lines.
- Mobile palette compliant; web red is charter-allowed.

## CARRY-FORWARD (pre-disclosed, owner-classified — NOT blockers)
- P2 M-002: supplement CATALOG cards still render stale `generated_*` blobs (mobile
  menu.js ITEM_IMG:95-104) while wizard renders real photos; heal widened this gap.
  Fix path if owner wants: repoint ITEM_IMG supp-* entries to the healed filenames.
- P2 M-001(stepper-clip), M-003(double-tap), M-004(catalog-placeholder), empty-cart ETA — known.
- F-PRICE-01 (standalone-vs-DB price divergence) = ESCALATED owner decision, not a defect.

**FINAL: heals CONFIRMED correct + complete. NO new P0/P1. Convergence GREEN from the
skeptic's chair (P0+P1 = 0).**
