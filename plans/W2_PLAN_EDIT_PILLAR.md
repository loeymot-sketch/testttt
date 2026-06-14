# W2 — Wizard Studio EDIT pillar (visual page CRUD + live refresh)
**Date:** 2026-06-15 · base = W1 converged. Planned by direct source read (planning workflow was server-rate-limited; contract gathered read-only).

## Goal (the "modify" moment)
Make the Studio's "Pages du wizard" panel an EDITOR for CATEGORY wizards (unflagged, V1 path):
**reorder (drag) · rename (inline) · delete · add page** → each change **saves + refreshes the live borne preview** (reloadPreview). This is the first "edit it, see the borne update" Shopify moment.

## Verified contract (reuse, file:line)
- Draggable: `vue-draggable-next` → `import { VueDraggableNext }` (ComposerStepListSidebar.vue:101), `<draggable v-model item-key="_uid" handle=".x" @end>`.
- Persist (bulk): `PUT admin/composer/profiles/{id}` `{template, branch_id_scope, steps:[payloadForStep], version}` (ProductComposerEditorComponent.vue:806); 409 → `{expected}` (`:811-814`).
- `payloadForStep` shape (NF525: **no price**): step_key/label/source_type/source_ref/min_select/max_select/allow_repeat/visible_on/stockable_choices/position/is_active/addon_role (`:770-797`). `ComposerStepRequest` prohibits `price`.
- Per-step also possible: PATCH `composer/steps/{id}`, DELETE `composer/steps/{id}` — but **bulk PUT is simpler** (one 409 point, atomic) → W2 uses bulk PUT.
- CATEGORY path: step/profile routes under `wizard.per_item_profile_guard` which 404s only ITEM-owned profiles when `FEATURE_WIZARD_PER_ITEM_DEMO` off → **category editing works unflagged** (verified prior audit).

## W2 implementation (WizardStudioComponent.vue — NON-FROZEN)
1. Register `draggable: VueDraggableNext`. Normalize loaded steps with a `_uid` (stable key).
2. Panel "Pages du wizard": `<draggable v-model="steps" handle>` rows = drag-handle + inline `<input v-model=label @change=save>` + min/max + delete btn; "+ Ajouter une page" button.
3. `saveStudioDraft()`: build `{template, branch_id_scope, steps: payloadForStep[], version}` → PUT profile → hydrate (version++, steps) → `await reloadPreview()` (device frame refreshes). 409 → conflict banner + reload.
4. Wire reloadPreview (already stubbed) after each successful save. Debounce rename saves (~500ms).
5. New page default: source_type='item_attribute', source_ref='' (renders as "non affichée·0 option" → my W1 banner flags it honestly; source binding = W3/W6).

## NF525 / frozen
No price on steps (payloadForStep excludes it; request prohibits). No frozen edit (only WizardStudioComponent.vue). Read-only preview unchanged (the live mount stays onAddToCart=noop).

## Tests
- Vitest: add-page increments steps + triggers PUT + reloadPreview; reorder PUTs new positions; delete removes + PUTs; 409 → conflict flag. (stub axios + draggable; assert vm + PUT payload).
- PHPUnit (sqlite :memory:): the bulk PUT on a CATEGORY profile persists reordered/renamed/added/removed steps (reuse ComposerProfileApiTest patterns) — likely already covered; add a Studio-path assertion if gap.
- e2e: reorder/rename/add/delete live on :8766 cat profile → device frame updates; converge adversarially.

## W3-W8 outline (each: plan→audit→test→execute, one by one)
- **W3** selection rules UI (min/max/allow_repeat sliders + single/multi/required human labels) — frontend only, fields exist.
- **W4** images+description per option — ADDITIVE migration (image_path/description on item_variations/item_extras) + media upload + projection enrich. Biggest; may split W4a(desc)/W4b(image upload).
- **W5** billing modes Free/Each-priced — edit option catalog price (free=0) via catalog endpoint; NO PricingService touch.
- **W6** templates turnkey — auto-fill source_ref on apply (fix the '' bug) so added/templated pages render.
- **W7** POS preview tab — replicate/mount POS view (composer-aware flag = owner gate G-POS-COMPOSER).
- **W8** full e2e end-to-end + gates (G-MEDIA, G-POS-COMPOSER, G-PUSH).
</content>
