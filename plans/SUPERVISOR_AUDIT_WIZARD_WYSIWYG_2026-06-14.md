# SUPERVISOR AUDIT — Wizard WYSIWYG plan + W0 (deep, adversarial)
**Date:** 2026-06-14 · 4 vérificateurs adversaires read-only + 1 trace DB source-primaire.
**Verdict global :** stratégie cœur **SAINE** (builder non-frozen, copie, même donnée) ; **NEEDS-REVISION** sur 2 points (D-2, D-3) — appliqués au plan. Aucune hallucination de sous-agent détectée sur le modèle de données.

## Claims testés
| # | Claim | Verdict | Preuve |
|---|---|---|---|
| C1 | Pas de prix sur un step (NF525) | ✅ CONFIRMÉ | `ComposerStepRequest.php:32` `'price'=>['prohibited']` ; migration steps sans prix |
| C2 | « 1er gratuit » = hardcodé frozen → gate PricingService | ❌ **RÉFUTÉ** | PricingService somme `price×qty` (transport-agnostic) ; item 41 sauces = 11 variations **0,00€** max=2 ; suppléments extras 0,90/2,00. Free-capped + each-priced = data-driven, **0 gate** |
| C3 | POS composer-aware défaut FALSE | ✅ CONFIRMÉ | `config/catalog_v15.php:104` `env(...,false)` ; `pos-wizard.js:436` |
| C4 | PricingService/KioskWizard/pos-wizard FROZEN | ✅ CONFIRMÉ | CLAUDE.md §7 |
| A | Pas de colonne image/description sur variations/extras | ✅ CONFIRMÉ | migrations create + ALTERs ; `getThumbAttribute` = config-only ⇒ colonnes additives requises |
| B | Composer admin/services NON-frozen | ✅ CONFIRMÉ | absents de §7 |
| C(flag) | item composer gated `FEATURE_WIZARD_PER_ITEM_DEMO`, catégorie non-gatée | ✅ CONFIRMÉ | `itemRoutes.js` + `config/catalog_v15.php:173` + `WizardPerItemDemo.php` |
| D(cols) | colonnes steps (min/max/allow_repeat/source/addon_role/visible_on/...) | ✅ CONFIRMÉ | migration `2026_04_27_143110` + ALTER `2026_05_03` |
| W0-API | endpoints item/category/profile existent + shape | ✅ WORKS | `routes/api.php` 722/378/769/776 ; fallback null-coalescing du composant OK |

## Corrections appliquées
- **P1 facturation (C2)** : G-PRICE **retiré**. V1 = free-capped + each-priced, 100% non-frozen. → plan D-3.
- **P1 fidélité preview (sceptique D, supervisé)** : « répliquer le rendu frozen » → **iframe du vrai kiosk** via endpoint `preview-projection` (draft→même projection). Fidèle par construction, 0 frozen, 0 dérive. La proposition brute du sceptique (monter le composant frozen) aurait exigé de modifier le frozen pour injecter le draft → écartée. → plan D-2.
- **P2** : afficher l'héritage catégorie>item (piège `resolveForItem`) → W1 ; UX du 409 → W2 ; `source_ref=''` auto-fill → W6 ; disque (npm cache clean → 8,5 Gi) → fait.
- **Bug W0** : `goBack()` 2 branches identiques → corrigé (`$router.back()` + fallback).

## Dette design notée (hors-V1)
- Override prix/image **par wizard** (vs édition du construct catalogue global) = nécessiterait `wizard_step_choice_overrides` ; pour V1 mono-restaurant l'édition catalogue suffit.
- Surcharge runtime « 1er gratuit » configurable = toucherait le marshalling frontend frozen → gate futur seulement si demandé.

## Ce qui reste vrai et solide
Modèle de données data-driven, frontière frozen nette, contrat API réutilisable, copie sans régression. La décomposition des 3 sous-agents initiaux est **fiable** (vérifiée en source primaire, 0 hallucination sur les faits porteurs).
</content>
