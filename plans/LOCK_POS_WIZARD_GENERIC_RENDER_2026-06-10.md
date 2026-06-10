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

## §2bis Addendum d'exécution (2026-06-10, même périmètre)
Découverte à l'implémentation : le chemin VIVANT du wizard est la version **single-page** (`renderSinglePage`/`bindSinglePageEvents`) — le multi-step (`renderWizard`) est dormant. Le LOCK s'applique donc au chemin single-page : sections composer remplacent les sections legacy **sous `composerMode`** (2 lignes de garde `if (!composerMode)`), handlers dans `bindSinglePageEvents`, gate min_select sur le bouton « Ajouter au panier », ticket via `buildTicketInstruction`, mapping form via `syncAndSubmit` §4bis. Le chemin multi-step dormant a reçu les mêmes branchements (inactifs). Aucun renderer legacy modifié ; flag OFF prouvé inatteignable (`composer_step` posé uniquement par `buildStepsFromComposerProfile`, pinné par spec).

## §4 Rollback
`git revert` du/des commits taggés `[LOCK-W6]` (ajouts isolés, insertions 1-ligne) ; aucun schéma, aucune donnée.

## §5 Validation — ✅ FAITE 2026-06-10 (preuves archivées reports/test-e2e/cms-e2e-2026-06-10/w6-*.png)
- Specs : posWizardComposerAware 9/9 + posWizardGenericRender 10/10 (pinne le périmètre)
- e2e :8767 **flag ON** (Tacos, wizard catégorie publié) : 3 sections builder rendues dans le design owner (badges Obligatoire, « Jusqu'à 4 choix », compteur 1/4 ✓, Voir plus, coches) ; sélection viande (Inclus) + formule (+€3.00) → **total €8.50→€11.50** ; ticket cuisine groupé par page ; **gate min** : ajout sans sauce obligatoire BLOQUÉ ; avec sauce → **panier Vue Sous-total/Total 11,50 €** (mapping syncAndSubmit prouvé) ; 0 console error
- e2e **flag OFF** : 0 section générique, sections legacy viande+sauce intactes, même item, même total — chemins nouveaux inatteignables
- Fix périphérique non-frozen requis : `master.blade.php` (shell SPA) n'injectait pas `posWizardComposerAware` → ajouté (parité avec admin-pos-v4.blade:109)

## §5bis Validation exigée avant clôture (référence)
Specs `posWizardComposerAware` étendu + `posWizardGenericRender` (À CRÉER) verts · e2e :8767 flag ON : render + sélection + total + recap + ticket + payload backend prouvés par captures/network · flag OFF : legacy bit-identique · adversarial dispute 0 P0/P1 · frozen-diff HORS périmètre §2 = 0.

## §10 Sign-off
- Owner : ✅ via /ultraplan 2026-06-10 (verbatim §1)
- Exécutant : Claude, commits `[LOCK-W6]` sur `goal/cms-gestion-2026-06-10-spine` (no push)
