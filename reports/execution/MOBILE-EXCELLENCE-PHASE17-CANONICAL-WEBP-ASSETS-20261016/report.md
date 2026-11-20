# MOBILE-EXCELLENCE-PHASE17-CANONICAL-WEBP-ASSETS-20261016

## Initialisation

Phase ouverte pour remplacer les références mobiles historiques par les
vignettes WebP canoniques déjà servies par le site et la borne.

`EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)`
`FALLBACK_REASON: codex-extension indisponible dans cette session ; exécution déléguée bornée.`

## Validation indépendante — 2026-10-16

- `node mobile/tests/mobile-catalog-visual.spec.js` — **PASS** ; 9/9 SHA
  identiques aux vignettes `public/images/menu/thumbs`.
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js --reporter=line --retries=0` — **PASS, 5/5** ; serveur mobile, chargement WebP, accueil, catégories, wizard et panier validés.
- `git diff --check` — **PASS**.
- Contrôle navigateur local frais `http://127.0.0.1:8088/?dev&fresh=20261016-r3` — **PASS** ; accueil Le Cayenne et catégories Sandwichs/Galette/Tacos visibles, aucun fallback d’image signalé.

Le hook global historique conserve un échec préexistant
`StripeCentsCastTest` (€1.005 → 100 au lieu de 101). Il n’est pas causé par
cette mission et aucun fichier paiement n’a été touché.

`VALIDATION_VERDICT: PASS`

## Exécution

Fichiers modifiés dans l’allowlist :

- `mobile/assets/menu/{sandwich-cayenne,sandwich-supreme,sandwich-mega,sandwich-terminator,tacos-cayenne,galette,cat-sandwich-cayenne,cat-galette,cat-tacos}.webp`
- `mobile/data/menu.js`
- `mobile/tests/mobile-catalog-visual.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`

Les neuf WebP ont été copiés byte-for-byte depuis `public/images/menu/thumbs/`.
Les produits sandwichs, tacos, galettes, leurs héros, le choix de pain Galette
et les trois catégories utilisent désormais les chemins `.webp`. Les anciens
PNG restent présents mais ne sont plus référencés par ces visuels.

## Validation ciblée

- `node mobile/tests/mobile-catalog-visual.spec.js` — PASS ; neuf SHA source/destination identiques, données métier inchangées.
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js --reporter=line --retries=0` — PASS, 5 tests.
  - Le serveur mobile sert les neuf WebP avec le SHA canonique.
  - Les images sont chargées dans l’accueil, le menu et le wizard.
  - Aucun fallback emoji visible, aucune image cassée, aucun débordement horizontal.
  - Navigation catégorie → personnalisation → panier validée.
- `git diff --check` — PASS.

## Invariants et risques

- Pricing backend, `OrderStatus`, `branch_id`, dispatch post-commit et services de commande inchangés.
- Aucun backend, prix, auth/SMS, paiement, fidélité, POS/kiosk ou packaging iOS touché.
- Risque résiduel : les anciens PNG existent encore volontairement ; un nettoyage nécessiterait une mission séparée.

`VALIDATION_VERDICT: PASS`
`AUDIT_VERDICT: PENDING`
`GPT_FINAL_AUDIT_VERDICT: PENDING`

## Audit de repli — 2026-10-16

### Périmètre et conformité au plan

- Le plan, la mission et le journal d’exécution limitent la phase aux neuf
  WebP catalogue, aux mappings `mobile/data/menu.js`, aux tests statiques/E2E
  et aux artefacts de gouvernance autorisés.
- Le diff vérifié ne contient, pour les fichiers texte de la phase, que des
  remplacements de chemins `.png` vers les WebP canoniques et les assertions
  SHA/runtime correspondantes. Les prix, identifiants de produits et règles
  de commande ne sont pas modifiés.
- Les autres changements présents dans l’arbre de travail sont préexistants
  ou hors phase et n’ont pas été touchés par cet audit.

### Preuves indépendantes

- Les neuf paires source/destination ont des SHA-256 identiques :
  `sandwich-cayenne`, `sandwich-supreme`, `sandwich-mega`,
  `sandwich-terminator`, `tacos-cayenne`, `galette`,
  `cat-sandwich-cayenne`, `cat-galette` et `cat-tacos`.
- `node mobile/tests/mobile-catalog-visual.spec.js` — **PASS** ; 39 visuels
  produits, 9 catégories, 15 boissons, parité wizard, copies canoniques et
  invariants métier contrôlés.
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js
  --config=tests/mobile-e2e/playwright.config.js --reporter=line --retries=0`
  — **5/5 PASS** ; serveur mobile, SHA servis, rendu accueil/menu,
  nouveautés, navigation catégorie → personnalisation → panier et absence
  de fallback image cassée validés.
- `git diff --check` — **PASS**.
- Le binaire `claude` n’est pas disponible (`claude command not found`) ; cet
  audit est donc exécuté dans la session Cursor selon le fallback prévu.

### Invariants FoodKing

- Pricing SSOT backend : **PASS**, aucune logique de prix modifiée.
- `OrderStatus` : **PASS**, aucune référence ni transition modifiée.
- `branch_id` : **PASS**, aucune donnée ou isolation de branche modifiée.
- Dispatch post-commit : **PASS**, aucun job, événement ou service backend
  modifié.
- Parité `OrderService` / `FrontendOrderService` : **PASS**, aucun service de
  commande touché.
- Zones frozen : **PASS**, aucun backend, paiement, auth/SMS, fidélité,
  POS/caisse/kiosk, migration ou packaging iOS touché.

AUDIT_CHANNEL: cursor-session
AUDIT_FALLBACK_REASON: claude command not found
AUDIT_VERDICT: PASS

## GPT final audit — 2026-10-16

- Le plan, la mission et l’activité d’exécution bornent correctement la
  livraison aux neuf WebP canoniques, aux mappings catalogue mobiles et aux
  contrôles statiques/E2E associés.
- Vérification indépendante : les neuf SHA-256 mobiles sont identiques aux
  fichiers `public/images/menu/thumbs/*.webp` du site.
- `node mobile/tests/mobile-catalog-visual.spec.js` — **PASS**.
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js
  --config=tests/mobile-e2e/playwright.config.js --reporter=line --retries=0`
  — **5/5 PASS**.
- `git diff --check` — **PASS**.
- Le diff de la phase ne modifie ni prix, ni identifiants métier, ni règles de
  commande, ni backend, ni authentification, ni paiement, ni fidélité, ni
  POS/borne, ni packaging iOS.
- Invariants FoodKing : pricing SSOT backend, `OrderStatus`, `branch_id`,
  dispatch post-commit et parité des services de commande — **PASS / non
  touchés**.
- Risques résiduels acceptés : les anciens PNG restent présents par choix du
  plan ; leur suppression ou une synchronisation plus large des autres
  surfaces nécessite une mission distincte. L’absence du binaire Claude reste
  couverte par le canal de repli déjà documenté.

GPT_FINAL_AUDIT_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
GPT_FINAL_AUDIT_MODEL: gpt-5.5-pro
GPT_FINAL_AUDIT_REASONING_EFFORT: xhigh
GPT_FINAL_AUDIT_VERDICT: PASS
