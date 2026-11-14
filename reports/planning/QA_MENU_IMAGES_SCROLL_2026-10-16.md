# MENU-IMAGES-SCROLL-20261016 — exécution mobile

PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_VERDICT: PASS
EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)
FALLBACK_REASON: missing node_modules codex vendor binary (mandat humain explicite).

Périmètre exécuté : navigation des écrans `mobile/`, confinement des décorations et cadrage des images produits. Le plan parent est `plans/PLAN_MENU-IMAGES-SCROLL-20261016_2026-10-16.md`. Les images de marque, nouveaux SKU et changements de catalogue restent hors de cette délégation. Aucun prix, slug, ID, service de commande ou fichier frozen à modifier. Graphiti non chargé ; index mémoire local consulté.

Précontrôles : `npm run verify:boucle` conditionnel OK (canaux API non testés), réservation des cinq fichiers mobiles OK, `safety-check.sh` PASS, `preflight-execute.sh` PASS. L'ancien `ACTIVE_CYCLE.md` désigne encore une autre mission clôturée ; il n'est pas modifié par ce mandat d'exécution ciblé.

## Résultat de l'exécution déléguée

Correctif mobile implémenté et inspecté. Ce rapport ne clôture pas le cycle et ne constitue pas un gate de publication.

Fichiers produit modifiés :

- `mobile/shared.jsx` : hook `useScreenScrollReset` exécuté avant affichage, limité au conteneur de l'écran ; remise à zéro verticale/horizontale au changement de clé, position conservée lors des rerenders ordinaires ; images sans capture de pointeur.
- `mobile/screens-main.jsx` : raccordement des huit écrans principaux au hook, décorations d'accueil non interactives, cadrage `contain` explicite des images produits (accueil, menu, détail de secours, panier).
- `mobile/screens-item-steps.jsx` : remise à zéro du conteneur réutilisé à chaque étape du wizard et à l'ouverture d'un produit direct ; `contain` sur le visuel produit direct.
- `mobile/styles.css` : confinement graphique des écrans, arrêt de l'animation d'entrée du wizard, décorations non interactives, défilement instantané.
- `mobile/tests/navigation.spec.js` (nouveau) : cycle de vie du hook, navigation avant/arrière, absence de reset lors d'un rerender, isolation des previews, raccordement des écrans, sept slots produits et conservation du comportement des héros.

Traçabilité modifiée : ce rapport et append dans `reports/AGENT_ACTIVITY_LOG.md`. Le plan déjà présent n'a pas été modifié.

La skill `frontend-design` a guidé la séparation entre slots produits en `contain` et héros : le défaut `cover` de `Slot` et le `contain` préexistant du héros signature sont conservés. Aucun nouvel asset ou design global introduit.

## Vérifications exécutées

| Vérification | Résultat |
| --- | --- |
| `node mobile/tests/navigation.spec.js` | PASS |
| Analyse syntaxique JSX par `@babel/core` des trois fichiers JSX modifiés | PASS |
| `git diff --check` | PASS |
| Playwright/Chromium, viewport 390×844 | PASS |
| Playwright/Chromium, viewport 320×568 | PASS |
| Playwright/Chromium, viewport 430×932 | PASS |
| `node mobile/tests/menu.spec.js mobile/data/menu.js mobile/data/menu.js` | FAIL préexistant : trois écarts uniques, répétés sur les deux chargements du fichier mobile |
| `node mobile/tests/ordersParity.spec.js` | FAIL préexistant : une ligne historique Tacos L |

Chaque parcours Chromium a chargé le vrai `mobile/index.html` sur un serveur local éphémère, puis exercé : accueil défilé → bouton Commander → neuf étapes de personnalisation → ajout au panier ; retour ; ouverture de Coca puis du tiramisu en cliquant sur leur zone image dans le menu défilé ; affichage complet en `contain` ; ajout au panier ; retours ; ouverture du panier depuis le menu défilé ; navigation par onglets. Assertions : destination en haut, disparition de l'ancien écran, animation de wizard désactivée, clics reçus sans forçage, images chargées, aucune exception JavaScript.

Limites : authentification locale de test, appels au backend `127.0.0.1:8766` neutralisés ; aucun paiement, création de commande réelle ou test iOS natif. WebKit n'est pas installé. Ce sont des parcours UI mobiles ciblés, pas le PASS d'une suite E2E métier complète.

Inspection visuelle des captures : canette Coca entière à 390 px, pot tiramisu entier à 320 px. Captures locales temporaires : `/tmp/MENU-IMAGES-SCROLL-390-Coca-Cola.png`, `/tmp/MENU-IMAGES-SCROLL-320-Tiramisu.png` (et équivalents des trois tailles). Le cadrage ne restaure pas des pixels déjà supprimés dans les assets d'origine.

## Écarts de référence inchangés

- `menu.spec.js` attend 16 produits `has_extra_meat`, Tacos L à 7,90 € et 38 produits ; ces assertions divergent déjà du catalogue actuel.
- `ordersParity.spec.js` trouve une ligne historique Tacos L à 7,90 € alors que le catalogue actuel indique 8,90 €.
- `mobile/data/menu.js`, `mobile/data/orders.js`, les deux tests existants et `config/menu_images.php` ont été comparés octet pour octet à `HEAD` : tous identiques. Ces écarts ne sont pas corrigés par ce mandat interdisant les modifications de prix.

## Invariants et handoff

- Prix, slugs et IDs : aucun changement ; aucune logique de prix ajoutée.
- `OrderStatus`, `branch_id`, dispatch après commit et services de commande : aucun changement.
- Frozen : aucun fichier concerné ; safety check PASS.
- Pas de modification de `config/menu_images.php` ni des données menu ; remplacement de photos et ajouts de SKU non exécutés dans cette délégation.
- Le hook global `post-execute.sh`, dépendant de l'ancien `ACTIVE_CYCLE.md` et lançant les suites backend/globales, n'a pas été exécuté dans ce sous-mandat mobile. La validation procédurale globale, l'audit Claude et l'audit GPT final restent à l'orchestrateur parent.

SELF_CHECK: correctif ciblé PASS ; baseline catalogue non verte. Aucun gate auto-approuvé, aucun commit, déploiement ou soumission Apple.

## Validation indépendante complémentaire

- `node mobile/tests/navigation.spec.js` : PASS.
- `git diff --check` : PASS.
- Suite mobile E2E canonique ciblée : 12 échecs. Les scénarios utilisent le slug obsolète `tacos-xxl` et une structure de wizard historique (`[role="checkbox"]`) ; ces échecs caractérisent une dérive préexistante de la suite, pas une régression introduite par ce correctif.
- `menu.spec.js` et `ordersParity.spec.js` : échecs préexistants de parité catalogue/historique Tacos L.

VERDICT: NEEDS_FIX — le correctif visuel ciblé est validé, mais le dépôt ne peut pas être déclaré prêt à soumission Apple tant que la suite E2E canonique, les images de marque et le catalogue demandé ne sont pas alignés.
