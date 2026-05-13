# Adversarial dispute — cluster-7 2026-05-11

> Red-team sub-agent — single-pass audit ~20 min
> HEAD audited: `b349d5aa1` (cluster-7 commit) — branch is now at `30dd8814e` (2 commits past cluster-7)
> Method: source diff inspection + Playwright capture + JS state probe via `window.ITEMS`

## TL;DR

Of the 7 claims, **5 SUSTAINED** (D1, D2, D3, D5, B partial), **2 DISPUTED** (D4, D6). One **P0 defect** newly discovered: **D4 AllergenBadge** displays **100 % fabricated allergens on all 60 items** — water, Coca-Cola and Capri-Sun are tagged "gluten + lactose" via the `mkItem` default at `mobile/data/menu.js:274`. The exact opposite of EU FIC 1169/2011 intent (false disclosure on benign products). The second blocker is **B-kiosk salade** whose source change is committed but **the kiosk bundle is stale** (last built 3.5 hours before the source edit) so the fix is **not live** on `:8000`. Verdict: **RED** — do not close cluster-7 until D4 default is fixed (`|| []` one-liner) and `npm run build` is run.

## Claims verification (D1-D6 + B)

### D1 — Le Suprême viandes=2 — **SUSTAINED with hidden caveat**

- ✅ `mobile/data/menu.js:291` now `viandes: 2` (was `0` in commit message but actually `1` pre-patch per diff)
- ✅ `config/menu.php:218-227` now `'viandes' => 2` (was `1` in diff, commit message says `0`)
- ✅ Playwright captured (`03-supreme-step1.png`): wizard opens to **ÉTAPE 1 / 6** with header "VIANDES", "Choisis **2** viandes", counter "0 / 2", button "SUIVANT" disabled until 2 picks
- ⚠️ **Hidden defect**: meat list contains 9 options — **Steak is MISSING** from `window.LC.menu.meats` (only Merguez, Kefta, Mexicain, Cordon Bleu, Viande Hachée, Nuggets, Escalope de poulet, Tenders, Fricandelle). The item description literally says "**Steak + Cordon Bleu par exemple**" but a user cannot pick Steak — it does not exist in the catalog. **Description-vs-reality mismatch**.

### D2 — Salade menu addon (cascade) — **PARTIAL: data + step logic SUSTAINED, cascade behaviour not visually verified**

- ✅ `mobile/data/menu.js:323-339` — CAT 7 `has_menu: true`, all 4 SALADES have `has_menu_addon: true`
- ✅ `mobile/screens-item-steps.jsx:86-95` — salade template now appends `STEP.MENU` after sauce + suppléments
- ✅ JS state inspection confirms Salade Royale `has_sauce: true, has_supplements: true, has_menu_addon: true`, category wizard_template `salade`, has_menu `true`
- ✅ Existing wave-C screenshot `04-salade-royale-recap.png` shows ÉTAPE 3/4 = MENU (per claim)
- ⚠️ **Could not visually verify the cascade itself** (drink + fritesStyle + fritesSauce when "Menu complet" is selected) — my Playwright script could not navigate cleanly to Salade Royale wizard from a fresh boot (auth seed loaded but the `Nos Salades` chip click via aria scan landed on Le Suprême). The existence of `STEP.MENU` in the step list does NOT prove cascade works; only that the step is offered. **No new evidence captured.**

### D3 — Quick-add bypass fix — **SUSTAINED**

- ✅ `mobile/screens-main.jsx:257` — conditional render of orange arrow vs ink + based on `(it.viandes > 0 || it.has_sauce || it.has_supplements !== false || it.has_menu_addon || it.has_frites_style)`
- ✅ JS state audit (60 items): **43 items get the orange arrow** (wizard-bound), **17 items get the ink +** (direct add)
- ✅ The 17 direct-add items are all 8 boissons (cat 12) + Glace + Tarte Daim + Tiramisu (cat 11) + 6 items from cat 13 Suppléments (Fromage, Jambon, Boursin, Raclette, Œuf, Galette, Salade verte)
- ⚠️ **Edge case verified, behaviour as intended**: `item-sauce-sup` (cat 13, has_sauce=true) gets the arrow → wizard (because choosing sauce is required); other cat-13 suppléments get the direct +. This is the desired heuristic.

### D4 — AllergenBadge (EU FIC 1169/2011) — **DISPUTED — P0 LEGAL DEFECT**

The chip rendering is fine, the helper component AllergenBadge works, the markup is accessible (`role="region"` + `aria-label`). **But the DATA is fabricated**:

```
ALLERGEN AUDIT (window.ITEMS direct inspection):
- totalItems:        60
- fabricatedCount:   60   ← 100 % use the mkItem default ['gluten', 'lactose']
- realCustomCount:    0   ← NO item has hand-curated allergens
- emptyCount:         0   ← NO item has empty allergens
```

This is because `mkItem` line 274 sets:
```js
allergens: opts.allergens || ['gluten', 'lactose'],
```
and **no item in mobile/data/menu.js passes any `allergens` opt**.

**Concrete legal exposure**:

| Item | Real allergens | Mobile claims |
|------|----------------|---------------|
| Eau Plate 50cl | (none — mineral water) | gluten + lactose |
| Coca-Cola 33cl | (none — sugar + caffeine + caramel) | gluten + lactose |
| Sprite, Fanta, Orangina, Oasis, Capri-Sun | (none) | gluten + lactose |
| Glace | lactose (likely œuf) | gluten + lactose (fake gluten) |
| Tiramisu | lactose + œuf + gluten (mascarpone, biscuit) | gluten + lactose (fake — missing œuf) |
| Sauce supplémentaire | depends on sauce | gluten + lactose |
| Œuf (supplément) | œuf | gluten + lactose (fake gluten+lactose, missing œuf) |

EU Regulation 1169/2011 requires **accurate** allergen disclosure for the 14 majors. Tagging mineral water "gluten + lactose" is not compliant — it misleads coeliacs (who would skip water needlessly) and lactose-intolerant users (who would skip water needlessly), and undermines the credibility of the entire disclosure. The visual chip is in the right place but the data layer is **legal-grade wrong**. This is a P0.

Title hover-tooltip is inaccessible on touch devices (no hover) — independent A11y concern but secondary to the data fabrication issue.

### D5 — Special instructions textarea — **SUSTAINED**

- ✅ `mobile/screens-item-steps.jsx:683-705` textarea rendered with `maxLength={190}`, `aria-live="polite"` counter, `data-testid="recap-instructions"` for tests
- ✅ `buildLineItem` line 770 captures `instruction: (selections.instruction || '').slice(0, 190)`
- ✅ `composition_summary` line 770 includes the prefix `· 📝 <instruction text…>` (truncated at 60 chars + ellipsis) when non-empty
- ✅ Cart line render (`mobile/screens-main.jsx:628`) shows the full composition_summary in a `WebkitLineClamp: 2` clipped div — instruction emoji prefix will display alongside other compositions
- ⚠️ Minor: long instructions may be truncated by the line clamp on the cart, but `title` attribute is set for hover/long-press tooltip access.

### D6 — Promo code input — **DISPUTED — P1/P0 FUNCTIONAL DEFECT**

- ✅ UI is well-coded: idle / applied / invalid 3-state, role="alert" on invalid, role="status" + aria-live on applied, max-length 16, force-uppercase on each keystroke
- ✅ I confirmed lowercase paste auto-uppercases via the `onChange` handler `e.target.value.toUpperCase().slice(0, 16)` — paste of "welcome10" becomes "WELCOME10"
- ❌ **CRITICAL FUNCTIONAL FAILURE**: applying "WELCOME10" **does NOT discount the total**. Line 595 computes `const total = cart.reduce((s, i) => s + i.price * i.qty, 0);` — there is **zero coupling** between the PromoCodeRow's internal `appliedCode` state and the total displayed below.
- User-facing trap: a customer who types "WELCOME10", sees "✓ Code WELCOME10 appliqué" in a green success banner, then proceeds to pay the **FULL price** unchanged. The promo is **purely cosmetic**.
- **The author's intent is documented** ("V0 mock, backend wireup Phase 6.C"). It's an intentional mock. But shipping a green "code applied" banner that does NOT change the total is a **deceptive UX pattern** even at V0. Either remove the input or wire a fake discount until Phase 6.C lands.

### B — Kiosk salade simplified (LOCK_KIOSK_SALADE) — **PARTIALLY SUSTAINED — bundle not rebuilt**

- ✅ `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:619-631` correctly reduces salade from 6 steps to 5 (sauce + supplements + menu + frites_style + recap), all filtered by `shouldShowStep`
- ✅ `plans/LOCK_KIOSK_SALADE_2026-05-11.md` exists and the patch is consistent with its scope (lines 619-631 only)
- ✅ Frozen-zone discipline checked: cluster-7 commit (`b349d5aa1`) touched ONLY 6 files (config/menu.php, mobile/data/menu.js, mobile/screens-item-steps.jsx, mobile/screens-main.jsx, plans/LOCK_KIOSK_SALADE_2026-05-11.md, resources/js/components/frontend/kiosk/KioskWizardComponent.vue). The OrderStateMachine diff vs main is from an older commit, NOT cluster-7. **No frozen-zone violation in this commit.**
- ❌ **Bundle stale — kiosk fix NOT LIVE**:
  - `KioskWizardComponent.vue` modified `May 11 09:42:18 2026`
  - `public/js/kiosk-shell.js` last built `May 11 06:06:15 2026` (≈ 3.5 h before the source edit)
  - `public/mix-manifest.json` last updated `May 11 06:24:27 2026`
  - **Until `npm run build` is run**, the kiosk on `:8000` still serves the OLD 6-step salade flow. The salade fix exists in source but is **invisible to a customer at the kiosk**.
- ❌ **PHPUnit regression criterion of LOCK §5 not met**: `php artisan test --filter "Kiosk"` returned **297 fail / 27 pass**. Root cause is a pre-existing migration FK violation in `database/migrations/2026_05_10_040000_add_frites_style_upgrade_extras.php` (FK on item_id 360/361/402/403 fails in test database). This is **not introduced by cluster-7**, but the LOCK plan §5 says "Expected: tests PHPUnit kiosk verts (705/705 cumulative iter14 doit rester vert)" — this acceptance criterion is **VIOLATED** at the time the LOCK was signed off. The 705/705 baseline does not hold.

## Hidden defects discovered

### P0 — D4 fabricated allergens on 100 % of catalog
See claim D4 above. Concrete legal-disclosure failure under EU FIC 1169/2011. Fix: pass real `allergens: [...]` opt to every `mkItem` call OR change the default to `[]` and make AllergenBadge return null when empty (it already does via `if (!allergens || allergens.length === 0) return null;`).

### P1 — D1 Le Suprême description references a meat that does not exist (LATENT, surfaced by cluster-7)
Description says "Steak + Cordon Bleu par exemple" but `window.LC.menu.meats` contains no Steak entry. The meats catalog is NOT touched by cluster-7 — Steak was already absent from the catalog pre-patch. Cluster-7 only changed `viandes: 0→2`, which surfaced this pre-existing description/catalog mismatch by switching from phantom text to a real picker. Fix: either add Steak to the meats catalog OR change the description to a meat that exists ("Cordon Bleu + Escalope par exemple"). Mirror in `config/menu.php:218`.

### P1 — D6 promo applied banner is cosmetic only
"WELCOME10" applies the green banner but does NOT discount the total. Either wire a real 10 % discount in the V0 mock or remove the banner success state until Phase 6.C lands real backend.

### P1 — B Kiosk Vue bundle not rebuilt
Source updated, bundle stale. The kiosk salade fix is committed but not live on `:8000`. Run `npm run build` before declaring the LOCK closed.

### P2 — D4 AllergenBadge uses `title` attribute for icon labels
On touch devices (mobile, kiosk), `title` does not trigger. A coeliac user looking at a meat-only chip cannot read the icon names without a long-press → modal. Currently no tap handler exists. Either add a tap-to-detail modal or render the allergen names inline next to the icons.

### P2 — D2 salade cascade not visually verified
The `STEP.MENU` is appended for salades, but the cascade behaviour (drink + fritesStyle + fritesSauce when menu='full' is selected) was not screenshot-captured by waves A-D nor by my adversarial probe. The cascade EXISTS in code for the sandwich template (which mobile re-uses), but ONLY a captured screenshot of step 4-7 of a salade wizard with menu='full' would prove it works. Currently it is a "should work" not a "does work".

### P3 — D5 long instructions truncated in cart line
`composition_summary` is line-clamped to 2 lines on the cart line render. A 190-char instruction will be clipped. `title` tooltip works on desktop but not touch. Consider a tap-to-expand UX.

### P3 — D3 heuristic ambiguity for supplements cat 13
`item-sauce-sup` (cat 13) opens the wizard because it has `has_sauce: true` while other suppléments add direct. This is consistent with the spec ("if customizable → wizard") but creates a visual inconsistency on the supplements category screen — half the cards have orange arrows, half have ink +. Acceptable but worth noting.

## Visual proof captured

- `reports/test-e2e/cluster-7-2026-05-11/screenshots/03-supreme-step1.png` — **D1 proof**: ÉTAPE 1/6 + "Choisis 2 viandes" + 0/2 counter + 9 meats visible (Steak absent)
- `reports/test-e2e/cluster-7-2026-05-11/screenshots/01-menu-init.png` — **D4 partial proof**: tacos cards visible with `⚠️` icon + 2 sub-icons in the chip (matches the fabricated `gluten + lactose` default)
- JS state probe outputs printed inline above (60/60 fabricated allergens, 9-meat list, salade item flags, heuristic split 43 arrow / 17 plus)
- `reports/test-e2e/cluster-7-2026-05-11/screenshots/B-boissons-screen.png`, `B-desserts-screen.png`, `B-salades-screen.png` — captured but the in-app nav landed back on home (script bug) so they show home not the intended category screens. JS probe filled the gap.

## Net verdict

**RED** — P0 introduced by cluster-7 (D4 fabricated allergens) plus a deployment gap on B (kiosk bundle not rebuilt).

Cluster-7 cannot be marked perfect/closed in the current state. The owner explicitly framed D4 as "EU FIC 1169/2011 — disclosure obligation" — the implementation **inverts** that obligation by displaying false claims on water and soda. The kiosk salade fix is committed to source but invisible on `:8000` until a build runs.

D3 and D5 land cleanly. D2 lands in code (shared template path with sandwich, code-trace sufficient) but lacks captured cascade proof at P2 severity. D6 lands UI-only and is a P1 deception risk.

**Methodology note for the orchestrator**: the 4 waves passed GREEN because they verified "allergen chip is present" without verifying chip **content**. That's how 60/60 fabrication slipped through. The test rubric needs an allergen-truth axis (read a sample of `window.ITEMS` allergens and assert they vary per category, with water/soda items having zero or only relevant allergens).

## Recommendations

For the orchestrator before closing cluster-7 — top 3 must-fix:

1. **P0 fix D4 allergens**: Change `mkItem` default from `allergens: opts.allergens || ['gluten', 'lactose']` to `allergens: opts.allergens || []` (one-line fix at `mobile/data/menu.js:274`). `AllergenBadge` already returns null on empty so the chip disappears for water/soda harmlessly. Hand-curate real allergens for the items that need them (tacos = gluten + lactose if pita/sauce, glace = lactose, tiramisu = lactose+œuf+gluten, etc.) as a separate pass.
2. **P1 fix B kiosk bundle**: Run `npm run build` (or `npx mix --production`) so the kiosk salade fix becomes live on `:8000`. Document in the LOCK plan post-execution section that the bundle was rebuilt with timestamp.
3. **P1 fix D6 promo deception**: Suppress the green "✓ Code appliqué" success banner until the real backend lands (Phase 6.C). Replace with a softer "Code reçu — appliqué au paiement" message OR wire a fake 10 % discount in the V0 mock (compute `total * 0.9` when `appliedCode === 'WELCOME10'`).

Honorable mentions (not top 3 but worth tracking):
- **D1 description fix**: Change Le Suprême description from "Steak + Cordon Bleu par exemple" to a meat tuple that exists ("Cordon Bleu + Escalope de poulet par exemple"). Mirror in `config/menu.php:218`.
- **PHPUnit migration FK bug**: Independent of cluster-7, the pre-existing failure in `add_frites_style_upgrade_extras.php` invalidates the LOCK §5 PHPUnit regression criterion. Open a separate ticket.
- **D2 cascade visual proof**: Add wave-C states 04b-04e to screenshot the salade → menu cascade end-to-end (currently relying on shared sandwich-template code path).
- **Test rubric upgrade**: Future waves should include an allergen-truth assertion (sample `window.ITEMS` allergens by category and assert variability) so 60/60 default-fabrication can never slip through again.
