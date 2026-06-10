# LOCK — pos-wizard.js : renderer générique composer (GATE-W6 / G-4)

**Fichier frozen :** `public/js/pos-wizard.js` (CLAUDE.md §7 — « design parfait » owner, strict-no-touch)
**Date :** 2026-06-10 · **Demandeur :** Claude (GOAL_WIZARD_BEST_CAISSE_BORNE)

## §1 Autorisation owner (verbatim)
Commande owner `/ultraplan` du 2026-06-10 :
> « plan as supervisor pour le meilleur UI et UX et coté technique composition et modification et tout details comme j'ai demandé avant pour **le wizard de caisse au meilleur possible**, et puis de même pour la borne en multi page. une fois fait créer le goal ultra complexe et détaillé au max avec test-e2e et adversair agents et **ne stop pas jusqu'à tout bon est validé avec preuve de test** max raisonnement. »

Interprétation : autorisation explicite du travail caisse = franchissement de GATE-W6 (documenté PENDING depuis `GOAL_WIZARD_E2E_PARITY_2026-06-09.md` §5 et `GOAL_CMS_GESTION §S`). Le présent LOCK formalise le périmètre exact ; tout dépassement = STOP + re-gate.

## §2 Périmètre EXACT (rien d'autre)
- **Ajouts purs (nouvelles fonctions)** : `renderGenericChoicesStep`, `composerChoicePrice`, `composerAddonTotal`, `escWiz` (+ extensions recap/instruction/submit listées C5-C7 du GOAL).
- **Insertions ≤2 lignes chacune, 5 points** : dispatch `:1131-1152`, `calculateRunningTotal:~1323`, `bindEvents:5315+`, `updateWizardUI:4906-4940`, `canProceedFromStep:5176-5253` (+`renderRecapStep:2050`, `buildWizardInstruction:2376`, mapping submit `:4041-4055` si nécessaire à la parité).
- **AUCUNE modification** des renderers legacy (viande/sauce/supplément/pain/taille/menu), du CSS `pos-wizard.css`, du blade, ni du design existant.
- **Flag `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED` : default reste FALSE.** Activation uniquement par env sur le harness e2e (:8767/foodking_e2e). Le flip prod = décision owner séparée (G-5/G-4 runtime).

## §3 Sécurité / invariants
- NF525 : aucun prix lu depuis le step ; jointure par id depuis `lastItemData` (catalogue) ; charge réelle backend inchangée (`PricingService` intouché).
- XSS : tout champ d'origine builder (`name/label/description/subtitle`) passé par `escWiz` au render ; updates dynamiques en `textContent` (convention existante :3251+).
- Flag OFF ⇒ chemins nouveaux inatteignables (early-return `:553`) — prouvé par captures avant/après identiques sur wizard legacy.

## §4 Rollback
`git revert` du/des commits taggés `[LOCK-W6]` (ajouts isolés, insertions 1-ligne) ; aucun schéma, aucune donnée.

## §5 Validation exigée avant clôture
Specs `posWizardComposerAware` étendu + `posWizardGenericRender` (À CRÉER) verts · e2e :8767 flag ON : render + sélection + total + recap + ticket + payload backend prouvés par captures/network · flag OFF : legacy bit-identique · adversarial dispute 0 P0/P1 · frozen-diff HORS périmètre §2 = 0.

## §10 Sign-off
- Owner : ✅ via /ultraplan 2026-06-10 (verbatim §1)
- Exécutant : Claude, commits `[LOCK-W6]` sur `goal/cms-gestion-2026-06-10-spine` (no push)
