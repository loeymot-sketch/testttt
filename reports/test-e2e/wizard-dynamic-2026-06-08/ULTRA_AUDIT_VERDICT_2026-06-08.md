# Ultra-audit + review + test-e2e deep — Composer/Wizard category-inheritance

**Date:** 2026-06-08 · **Branch:** `heal/pre-cloud-exec-2026-06-05` @ `cb8125869` (+ 1 commit after)
**Scope:** the W7 composer category-inheritance work (render fixes + GAP-A builder + GAP-E source picker)
**Method:** manual deep trace (advisor-guided) + 6-dimension adversarial Workflow (9 agents, 211 tool-uses) with per-finding adversarial verification + live e2e on `:8767`/`foodking_e2e`.

---

## VERDICT: the category-wizard CAN render correctly, but is NOT turnkey — the template ships a broken wizard out-of-box; correct output requires expert manual authoring today.

No fiscal P0. No security leak. The render path is proven correct **for a manually-corrected
profile** (B1). **But:** `ComposerTemplateService::stepsFor` hardcodes `source_ref => ''` (line 46),
so applying the "tacos" template through the builder **always** produces the broken empty-`source_ref`
wizard from §1. GAP-E populates the picker; it does **not** auto-fill `source_ref`. So today the owner
must, per `item_attribute` step, manually pick the attribute in the source picker **and** delete
template steps that don't match the catalogue (Le Cayenne tacos have no "taille"/size attribute). **That
manual-authoring friction IS a large part of "ça fonctionne pas"** — see §5 for the turnkey lever.

**What is proven vs not (honest boundary):**
- ✅ Render of an inherited category wizard with `source_ref` SET → distinct correct choices (B1, live `:8767`).
- ✅ Persistence chain UI→payload→DB→projection, code-verified end-to-end: builder `payloadForStep`
  (`ProductComposerEditorComponent.vue:794` emits `source_ref`) → bulk PUT `admin/composer/profiles/{id}`
  → `ComposerProfileService::update:133-137` → `ComposerStepService::create` → `normalize:47` persists
  `source_ref`. Picker populated via GAP-E (admin screenshot). Contract test asserts the picker loads sources.
- ⚠️ NOT exercised by a live click: the actual Vue interaction of selecting an attribute in the picker
  and saving (verified by code-read + contract test, not a browser click-through).
- ⚠️ B1 reflects a profile I hand-corrected via `ComposerStepService::update` (set viande/sauce
  `source_ref`, deactivated the bogus taille step) — **NOT raw template output**.

---

## 1. The real "ça fonctionne pas" — root-caused

A published **category** wizard rendered the item's **entire undifferentiated variation list in every
`item_attribute` step** (e.g. "Choisis la taille" showed meats; taille/viande/sauce all showed the
same 14 items). Two compounding causes:

1. **`ComposerProfileProjection::matchesAttributeRef('')` returns `true`** (line 178-180) → an
   `item_attribute` step with **empty `source_ref`** matches ALL the item's variations.
2. **The category builder had no source picker** until GAP-E (this session). Before GAP-E
   (`7b524328d`), `availableSourcesForCategory` did not exist → the picker showed "Aucune source
   disponible" → authors **could not narrow** category `item_attribute` steps → `source_ref` stayed
   empty → every such step collapsed to the full list. Profile #16 (the only category profile, in the
   e2e clone, authored pre-GAP-E) is the evidence.

**GAP-E was therefore the load-bearing fix, not a minor gap-fill.** Proven through the real save path
(`ComposerStepController::update` → `$request->validated()` → `ComposerStepService::update` →
`normalize`, persists `source_ref`): with `source_ref` set, the projection yields **distinct correct
choices** — "Choisis tes viandes" → 4 meats, "Choisis ta sauce" → 10 sauces (was 14-mixed for both).
Owner-facing proof: `e2e-correct/B1-wizard-step1-viande.png` (clean VIANDE→SAUCE→MENU→Récap, 4 meats).

Also confirmed: the frozen kiosk wizard **skips zero-choice steps** (`composerStepType` → null →
filtered; `shouldShowComposerStep` requires `choices>0`) — so empty `extra_group` steps (garnitures /
suppléments, inert for current Tacos) produce **no dead page**. Item 27 (Big Tacos) behaves identically.

> **Production context (recalibration):** the operating `foodking` DB has **0 published category
> profiles** and **10 item-owned** profiles (all `source_ref` SET, seeded by `MenuHealLight*` console
> commands). Nothing is broken in production today. The render fix is **inert until a category wizard
> is published**. Every finding below is therefore "must-fix before the category-wizard feature is
> relied upon" rather than "broken now."

---

## 2. Healed this session (committed, non-frozen, tested)

| # | Fix | Commit | Evidence |
|---|---|---|---|
| H1 | Category-profile **inheritance at render** (4 resolvers) | `0ad1906ff` | 8/8 MenuProjectionComposerProfileTest; B1 screenshot |
| H2 | GAP-A builder: expose `allow_repeat` + `addon_role`; fixed empty min/max summary | `217b880cb` | 5/5 Vitest; audit GAP-A round-trip "CORRECT end-to-end" |
| H3 | GAP-E: category **source picker** endpoint (the load-bearing fix above) | `7b524328d` | picker populated `Viande 1#1, Sauce#5` (admin screenshot) |
| H4 | Publish-modal warning **told the truth** (item-owned WINS; was "va remplacer") + i18n key + `t()` named-params | `cb8125869` | 6/6 categoryComposerEditorContract |
| H5 | **CatalogWarningService** inheritance-aware — no false "missing composer" blocker | `(this session)` | 7/7 incl. 2 new (still-missing raises; inherited suppresses) |
| H6 | Discriminator test proving the PricingService gap (asymmetry) | `a458dd17c` | 14/14 ComposerStepConstraintTest |

**Regression:** `Composer|MenuProjection|CatalogWarning|ComposerStepConstraint` = **164/164 pass** (2 pre-existing skips). 0 frozen-zone files touched.

---

## 3. Confirmed findings (adversarially verified)

### OPEN — frozen, owner-gated
- **[P1] PricingService composer-step validation skipped for inherited items** (`PricingService.php:564-566`).
  Independently confirmed; completeness-critic found **no other `item_id`-only resolver**.
  Frozen → **`GATE-G-PRICINGSERVICE-INHERITANCE-LOCK-REQUEST.md`** (concrete diff ready). Includes the
  `allow_repeat`-unenforced facet (same root). Validation-only; no price/NF525 impact.

### OPEN — non-frozen, hardening backlog (latent until category wizards used)
- **[P2] Builder lets you publish a multi-attribute wizard with unconfigured `item_attribute` steps.**
  Template seeds `source_ref=''`; UI labels it "(optionnel)" with "empty = all options" default; no
  warning when 2+ `item_attribute` steps stay empty → the collapse in §1. **Recommend:** publish-time
  validation/warning requiring `source_ref` when the source has >1 attribute (or when ≥2 item_attribute
  steps share empty ref). *Design decision on UX (block vs warn) → owner.*
- **[P2] N+1 multiplication:** category inheritance makes every menu item reach
  `ComposerProfileProjection::project → ChoiceAvailabilityResolver::snapshotForItem` (~+90 redundant
  stock queries/menu load once a category profile covers ~45 items; pre-fix the NULL-profile early-return
  skipped it). Partly pre-existing redundancy. **Recommend:** reuse the menu loop's batched
  `snapshotForItems` instead of per-item snapshot inside `project`.
- **[P2] i18n fr-only:** the 13 GAP-A `label.composer.*` keys + `category_publish_warning` exist in `fr`
  only (en/ar missing). **No raw-key leak** (admin surface is FR-locked + `fallbackLocale:'fr'`), but
  breaks the `label.composer.*` parity convention and the parity sentinels don't cover composer keys
  (regex requires literal `$t(`; composer uses bare `t()`). **Recommend:** add keys to en/ar + extend sentinel.
- **[P2] Branch isolation of category-owned profiles is UNTESTED** (all isolation fixtures item-owned).
  Code is provably correct (same branch-scope predicate, redundant `WizardProfileBranchScope`); add a
  test that publishes a branch-scoped *category* profile.
- **[P2] Precedence drift:** `ComposerProfileService::resolveForItem` is **CATEGORY-wins**, opposite the
  canonical **item-owned-wins** the render fix applies. Appears dead/unused but is a footgun. **Recommend:**
  align to item-wins or remove.

### CLEAN / informational
- **[P3]** branch-scope predicate cosmetically diverges across the 4 resolvers (result-set-equivalent).
- **[P3]** `availableSourcesForCategory` omits `authorizeBranchScope` — coherent with item sibling, discloses no branch data.
- **[P3]** `IngredientService` addon drill-down returns `wizard_profile_id=null` for inherited items.
- **[P3]** stale `addon_role` persists when `source_type` switched away from addon (benign dead data).
- **[P3]** orphan locale files `bn.json`/`de.json` not loaded by vue-i18n.
- Security/branch-isolation dimension: **structurally clean** (no cross-branch leak, no unpublished render).

---

## 4. Evidence index
- `e2e-correct/B1-wizard-step1-viande.png` — corrected inherited wizard (4 distinct meats) ✅ owner proof
- `e2e-correct/A-admin-cat5-composer.png` + RUN_LOG — GAP-E picker populated (`Viande 1`, `Sauce`)
- `ComposerStepConstraintTest` 14/14 · `CatalogWarningServiceExtraCodesTest` 7/7 · `MenuProjectionComposerProfileTest` 8/8
- Workflow `wvu7c2aos` — 6 dimensions, 16 findings, 3 adversarially confirmed
- `GATE-G-PRICINGSERVICE-INHERITANCE-LOCK-REQUEST.md` — frozen heal, ready to countersign

## 5. Recommendation to owner — the turnkey lever

The category-wizard renders correctly **when authored correctly** (GAP-E unblocked the picker), but it
is **not turnkey**. To make "apply template → publish → works" true out-of-box, the highest-leverage
fix is:

- **★ TURNKEY FIX (non-frozen, the actual cure for "ça fonctionne pas"): make the template auto-fill
  `source_ref`.** At category/item template apply-time (`ComposerTemplateService::buildPayload` /
  `ComposerProfileService::createForCategory`), map each `item_attribute` step-key to the representative
  item's matching attribute **by name** (`viande`→"Viande 1", `sauce`→"Sauce…") and **drop** template
  steps with no matching attribute (no "taille" on Le Cayenne tacos). Then a freshly-applied template
  produces a working wizard with zero manual narrowing. This — not just the publish-guard — is what
  removes the manual-authoring friction. I can heal it on your go.

Before relying on category wizards in production, co-ship:
- (a) **GATE-G** PricingService validation (FROZEN — needs your sign-off; diff ready).
- (b) the **turnkey template auto-fill** above (non-frozen) **and/or** a **publish-time `source_ref`
  guard** (block/warn on unconfigured `item_attribute` steps). Guard = safety net; auto-fill = the real
  fix. (b) carries a UX decision (auto-fill silently vs guard-and-prompt) — I'd like you to pick.
- (c) N+1 + i18n + precedence-drift hardening (non-frozen, low-risk).
