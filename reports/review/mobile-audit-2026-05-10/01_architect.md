# 01 — AGENT-ARCHITECT — Kiosk wizard vs mobile ScreenItem (gap analysis)

> Audit YC GStack Mobile, run `mobile-audit-2026-05-10`. Read-only sources:
> `resources/js/components/frontend/kiosk/**` (frozen-zone), `mobile/**`,
> `config/menu.php`. No code modified. All claims cite `file:line`.

Conventions used below:
- `KW.vue` = `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `KApp.vue` = `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `steps/KS<Name>.vue` = `resources/js/components/frontend/kiosk/steps/KioskStep<Name>Component.vue`
- `mobile/screens-main.jsx` = current React/JSX prototype
- `mobile/data/menu.js` = current data layer

---

## 1. Kiosk wizard state machine — ground truth

### 1.1 `activeSteps` — the spine of the wizard
- Computed property `activeSteps()` at **`KW.vue:533–623`**.
- First branch: composer-driven steps from `publishedComposerProfile()` if backend exposes one (`KW.vue:535–536`, helper `KW.vue:758–780`). DB has 0 rows today (`ItemWizardProfile` empty), so this path returns `[]` and the template-switch fires.
- Template switch (`KW.vue:541–622`) keyed off `effectiveWizardTemplate()` (`KW.vue:864–886`). Template lookup priority: composer profile → `item.wizard_template` → `item.category.wizard_template` → name heuristic (`KW.vue:887–926`).
- Each branch emits an array of `{ type, label, component }` objects, then runs `.filter(s => this.shouldShowStep(s.type))` at the end of every branch (e.g. `KW.vue:551`, `561`, `570`, …).
- `shouldShowStep(type)` (`KW.vue:961–1021`) inspects the actual catalog (`partitionKioskExtras`, `kioskSauceVariationRowsForItem`, `kioskViandeCatalogForItem`, `item.has_menu`, `itemAttributes`, `extras[].group_label==='frites_style'`, etc.) so a step only appears if it has real content. This is the kiosk's **runtime truth-table**, not a static config.

### 1.2 `step.type` vocabulary — exhaustive
Verified by `componentForStepType` (`KW.vue:801–815`), `getStepIcon` (`KW.vue:1472–1487`), `STEP_KEY_REGISTRY` (`KW.vue:301–325`), `ADDON_ROLE_TO_TYPE` (`KW.vue:327–332`):

| `step.type`       | Component                       | Frozen file (line where instantiated) |
|-------------------|---------------------------------|---------------------------------------|
| `pain`            | `KioskStepPain`                 | `KW.vue:266–268`                      |
| `taille`          | `KioskStepTaille`               | `KW.vue:269–271`                      |
| `viande`          | `KioskStepViande`               | `KW.vue:272–274`                      |
| `sauce`           | `KioskStepSauce`                | `KW.vue:275–277`                      |
| `garnitures`      | `KioskStepGarnitures` (crudités)| `KW.vue:278–280`                      |
| `supplements`     | `KioskStepSupplements`          | `KW.vue:281–283`                      |
| `frites_style`    | `KioskStepFritesStyle`          | `KW.vue:286–288` (V3.6 owner gate)    |
| `menu`            | `KioskStepMenu`                 | `KW.vue:289–291`                      |
| `generic_choices` | `KioskStepGenericChoices`       | `KW.vue:292–294`                      |
| `recap`           | `KioskOrderSummary`             | `KW.vue:231` (sync import)            |

Step-key aliases (registry `KW.vue:301–325`): `galette/bun→pain`, `meat/proteine→viande`, `sauces→sauce`, `garniture/crudites→garnitures`, `supplement/extras→supplements`, `formule/boisson/drink/frites/side/dessert→menu`, `size→taille`. Addon-role mapping `drink|side|dessert|menu_component → menu` (`KW.vue:327–332`).

### 1.3 Per-template recipe (verbatim from `KW.vue:541–622`)

| Template   | Step ladder (before `shouldShowStep` filter)                                                   |
|------------|------------------------------------------------------------------------------------------------|
| `tacos`    | `[taille?]` → viande → sauce → garnitures → supplements → menu → recap                         |
| `sandwich` | pain → `[viande?]` → sauce → garnitures → supplements → menu → recap                           |
| `burger`   | `[viande?]` → sauce → garnitures → supplements → menu → recap                                  |
| `assiette` | `[viande?]` → sauce → garnitures → supplements → recap (no menu — `has_menu=false`)            |
| `snacking` | sauce → menu → frites\_style → supplements → recap                                             |
| `omelette` | sauce → garnitures → supplements → recap (also covers Ojja + Menus Enfants per `KW.vue:898–911`) |
| `salade`   | garnitures → sauce → menu → frites\_style → supplements → recap                                |
| `simple`   | frites\_style → supplements → recap                                                            |

`shouldAskTacosTaille()` (`KW.vue:1022+`) suppresses the taille step when `item.name` already encodes `M/L/XL/XXL` via `detectTacosSize` helper.

### 1.4 Navigation & gating
- `currentStepIndex` is the only nav state (`KW.vue:384`).
- `nextStep()` (`KW.vue:1458–1462`): `if (currentStepIndex < activeSteps.length - 1) currentStepIndex++`. **No skip semantics** — a step that should not appear is excluded upstream by `shouldShowStep`, never silently skipped at runtime.
- `prevStep()` (`KW.vue:1463–1467`): pure decrement, no validation re-check (free back-nav).
- Footer “next/add” is a single button that branches on `currentStepIndex < activeSteps.length - 1 ? nextStep() : addToCart()` (`KW.vue:177`); disabled by `:disabled="!canAdvance"` (`KW.vue:180`).
- `canAdvance` (`KW.vue:713–755`) is a per-step computed validator:
  - `viande` — if catalog has “included” options, needs `includedViandeSelectionCount() >= detectViandeCount()`; else relies on `selections.totalViandes >= required` (`KW.vue:717–723`).
  - `sauce` — `sauceOrder.length > 0` (skip-button at `steps/KSSauce.vue:17` emits `['_skip']` so the array is non-empty when user opts out).
  - `pain` — `selections.pain !== null`.
  - `taille` — `selections.taille !== null` (`KW.vue:730`).
  - `frites_style` — always true (Nature is the implicit default; `KW.vue:728`).
  - `generic_choices` — `canAdvanceComposerChoiceStep` (min/max from composer step).
  - `menu` — explicit `menuChoice` ∈ {`full`,`frites`,`boisson`,`none`}; if `wantsDrink` and catalog exposes drinks → `boissonChoice` required; if menu includes fries and item has a sauce catalog → `fritesSauceOrder` non-empty (`KW.vue:733–752`).
  - `recap` and any other type → `true`.
- Live composition strip (`KW.vue:97–135` + chips builders `KW.vue:1140–1256`) reads `selections` reactively — every step writes via `updateSelection(key, value, meta)` (`KW.vue:1257+`) and the strip re-renders.
- Dirty-state propagation: each step component emits `('update', key, value, meta?)`; the parent owns the `selections` object (`KW.vue:388–411` data, `KW.vue:1259` setter); no two-way binding, single-source-of-truth.
- Focus management: each step root has `tabindex` + `@keydown.enter|space` (e.g. `steps/KSViande.vue:36,41,42`).

### 1.5 What drives template selection per item
`effectiveWizardTemplate()` priority chain (`KW.vue:864–886`):
1. `publishedComposerProfile().template` (DB-driven; today empty — verified by user).
2. `item.wizard_template` (resource attribute, derived from category — `config/menu.php:49–61` per category).
3. `item.category.wizard_template` (nested resource fallback).
4. `detectTemplateFromName()` (`KW.vue:887–926`) — name/category substring heuristic.

Per-step content additionally depends on item flags: `has_menu`, `viande_count`/heuristic, `extras[].group_label`, `itemAttributes` (sauce/pain attribute presence + variations).

---

## 2. Mobile current architecture — what exists today

| Aspect                 | Location                                              |
|------------------------|-------------------------------------------------------|
| Single-scroll component| `mobile/screens-main.jsx:248–530` (`function ScreenItem`) |
| Local state            | `mobile/screens-main.jsx:261–266` — five `uS` hooks: `meatIds`, `sauceIds`, `cruditeIds`, `supplementIds`, `formuleId`, `qty` (note: actually 6 incl. qty) |
| Validation             | `mobile/screens-main.jsx:288–289` — `meatsOK = item.viandes === 0 \|\| meatIds.length === item.viandes` ; `valid = meatsOK` (sauce/crudités/supplements/menu all optional) |
| Section rendering      | All inline JSX, gated only by item booleans: `item.viandes > 0` (`341`), `item.has_sauce` (`368`), `item.has_crudites` (`393`), `item.has_supplements !== false` (`415`), `item.has_menu_addon` (`441`) |
| Composition snapshot   | Built lazily inside the “Add to cart” onClick (`mobile/screens-main.jsx:480–509`) — `lineItem = {…item, meatIds, meatLabels, sauceIds, sauceLabels, cruditeIds, cruditeLabels, supplementIds, supLabels, formuleId, formuleLabel, composition_summary, sups, qty, unitPrice, lineTotal, price}` |
| CTA                    | Sticky bottom button `mobile/screens-main.jsx:476–527`; label flips from “Choisis N viande(s)” to “Ajouter au panier” depending on `valid`. No prev/next, no progression. |
| Menu addon model       | `mobile/screens-main.jsx:441–462` — flat radio over `[null, …lcMenu.formules]` (3 entries: f-menu, f-frites, f-boisson) defined in `mobile/data/menu.js:90–94`. **No drink picker, no frites style, no frites sauce.** |
| Data SSOT mirroring    | `mobile/data/menu.js:39–94` (MEATS, SAUCES, CRUDITES, SUPPLEMENTS, FORMULES) + `99–113` (CATEGORIES with `wizard_template`+`has_menu`). Categories table mirrors `config/menu.php:49–61` 1:1, but items use kiosk-derived booleans only. |
| Frozen-zone status     | `mobile/**` is the audit target — fully editable. The kiosk reference is read-only. |

---

## 3. Gap table — kiosk vs mobile (sourced)

| # | Aspect | Kiosk (file:line) | Mobile current (file:line) | Gap | Severity |
|---|--------|-------------------|----------------------------|-----|----------|
| 1 | State machine vs single-scroll | `KW.vue:533–623` `activeSteps` + `currentStepIndex` (`384`) + `<component :is="currentStepComponent">` (`144`) | `screens-main.jsx:248–530` flat JSX, no step index | Mobile has zero state machine; user sees all sections at once with no progression | **P0** |
| 2 | Per-template `activeSteps` | 8 templates with explicit ladders (`KW.vue:541–622`) | Sections gated only by 5 booleans (`screens-main.jsx:341,368,393,415,441`) — no template branching | Mobile cannot model `tacos` taille step, `sandwich` pain step, `snacking` order, `salade` order, `frites_style` | **P0** |
| 3 | Prev/next nav | Two buttons `prevStep`/`nextStep` (`KW.vue:171,177`) + dot strip (`78–86`) + arrow chevrons (`70,87`) + `:disabled` per `canAdvance` | None — sticky CTA only (`screens-main.jsx:476`) | No back navigation, no progression UI, no chunked attention | **P0** |
| 4 | Per-step validation gating | `canAdvance` switches on step type (`KW.vue:713–755`); each step disables CTA until valid | Only `meatsOK` (`screens-main.jsx:288`); sauce/crudités/supplements/menu never block CTA | Mobile lets users add a tacos to cart with zero sauce selected (kiosk forces explicit choice via `_skip` pseudo-id) | **P0** |
| 5 | Recap step | Explicit step component `KioskOrderSummary` (mounted via registry `KW.vue:812`) + `recap` flag in nav (`164`) + special instructions textarea (`151–162`) | Implicit — no recap surface; “Add to cart” jumps straight to `go('cart')` (`screens-main.jsx:510`) | Customer never reviews composition before commit; no notes capability | **P0** |
| 6 | Menu cascade (drink + frites style + frites sauce) | `KioskStepMenu` (917 lines) emits `menuChoice` (`steps/KSMenu.vue:571`), `boissonChoice` (`520`), `fritesSauceOrder` (`540`), `fritesSauce` (`541`). `canAdvance` enforces drink + sauce sub-validation (`KW.vue:733–752`) | Single radio over 3 flat formules (`screens-main.jsx:441–462`); `formuleId` only — no drink, no frites style, no frites sauce | Menu formula UX is dramatically simpler on mobile; drops 3 sub-choices | **P0** |
| 7 | Frites style step (cat 10 / extras `group_label='frites_style'`) | `KioskStepFritesStyle` (308 lines), gated by `shouldShowStep('frites_style')` (`KW.vue:1005–1019`) — Nature/Cheddar/Cheddar+Oignons cards | Not present anywhere in mobile | Frites items in cat 10 (and snacking cat 8 / salade cat 7 with menu) miss upgrade path | **P1** |
| 8 | Assiette “style cuisson” Nature/Curry/Paprika | Not a wizard step — text-only in `description` (config/menu.php:328) ; the kiosk has no `KioskStepStyleCuisson*` (`KW.vue:541–622` no such case) | Same — description shows on detail (`screens-main.jsx:332`) | **No gap** — both surfaces treat it as descriptive text. Backend has no attribute today (verified: not in step registry) | **P2** (information only, owner may want to elevate to wizard step later) |
| 9 | Focus management between steps | Step roots use `tabindex` + `@keydown.enter\|space` (`steps/KSViande.vue:36,41,42`); wizard root has `role="dialog" aria-modal="true" tabindex="-1"` (`KW.vue:2–7`) | None — flat `div onClick` cards, no `tabIndex`, no keyboard activation (`screens-main.jsx:352,378,402,425,451`) | No keyboard a11y; a11y P1 finding for any production launch | **P1** |
| 10 | Live composition strip | `compositionSummaryChips` (`KW.vue:657–693`) + chips renderer (`104–134`) reactive to every selection | Absent — user cannot see running summary | Mobile lacks the “bandeau récap” feedback | **P1** |
| 11 | Sauce skip semantics | `KSSauce.vue:17` button emits `['_skip']` so `canAdvance` passes without a forced choice | N/A (no validation) | If we port kiosk validation, mobile will need an explicit “Sans sauce” affordance — kiosk’s `s-sans` already exists in mobile data (`mobile/data/menu.js:68`) | **P1** |
| 12 | Item template signal | `effectiveWizardTemplate()` reads `item.wizard_template` / `item.category.wizard_template` (`KW.vue:864–886`); fallback heuristic (`887–926`) | `mobile/data/menu.js` exposes `wizard_template` + `has_menu` per category but `screens-main.jsx` never reads them | The data layer already carries the field; UI just ignores it. Cheap to wire | **P0** (blocker for refactor; trivial fix) |
| 13 | Generic composer steps | `KioskStepGenericChoices` (236 lines) — backend-driven `choices` array | Not present in mobile | Currently 0 rows in DB; can be deferred (matches owner constraint that ItemWizardProfile/Step are empty) | **P2** |
| 14 | Special-instructions note | `<textarea>` on recap step, `selections.instruction` (`KW.vue:151–162`, max 190 chars) | Absent | Owner may want; carry-on for recap step | **P2** |
| 15 | Abandon confirm modal | `showAbandonConfirm` overlay (`KW.vue:194–224`) protects against accidental close | Mobile uses raw close icon `IconBtn onClick={() => go('back')}` (`screens-main.jsx:303,306`) — no guard | Touch-error risk; same UX guard advisable | **P2** |

---

## 4. Recommended refactor blueprint — multi-page wizard

### 4.1 File layout
- `mobile/screens-item-steps.jsx` — new file containing one functional component per kiosk step kind:
  - `ScreenStepTaille` (mirrors `KSTaille.vue`)
  - `ScreenStepPain` (mirrors `KSPain.vue`)
  - `ScreenStepViande` (mirrors `KSViande.vue`)
  - `ScreenStepSauce` (mirrors `KSSauce.vue` incl. `_skip` semantics)
  - `ScreenStepGarnitures` (crudités, mirrors `KSGarnitures.vue`)
  - `ScreenStepSupplements` (mirrors `KSSupplements.vue`)
  - `ScreenStepFritesStyle` (mirrors `KSFritesStyle.vue`)
  - `ScreenStepMenu` (mirrors `KSMenu.vue` cascade — drink + frites style + frites sauce)
  - `ScreenStepRecap` (mirrors `KioskOrderSummary` + special instructions)
- `mobile/screens-main.jsx` — `ScreenItem` rewritten as the **state-machine driver only** (analog of `KW.vue` `<script>`).

### 4.2 ScreenItem rewrite shape

```
ScreenItem({ go, itemId, addToCart })
  const item = lcMenu.findItem(itemId)
  const [selections, setSelections] = uS(initialSelections(item))   // single object, kiosk-style
  const [stepIndex, setStepIndex] = uS(0)
  const activeSteps = computeActiveSteps(item, selections)          // mirrors KW.vue:533–623
  const step = activeSteps[stepIndex]
  const canAdvance = stepValidator(step, selections, item)          // mirrors KW.vue:713–755
  const update = (key, value, meta) => setSelections(s => ({...s, [key]: value, ...maybeMeta}))
  const next = () => stepIndex < activeSteps.length - 1 && setStepIndex(i => i + 1)
  const prev = () => stepIndex > 0 && setStepIndex(i => i - 1)
  return <ScreenStepWrapper step={step} index={stepIndex} total={activeSteps.length}
                            selections={selections} onUpdate={update}
                            onPrev={prev} onNext={canAdvance ? next : null}
                            onCommit={() => addToCart(buildLineItem(item, selections))} />
```

### 4.3 Per-step layout (uniform shell)
- Full-viewport (no nested page header — replace iOS frame’s “back” with wizard prev).
- Top: dots progression (mirrors `.kiosk-progress-track` `KW.vue:77–86`) + step label.
- Middle: step content (slide transition `transition-group` or framer-motion `mode="wait"`).
- Bottom: sticky CTA — “Suivant” / “Ajouter au panier” on last step, disabled by `canAdvance`. Reuse `lc-btn--ink` style; mirror disabled state from `KW.vue:514–520`.
- Live composition chip row above CTA (mirrors `KW.vue:97–135`).

### 4.4 Composition state pattern (canonical kiosk shape)
- Single `selections` object (analog of `KW.vue:388–411`):
  - `pain`, `taille`, `viandes:{}`, `totalViandes`, `sauces:{}`, `sauceOrder:[]`, `garnitures:{}`, `supplements:{}`, `menuChoice`, `boissonChoice`, `fritesSauceOrder:[]`, `fritesSauce`, `fritesStyleExtraId`, `instruction`.
- Each step receives `{step, item, selections}` as props (mirrors `wizardStepBindings` `KW.vue:700–712`) and emits `(key, value, meta)` to a single `onUpdate` callback (mirrors `updateSelection` `KW.vue:1257+`).
- `_painMeta`, `_tailleMeta`, `_viandeMeta`, `_boissonMeta`, `_fritesSauceMeta` underscored sub-fields preserved (mirrors `KW.vue:391–395`).

### 4.5 `computeActiveSteps(item, selections)` — direct port
Wire `item.category.wizard_template` (already in `mobile/data/menu.js:99–113`) into a switch identical to `KW.vue:541–622`. For each branch, run a `shouldShow(type)` filter that mirrors `KW.vue:961–1021` against the current item booleans (mobile already has `viandes`, `has_sauce`, `has_crudites`, `has_supplements`, `has_menu_addon`; needs additions for `frites_style` extras and `taille`).

### 4.6 Menu cascade — close the gap
`ScreenStepMenu` must emit four selection keys (mirrors `steps/KSMenu.vue:520,540,541,571`):
- `menuChoice` ∈ {`'none'`,`'full'`,`'frites'`,`'boisson'`}
- `boissonChoice` (drink id) when `menuChoice ∈ {full,boisson}` and drinks exist
- `fritesSauceOrder` (string[]), `fritesSauce` when `menuChoice ∈ {full,frites}`

Add a `DRINKS` table to `mobile/data/menu.js` (cat 12 boissons already present in `config/menu.php:586–650`; surface them as drink choices) and a `FRITES_SAUCES` reuse of `SAUCES` minus `s-sans`.

### 4.7 References to mirror per step

| Mobile step          | Kiosk file                          | Lines of ground-truth UX |
|----------------------|-------------------------------------|--------------------------|
| ScreenStepTaille     | `steps/KSTaille.vue`                | 1–269                    |
| ScreenStepPain       | `steps/KSPain.vue`                  | 1–264                    |
| ScreenStepViande     | `steps/KSViande.vue`                | 1–689                    |
| ScreenStepSauce      | `steps/KSSauce.vue`                 | 1–479 (incl. `_skip`)    |
| ScreenStepGarnitures | `steps/KSGarnitures.vue`            | 1–393                    |
| ScreenStepSupplements| `steps/KSSupplements.vue`           | 1–504                    |
| ScreenStepFritesStyle| `steps/KSFritesStyle.vue`           | 1–308                    |
| ScreenStepMenu       | `steps/KSMenu.vue`                  | 1–917                    |
| ScreenStepRecap      | (kiosk uses `KioskOrderSummary`)    | `KW.vue:151–191`         |

---

## 5. Open architectural questions for AGENT-ADVERSARIAL

1. **Recap surface** — Make `ScreenStepRecap` a full step (kiosk parity, `KW.vue:138`+`151–162`), or a confirmation modal launched from the last step CTA? Kiosk uses a step; full-screen mobile may feel heavier.
2. **Menu cascade layout** — Mirror kiosk `KioskStepMenu` (single screen, internal sections for choice + drink + frites style + frites sauce — see `steps/KSMenu.vue:254–571`), or split into 3–4 distinct mobile screens? Kiosk uses one composite screen; mobile viewport is smaller and may benefit from sub-pages.
3. **Validation strictness** — Should mobile hard-block CTA on missing sauce (kiosk forces explicit `_skip`, `steps/KSSauce.vue:17`), or accept implicit silence? Owner UX call: enforces clarity vs increases friction.
4. **Crudités default-ON** — Kiosk treats crudités as defaults the user can remove (`mobile/data/menu.js:73–76` already mirrors). Confirm we keep default-ON in the new ScreenStepGarnitures rather than empty-on-mount.
5. **Heuristic fallback** — Kiosk falls back to name regex when `wizard_template` is missing (`KW.vue:887–926`). Mobile data is static and always carries the field; can we drop the fallback entirely on mobile (simplification opportunity), or do we keep parity for future API-driven items?
6. **Frozen-zone status of mobile** — Confirm with owner that `mobile/**` is **fully editable** for this refactor (no LOCK doc needed). Kiosk and POS Vanilla wizard remain read-only per CLAUDE.md §7.

---

End of `01_architect.md`.
