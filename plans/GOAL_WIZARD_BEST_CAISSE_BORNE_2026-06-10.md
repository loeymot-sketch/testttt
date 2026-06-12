# GOAL — WIZARD AU MEILLEUR : caisse (GATE-W6 sous LOCK) + borne multi-pages (uplift UX)

**Date:** 2026-06-10 · **Statut:** EXÉCUTION (owner /ultraplan : « wizard de caisse au meilleur possible… de même pour la borne… ne stop pas jusqu'à tout validé avec preuve »)
**Base:** `goal/cms-gestion-2026-06-10-spine` (superset spine) · Harness :8767/`foodking_e2e` · Audits fondateurs : agents aa0139eb (caisse) + a966fcdb (borne), 2026-06-10, tout file:line vérifié primary-source.

## §0 — CONTRATS
- **NF525** : prix JAMAIS sur un step ; la projection composer reste price-free (`ComposerProfileProjection.php:98-102`) ; tout affichage prix = jointure par id côté client depuis le catalogue (`item.variations/extras/addons`) ; la charge réelle reste 100% backend `PricingService`.
- **Frozen** : `KioskWizardComponent.vue` INTOUCHÉ (uplift borne = `steps/` + helpers uniquement). `pos-wizard.js` touché UNIQUEMENT sous `LOCK_POS_WIZARD_GENERIC_RENDER_2026-06-10.md` (GATE-W6) : ajouts purs + insertions 1-ligne listées, flag default reste FALSE, activation testée sur harness e2e seulement.
- Convergence : test-e2e adversarial (captures réelles + dispute) jusqu'à 0 P0/P1 ; suites + specs verts ; preuve de parité numérique caisse (sélection générique → total → recap → ticket KDS → submit backend).

## §1 — VOLET BORNE (non-frozen, sans gate) — cible `steps/KioskStepGenericChoicesComponent.vue`
P1 (owner-grade) :
- B1 **Prix par option** : join client `choice.id`×`source_type` → `item.variations/extras/addons` (prop `item` déjà bindée, Wizard:724) + `kioskPriceMixin` (pattern Supplements:99) ; affiche `+X,XX €` ou « Inclus ».
- B2 **Compteur « X / N » + max visible** (« Choisissez jusqu'à N ») + hint `role=status aria-live` (pattern Taille:34).
- B3 **Stepper −/+ si `allow_repeat`** (pattern Supplements) — tue le bug wipe-au-max (Generic:177-181).
- B4 **Badge « Épuisé »** sur cartes indisponibles (greyout seul aujourd'hui, Generic:122-128).
- B5 **Dé-doublon titre** : le `<h3>` du step (Generic:3) duplique le bandeau frozen (Wizard:1584) → convertir en guidance.
P2 : B6 cartes grande-image conditionnelles (toutes choices imagées → grille 3-4 col, média ≥120px) ; B7 micro-animations sélection (0.15s) ; B8 ✓ checkmark quand `max_select===1` (pattern Taille:30) ; B9 clamp desc 2 lignes.
**Acceptance** : `tests/js/kioskWizardGenericComposer.spec.js` étendu + `(À CRÉER tests/js/kioskGenericChoicesUx.spec.js)` mount-level (prix joint, X/N, stepper, épuisé) ; captures borne :8767 analysées ; adversarial.

## §2 — VOLET CAISSE (frozen, LOCK GATE-W6) — `public/js/pos-wizard.js`
Anatomie cible (audit §1) : markup indistinguable du design owner (`.wizard-options`/`.wizard-option`/`.check-mark`/`renderOptionIcon`/`.option-name`/`.option-price`, single si `max===1`, compteur style viande si `allow_repeat`, quota « n / max », cap 6 + `.btn-voir-plus`).
**Ajouts purs** : C1 `renderGenericChoicesStep(step)` ; C2 `composerChoicePrice(choice)` (join id/source_type → `lastItemData`, patterns :1274-1322) ; C3 `composerAddonTotal()` ; C4 `escWiz()` (échappement — surface builder moins trusted, conventions :3251+ textContent pour updates).
**Insertions 1-ligne (5)** : dispatch `:1131-1152` (+branche `generic_choices`) ; `calculateRunningTotal` `:~1323` (+`composerAddonTotal()`) ; `bindEvents` `:5315+` (+délégation `[data-type="generic"]` → `selections.generic={stepKey:{choiceId:count}}`) ; `updateWizardUI` `:4906-4940` (+branche generic) ; `canProceedFromStep` `:5176-5253` (+case min_select).
**Chaîne aval (le vrai risque, scopé au LOCK)** : C5 `renderRecapStep` `:2050` (+section choix génériques) ; C6 `buildWizardInstruction` `:2376` (+énumération générique → ticket KDS) ; C7 `syncAndSubmit`/mapping form `:3708,:4041-4055` (les sélections génériques partent au backend en option_ids — vérifier le payload réel attendu côté serveur AVANT de coder C7 ; si le chemin existant addons/extras suffit par id, C7 = mapping vers ce chemin).
**Garde-fous** : flag OFF ⇒ zéro chemin nouveau atteint (early-return :553) ; `composerAddonTotal()` no-op si `selections.generic` vide ; régression legacy testée (wizard Tacos classique flag OFF puis ON sur :8767) ; XSS : tout champ builder échappé via C4.
**Acceptance** : `tests/js/posWizardComposerAware.spec.js` étendu (existence + branchements) + `(À CRÉER tests/js/posWizardGenericRender.spec.js)` (render string : markup, prix joint, min/max) ; **e2e :8767 flag ON** : ouvrir item à wizard composer → pages génériques rendues (captures), sélection payante → total mis à jour → recap correct → panier → payload backend prouvé (network) ; flag OFF : legacy intact (captures avant/après identiques).

## §3 — EXÉCUTION
Wave 1 (parallèle) : implémenteur borne (B1-B9, TDD) ‖ orchestrateur caisse (LOCK commit → C1-C7 par étapes, commit par étape).
Wave 2 : build prod + e2e adversarial dual (captures réelles caisse flag ON + borne) → heal loop → convergence 0 P0/P1.
Wave 3 : BRAIN/mémoire + statut. Flag default reste FALSE (flip prod = décision owner post-validation, G-5 inchangé).
