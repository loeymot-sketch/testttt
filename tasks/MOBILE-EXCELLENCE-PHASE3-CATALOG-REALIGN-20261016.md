# Task — MOBILE-EXCELLENCE-PHASE3-CATALOG-REALIGN-20261016

## Demande propriétaire

Refondre l’interface mobile Le Cayenne pour que les catégories, nouveautés et fiches
produits utilisent des photos réelles et lisibles, sans emoji de substitution ni badge
allergène dans les cartes compactes. Préserver la parité catalogue avec la borne et le
backend : prix, IDs, slugs, options et payloads ne doivent pas être inventés côté mobile.

## Périmètre de ce lot

- `mobile/data/menu.js`
- `mobile/shared.jsx`
- `mobile/screens-main.jsx`
- `mobile/screens-item-steps.jsx`
- `mobile/styles.css`
- `mobile/tests/mobile-catalog-visual.spec.js`
- `mobile/tests/menu.spec.js`
- `mobile/tests/navigation.spec.js`
- `reports/execution/MOBILE-EXCELLENCE-PHASE3-CATALOG-REALIGN-20261016/report.md`

## Hors périmètre / escalades

- Authentification SMS et fournisseur SMS : nouvelle mission backend/auth requise.
- Fidélité désactivation/réactivation 30 jours : contrat API et politique à valider avant
  toute modification backend ou persistance cliente.
- Mollie / paiement natif : nouvelle mission paiement avec retour 3DS/deep-link et
  idempotence ; aucune activation frontend seule.
- Création du projet iOS / signature Apple : lot packaging natif séparé.
- Ajout de boissons absentes de la borne/DB : ne pas ajouter d’items ou de prix locaux
  sans validation de la source backend.

## Invariants

- Le backend reste la source de vérité des prix et des options.
- Aucun changement de `OrderStatus`, `branch_id`, dispatch, auth, paiement ou migration.
- Les catégories et produits visibles doivent avoir un asset local vérifiable ; un asset
  d’une autre marque est interdit.
- Le badge allergène est retiré des cartes compactes, mais la divulgation détaillée reste
  présente dans la fiche et le récapitulatif.
- Les payloads de commande restent byte-stables hors chemins visuels explicitement couverts.

## Stratégie de test

`local-validation` + `playwright-critical-flow` : tests Node de catalogue/parité, puis
parcours iPhone accueil → menu → catégorie → fiche → personnalisation → panier, avec
vérification des prix affichés, photos accessibles, absence de badge compact et absence
de débordement horizontal.

## Exécution attendue

1. Vérifier les assets existants et marquer les références sans packshot exact comme
   bloquées, sans les remplacer par une autre marque.
2. Harmoniser le rendu catégories/nouveautés/cartes sur les photos réelles existantes,
   avec fallback neutre explicite sans emoji.
3. Retirer uniquement les indicateurs allergènes des cartes compactes ; conserver les
   détails dans les écrans de personnalisation et de récapitulatif.
4. Ajouter les garde-fous de parité et les tests de rendu mobile.
5. Rejouer les tests autonomes et le parcours Playwright ciblé.

## Plan review

PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PENDING

