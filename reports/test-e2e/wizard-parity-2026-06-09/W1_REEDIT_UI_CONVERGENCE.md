# W1 — Re-edit UI wiring — CONVERGENCE (GOAL_WIZARD_E2E_PARITY)

Date: 2026-06-09 · Harness: :8766 disposable clone (`foodking_e2e`) · Branch: heal/pre-cloud-exec-2026-06-05
Commit: `70f176abc` (source) + this report.

## What shipped (non-frozen)
The backend already had create + update (PUT, server-trusted step PK, collision-free). W1 wired the
**missing builder UI** so a gérant can MODIFY an existing options page, not only create one, plus a
read-only pre-fill endpoint.

- Backend `showPersonalPage` GET `/profiles/{profile}/personal-page/{step}` — read-tier authz, returns
  `{label, min/max, visible_on, options:[{name,price,description,image_path}]}` from a representative
  item. 404 cross-profile, 422 non-extra_group. Price returned here (admin edits the construct = SSOT);
  the kiosk/POS projection stays price-free (untouched).
- Frontend `ProductComposerEditorComponent.vue` — "Modifier les options" button on an existing
  `extra_group` step → `editPersonalPage()` GETs pre-fill, opens modal in edit mode; `submitPersonalPage`
  PUTs in edit mode / POSTs in create mode; title + submit label reflect mode.

## Evidence (4 layers, all GREEN)
1. **Backend PHPUnit** `ComposerPersonalPageTest` — **18/18** (6 re-edit incl. the killer
   `test_reedit_targets_steps_own_group_not_body_label`, + 2 new GET: editable-state-with-prices,
   ownership/type guards).
2. **Frontend Vitest** `composerPersonalPageReedit.spec.js` — **4/4** (button visibility,
   server pre-fill, PUT-not-POST in edit, POST in create).
3. **Live visual** (:8766, item 41 "Bowl Frites Poulet mariné", "Suppléments" extra_group step):
   - `w1-02-step-selected-edit-button.png` — 3 pages in sidebar, edit button visible on the group step.
   - `w1-03-modal-prefilled.png` — title "Modifier la page personnalisée", label "Suppléments (0.90€)",
     5 options pre-filled WITH prices, submit "Mettre À Jour La Page". **0 console errors, 0 net 4xx/5xx,
     no raw i18n keys, layout/branding intact.**
4. **Live mutation round-trip** (`w1-04`): UI edit Oignon frais 0.90→1.50 → **PUT 200**
   (`options_synced:5, items_touched:1`) → DB persisted (1.50, all 5 options retained, none soft-deleted)
   → reverted to 0.90 (clone hygiene).

## Design note
The edit affordance shows on ANY existing `extra_group` step (not only createPersonalPage-origin), because
no persisted personal-page marker exists (the forgeable marker was reverted in the W5 saga) and the backend
is collision-free by construction for any extra_group step. This fulfils the owner ask "modifier each wizard
page" while staying overwrite-proof. Soft-delete of absent options is reversible and never alters past orders
(NF525 composition_snapshot frozen).

## W5 adversarial heal (added 2026-06-09)
Adversarial pass found a REAL P1 in W1 and it was fixed:
- **Cross-sibling silent delete**: `showPersonalPage` pre-filled from ONE representative item, but
  `updatePersonalPage` soft-deletes options absent from the submission across ALL category items.
  Heterogeneous siblings (category-1 'supplement' = 3 distinct sets across 12 items) meant editing
  could silently delete options never shown in the modal. **Fix**: `showPersonalPage` now returns the
  UNION across all scope items (dedupe by case-folded name). Test
  `test_show_personal_page_unions_options_across_heterogeneous_siblings` + live-verified (category-1
  supplement returns all 10 union options). Tests now **20/20**.
- Also added `test_reedit_works_on_catalog_template_origin_step...` (catalog-template-origin re-edit
  edits its own group + leaves a different group untouched).

## Verdict: W1 GREEN (after heal) — 0 OPEN P0/P1, tests 20/20. Borne side proceeds.
