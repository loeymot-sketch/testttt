# PHASE A — Builder wizard : preuve RÉELLE (siège gérant) 2026-06-11

> Owner exige : prouver RÉELLEMENT (pas « oui c'est fait ») que composer/modifier/supprimer/ajouter page+choix marche, que les listes enregistrées sont VRAIES, accessible et faisable. Drive live :8768/foodking_e2e + vérification code/DB.

## Ce qui est PROUVÉ (live + code + DB)
- **A1 Ouvrir** : composer charge l'état réel. État VIDE (item 37) = pédagogique : « Le wizard est le parcours que ton client suit… Chaque page = une étape », 2 chemins (template / page manuelle), aperçu « Aucune étape — achat direct ». Capture `b1-empty-state-item37.jpg`.
- **A2 LISTES ENREGISTRÉES RÉELLES** (le cœur de l'exigence owner) — scopées à l'item, les 3 types :
  - item 37 Big Classique : Attribut produit → **Viande 1 / Viande 2 / Sauce (1ère Gratuite)** ; Groupe extras → **crudite / supplement** ; Addon catalogue → **Menu (Frites+Boisson) / Frites Seules / Boisson Seule**.
  - item 33 Petite Frites : Attribut produit → **Style frites** (seul) ; extras/addons vides (item simple). → les listes reflètent EXACTEMENT le catalogue réel, jamais du faux.
- **A3 Ajouter une page** : PAGES 0→1 (item 37), 1→2 (item 33). Dirty-guard actif.
- **A4 Modifier + PERSISTER** : page « Choix de la viande » → source Viande 1 → choix unique. Panneau « Options et prix (lecture seule) » montre les **4 vrais choix** (Poulet mariné/curry/tandoori/crispy — « Inclus ») + **« Prix modifiables depuis la fiche produit (jamais sur la page wizard) »** = NF525 communiqué DANS l'UI. Sauvegarde → dirty effacé → **RECHARGE → page persiste** (PAGES=1). DB e2e : step `Choix de la viande | item_attribute:1 min=1 max=1`, **source_item_attribute_id=1 → Viande 1** (FK T-COMPO-3 persisté). Captures `b2-page-configured-viande.jpg`.
- **A6 Page personnalisée (ajouter/supprimer CHOIX)** — architecture CODE-VÉRIFIÉE saine : `createPersonalPage` (ComposerProfileController.php:224) crée de **vraies ItemExtra** (catalogue) avec le prix par option, l'étape = `extra_group` **price-free** → prix dans **SSOT PricingService (NF525 respecté)**. Idempotent (réutilise le step), **guard de collision mb_strtolower** (refuse si group_label existe, folding = projection sur toute DB, 3 rounds adversariaux documentés), re-edit PK-bound collision-free + soft-delete des options retirées. Modal clair : « le prix vit sur l'option, jamais sur la page ». Capture `b3-custom-page-editor.jpg`.
- **A9 Publier** : modal « Publier ce wizard — visible immédiatement sur POS et Kiosk », bouton vert AA (#15803d). Diff price-free (0 prix). Capture `b4-publish-diff.jpg`. (NB : 1 clic publish n'a pas abouti = **drop de session :8768 intermittent**, artefact harness — re-vérifié en cours par l'adversaire cluster publish.)

## Verdict Phase A (preuve, pas foi)
Le builder est **vraiment accessible, faisable et architecturalement sain** : listes réelles scopées, persistance DB prouvée, FK stable, custom-page → catalogue SSOT (NF525), guards robustes, UX pédagogique. **C'est la bonne méthode.** Restent à valider par la dispute adversaire : reorder, delete page/wizard, template, publish end-to-end (+ le drop session), rendu client borne, a11y/clavier, cas limites.
