# RUN — V14 T18 — A11Y POS operator (WCAG 2.2 AA keyboard)

**TASK_ID:** T18_A11Y_POS_OPERATOR  
**CYCLE:** V14_VAGUE_D_PHASE2_2026-04-20  
**DATE:** 2026-04-20  
**STATUT:** PASSED  

## Scope livré (WCAG / UX)

| Zone | Livraison |
|------|-----------|
| Skip link | Lien « Skip to cart » (`#pos-cart`) en tête de `PosComponent`, styles `sr-only` + focus visible |
| Live regions | `#pos-a11y-live` (annonces SR) ; wrappers menu avec `aria-live="polite"` / `aria-relevant="additions"` / `aria-busy` pendant chargement |
| Totaux panier | Lignes récap `role="status"` + `aria-live="polite"` + `aria-atomic="true"` |
| Région panier | `#pos-cart` : `role="region"` + `aria-label` (i18n `a11y.cart_region`) |
| Liste lignes | `tbody role="list"` / `tr role="listitem"` |
| Quantités / retirer | `type="button"` + `aria-label` (increase / decrease / remove selon contexte) |
| Tuiles articles | `role="button"`, `tabindex`, clavier Enter/Space, `aria-label` avec prix ; bouton sac `tabindex="-1"` pour un seul focus |
| Reçu | `ReceiptComponent` racine : `role="document"` + `aria-label` |
| Helpers | `trapFocus` / `announce` dans `resources/js/helpers/posA11y.js` |
| Focus visible | `resources/css/pos-a11y.css` (+ source SCSS `resources/sass/admin/_pos_a11y.scss`) importé via `app.css` |

**Note :** Il n’existe pas de `CartComponent.vue` dans ce dépôt — le panier est dans `PosComponent.vue` ; les attributs demandés pour le « cart » y sont appliqués.

## Fichiers touchés / créés

- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/ItemComponent.vue`
- `resources/js/components/admin/pos/ReceiptComponent.vue`
- `resources/js/helpers/posA11y.js` (NEW)
- `resources/css/pos-a11y.css` (NEW)
- `resources/sass/admin/_pos_a11y.scss` (NEW)
- `resources/css/app.css` (@import `pos-a11y.css`)
- `resources/js/languages/en.json`, `fr.json`, `ar.json` (clés `a11y.*`)
- `tests/js/posA11y.spec.js` (NEW, 5 tests)
- `tests/js/posComponentA11y.spec.js` (NEW, 3 tests)

## Tests

- `npx vitest run tests/js/posA11y.spec.js tests/js/posComponentA11y.spec.js` → **8/8** verts  
- Régression : `npx vitest run tests/js/pos*.spec.js tests/js/PosComponent.spec.js` → **132/132** verts  

## Vérifications type bouton

- `type="submit"` réservé au formulaire de recherche et au modal client (ajout client) — pas d’introduction de `submit` sur « Appliquer remise » (passé en `type="button"`).

## TODOs / backlog (hors scope)

- Audit Lighthouse / axe sur écran POS réel (staging) ; scores et issues → backlog produit.
- Brancher `trapFocus` sur modales POS (variation, paiement, parked) si fermeture clavier doit piéger le focus de façon stricte.
- Option : appeler `announce()` depuis mutations panier (`cart_updated`) pour lecteurs d’écran.

## Risques résiduels

- Annonces `role="status"` sur plusieurs `<li>` peuvent être verbeuses si le panier change très fréquemment — ajuster en `aria-live="off"` sur lignes non critiques si feedback terrain.
- Warnings Vitest préexistants sur `TestPosComponent` (méthodes / `router-link` non résolu) — inchangés, non bloquants.

EXECUTE_DELEGATION: foodking-routine-implementer
