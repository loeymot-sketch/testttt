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
- `mobile/tests/mobile-identity.spec.js` (lecture/régression uniquement)
- `mobile/tests/menu.spec.js`
- `mobile/tests/navigation.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
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

## Source de parité

La référence de lecture est l’API locale FoodKing `http://127.0.0.1:8766/api/frontend/item`
avec la clé publique déjà utilisée par l’application, complétée par les endpoints de détails
des items lorsque les options doivent être vérifiées. `branch_id=1` est le périmètre Le Cayenne.
Le probe doit capturer `id`, `slug`, `price`, groupes d’options, contraintes required/min/max,
et signaler `ESCALATE_BACKEND_PARITY` si le drift n’est pas nul. L’export/API est une preuve
de comparaison, pas une autorisation d’écrire dans le backend.

## Invariants

- Le backend reste la source de vérité des prix et des options.
- Aucun changement de `OrderStatus`, `branch_id`, dispatch, auth, paiement ou migration.
- Les catégories et produits visibles doivent avoir un asset local vérifiable ; un asset
  d’une autre marque est interdit.
- Le badge allergène est retiré des cartes compactes, mais la divulgation détaillée reste
  présente dans la fiche et le récapitulatif.
- Les payloads de commande restent byte-stables hors chemins visuels explicitement couverts.
- Aucun champ `price`, calcul de total, règle d’option ou prix de supplément ne sera modifié
  dans ce lot. Le miroir prix frontend existant est un risque connu et fera l’objet d’un lot
  backend/API séparé ; il ne sera pas masqué par une mise à jour de fixture.

## Stratégie de test

`local-validation` + `playwright-critical-flow` : tests Node de catalogue/parité visuelle,
puis création pendant EXECUTE et exécution de `npx playwright test
--config=tests/mobile-e2e/playwright.config.js tests/mobile-e2e/catalog-visual-critical.spec.js`
sur le serveur mobile dédié 8087, avec
parcours iPhone accueil → menu → catégorie → fiche → personnalisation → panier. Le test
vérifie les images chargées, `alt`, absence d’emoji visible, nouveautés, absence de badge
allergène compact, présence du détail allergène, prix affichés sans les modifier et absence
de débordement horizontal.

## Exécution attendue

1. Vérifier les assets existants et marquer les références sans packshot exact comme
   `BLOCKED_ASSET`, sans les remplacer par une autre marque ; un fallback neutre n’est pas
   considéré comme une livraison finale pour ces références.
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
PLAN_REVIEW_VERDICT: PASS

