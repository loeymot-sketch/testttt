# Task — MOBILE-EXCELLENCE-PHASE9-VISUAL-REFONTE-20261016

## Objectif

Refondre l’accueil et les cartes catalogue de l’application mobile Le Cayenne
avec une direction visuelle « atelier Cayenne » : photos produits réelles,
hiérarchie éditoriale, catégories lisibles et navigation tactile fiable.

## Périmètre

- accueil mobile : en-tête, hero, catégories, sélection, nouveautés,
  informations du restaurant ;
- cartes menu : densité, photos, prix et actions ;
- suppression des décorations emoji dans les zones de marque/catégorie ;
- cache-busting des ressources modifiées ;
- tests statiques et E2E mobile.

## Hors périmètre

- prix, catalogue backend, payloads de commande et `branch_id` ;
- activation Mollie ;
- réactivation fidélité ;
- fournisseur SMS de production ;
- migrations, caisse, borne, KDS et packaging natif iOS.

## Invariants

- le backend reste la source de vérité des prix ;
- aucune modification d’`OrderStatus`, de `branch_id` ou du dispatch ;
- aucune image inventée ne remplace une image canonique : les assets absents
  restent neutres et identifiables ;
- la disclosure allergènes détaillée reste disponible dans la fiche produit,
  sans badge d’alerte sur les cartes compactes.

## Validation attendue

- tests Node mobile existants ;
- nouveau test statique de structure et de parité visuelle ;
- parcours Playwright accueil → menu → catégorie → personnalisation → panier ;
- inspection manuelle du preview `127.0.0.1:8088` avec ressources fraîches ;
- `git diff --check` scoped.

## EXECUTION_TIER

complex — interface multi-écrans et tests browser, sans invariant métier modifié.
