# Plan — MOBILE-EXCELLENCE-PHASE6-VISUAL-ALIGNMENT-20261016 — 2026-10-16

## Execution tier

complex — UI mobile, catalogue visuel et vérification E2E ; aucune logique de
prix ou de commande modifiée.

## Fichiers autorisés

| Fichier | Action | Limite |
|---|---|---|
| `mobile/data/menu.js` | corriger uniquement les chemins d’images exactes | conserver le hash métier |
| `mobile/screens-main.jsx` | contact rendu/testable et badge allergène neutre hors cartes | détail légal conservé |
| `mobile/tests/mobile-catalog-visual.spec.js` | renforcer les assertions visuelles | pas de données métier inventées |
| `tests/mobile-e2e/catalog-visual-critical.spec.js` | ajouter les assertions runtime | lecture seule |
| `reports/execution/.../report.md` | preuves et verdict | pas de secret/PII |

## Séquence

1. Vérifier les assets existants et leur usage actuel.
2. Remplacer uniquement les mappings de placeholder par un packshot exact déjà
   présent et vérifié visuellement ; si l’asset ne montre pas clairement la
   marque/flavour demandée, conserver le placeholder neutre (Orangina est donc
   explicitement maintenu neutre dans ce lot).
3. Conserver explicitement les noms, IDs, slugs, catégories, ordre catalogue,
   prix et constructeurs de payloads ; le test Node doit comparer leur hash avant
   et après le lot. La baseline immuable est le SHA-256 de la chaîne exacte
   `JSON.stringify(menu, (key, value) => ['image', 'hero', 'phone'].includes(key) ? undefined : value)`
   fixé dans `mobile/tests/mobile-catalog-visual.spec.js` :
   `dba1d13ba410398c0429ad164d937e28cffb91336cf176b0d384c20b313ae91d`.
   Le payload `buildLineItem` conserve sa sentinelle existante.
4. Exposer le numéro de branche via un sélecteur accessible sans réintroduire de
   numéro codé en dur dans l’écran. La valeur canonique vérifiée est celle de la
   branche active, alignée sur le défaut borne/backend `03 65 67 82 91` et
   sérialisée en `0365678291` dans la data layer mobile ; l’E2E lit aussi
   `GET /api/frontend/branch/show/1` et vérifie son `id` et son téléphone avant
   de comparer `window.LC.config.branchId === window.LC.menu.branch.id`.
5. Remplacer l’icône d’alerte visuelle par un repère neutre dans le détail,
   sans supprimer la disclosure légale complète.
6. Ajouter les garde-fous Node et Playwright, lancer le serveur mobile sur
   `http://127.0.0.1:8088`, utiliser un query cache-bust, puis exécuter :
   `node mobile/tests/mobile-catalog-visual.spec.js`,
   `node mobile/tests/mobile-identity.spec.js`,
   `node mobile/tests/navigation.spec.js`,
   `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js`,
   et `git diff --check` limité aux fichiers du lot.

7. Ajouter des assertions E2E : zéro badge allergène sur toutes les cartes
   compactes de l’accueil et du menu, un badge complet conservé dans le détail
   produit/recap, et tous les slots visuels chargés ou neutres.

## Risques

- Certains packshots demandés par le propriétaire n’existent pas encore dans le
  dépôt (Hawaï, Fuze Tea, Perrier, Oasis Cassis, Schweppes Agrumes, bouteille de
  Coca). Ils doivent rester neutres jusqu’à réception d’images exactes.
- Le serveur local peut servir un ancien bundle en cache ; la validation doit
  utiliser un rechargement cache-bust et les tests statiques.

## Plan review

PLAN_REVIEW_CHANNEL: foodking-complex-implementer (fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS
PLAN_REVIEW_NOTES: scope, baseline déterministe, source branch/phone, commandes
et assertions compact-vs-detail validés après trois passes de revue déléguée
