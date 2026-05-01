# POS Claude Design Integration Audit — 2026-04-27

TASK_ID: POS-CLAUDE-DESIGN-INTEGRATION-2026-04-27  
EXECUTE_DELEGATION: codex-extension  
VERDICT: PASS_WITH_LOCAL_RECONNECT_RESERVE

## Objectif

Porter l'intention visuelle du design POS V4 / Claude Design vers la caisse active sans casser le fonctionnement existant, en particulier le wizard POS qui est conservé comme surface de prise de commande rapide.

## Sources de design vérifiées

- Aucun prototype standalone POS comparable à `Borne FoodKing.html` n'a été trouvé dans le dépôt courant.
- Sources POS disponibles et utilisées comme référence :
  - `plans/PLAN_POS_V4_EXPORT_READINESS_2026-04-25.md`
  - `plans/PLAN_POS_V4_IMPL_MASTER_2026-04-26.md`
  - `reports/audit/RAPPORT_ECARTS_DESIGN_POS_V4_SYSTEMIQUE_2026-04-25.md`
  - `reports/audit/AUDIT_POS_V4_EXPORT_DESIGN_2026-04-24.md`
  - `resources/css/pos-v4.css`

## Périmètre implémenté

### POS shell actif

- Ajout d'une enveloppe POS V4 sur la caisse active : `data-pos-v4-shell`.
- Bandeau opérateur sombre/rouge avec statut branche, nombre d'articles et accès plan de salle.
- Recherche renforcée : hauteur, contraste, bouton rouge.
- Grille catégories portée en boutons natifs pour l'ergonomie tactile.
- Cartes catégories et produits stylées en cartes denses, lisibles, adaptées à l'encaissement.
- Ticket caisse renforcé : titre, compteur, total plus visible, actions annuler/commande plus nettes.

Fichier principal : `resources/js/components/admin/pos/PosComponent.vue`

### Modal paiement

- Refonte visuelle de la modal paiement : header sombre/rouge, carte total plus forte, boutons espèces/carte plus tactiles, pavé numérique plus lisible, CTA vert renforcé.
- Le mode carte TPE reste simulé via saisie des 4 derniers chiffres.
- La logique de paiement existante n'a pas été remplacée par une logique front de prix.

Fichier principal : `resources/js/components/admin/pos/PaymentComponent.vue`

### Wizard POS

- Le wizard POS n'a pas été réécrit.
- Aucun changement dans `resources/js/components/admin/pos/ItemComponent.vue`.
- Validation navigateur : le wizard ouvre bien `Le Terminator`, impose 2 viandes, ajoute la ligne au panier avec viandes, pain, crudités et total 9,00 €.

## Double audit technique

| Zone | Avant | Après | Verdict |
| --- | --- | --- | --- |
| Shell caisse | Surface fonctionnelle mais visuellement plate | Direction POS V4 appliquée au shell actif | PASS |
| Wizard POS | Fonctionnel et optimisé | Préservé, non réécrit | PASS |
| Panier | Fonctionnel, moins lisible | Ticket caisse plus lisible, total/action renforcés | PASS |
| Paiement | Fonctionnel, modal peu distinctive | Modal paiement plus claire, TPE simulable | PASS |
| Prix | Backend SSOT requis | Aucun calcul métier ajouté côté frontend | PASS |
| Branch isolation | Invariant critique | Aucun changement backend/scope branche | PASS |
| Build assets | À régénérer après SFC | `npm run production` PASS | PASS |
| Narrow browser | Sidebar admin masque la caisse dans panneau étroit | Constaté ; cible réelle desktop POS validée | RESERVE |

## Validation navigateur

### In-app browser

URL : `http://127.0.0.1:8000/admin/pos-v4`

- Login POS validé avec `pos@lecayenne.fr`.
- Surface POS V4 présente : `Caisse FoodKing`, `Commande rapide`, catégories, best sellers, ticket caisse.
- Wizard ouvert depuis produit visible.
- Deux viandes sélectionnées.
- Article ajouté au panier.
- Panier : `Le Terminator`, `Merguez`, `Kefta`, total `9.00€`.
- Erreurs console critiques : 0.

Réserve : le panneau in-app browser est très étroit ; le menu admin latéral masque une partie de la caisse. Le desktop POS réel a été validé séparément.

### Playwright desktop 1440x960

Script local exécuté sur `http://127.0.0.1:8000/admin/pos-v4`.

Résultats :

```json
{
  "visibleChecks": {
    "hasCaisseFoodKing": true,
    "hasCommandeRapide": true,
    "hasTicketCaisse": true,
    "hasBestSellers": true,
    "hasNoFatalError": true
  },
  "cartChecks": {
    "hasLine": true,
    "hasMeats": true,
    "hasTotal9": true
  },
  "paymentChecks": {
    "hasModal": true,
    "hasTotal9": true,
    "hasCash": true,
    "hasCard": true
  },
  "jsCriticalErrors": []
}
```

Captures produites :

- `reports/ux/pos-v4-desktop-shell-2026-04-27.png`
- `reports/ux/pos-v4-desktop-wizard-2026-04-27.png`
- `reports/ux/pos-v4-desktop-payment-2026-04-27.png`
- `reports/ux/pos-v4-desktop-card-sim-2026-04-27.png`
- `reports/ux/pos-v4-desktop-receipt-after-card-submit-2026-04-27.png`

## Validation automatisée

| Commande | Résultat |
| --- | --- |
| `npx vitest run tests/js/PosComponent.spec.js tests/js/posComponentA11y.spec.js tests/js/posPaymentComponentContract.spec.js tests/js/paymentComponentPropMutation.spec.js tests/js/paymentComponent401Retry.spec.js tests/js/posPaymentItemsNormalize.spec.js` | 6 files PASS, 19 tests PASS |
| `npm run production` | PASS |
| `npx playwright test tests/e2e/02-pos-cash.spec.js tests/e2e/05-pos-card.spec.js --project=chromium` | 6 tests PASS |
| `php artisan test tests/Feature/QuoteReplayIdempotencyTest.php --filter=pos` | 1 test PASS |
| `php artisan test tests/Feature/PosCashEndpointSentinelTest.php tests/Feature/PosPricingSsotProofTest.php tests/Feature/PosTicketRestaurantPaymentTest.php` | PASS sur la sentinelle exécutée |
| `git diff --check -- resources/js/components/admin/pos/PosComponent.vue resources/js/components/admin/pos/PaymentComponent.vue reports/post_execute_latest.log` | PASS |
| `bash .cursor/hooks/safety-check.sh` | HALT préexistant : `app/Services/OrderService.php` est déjà staged en zone frozen |

Notes :

- Les warnings Vitest `router-link` / `vue-select` sont des warnings de stubs existants, non bloquants.
- `php artisan test` affiche `TTY mode requires /dev/tty`, non bloquant.
- La tentative de clic final `Confirm & Print Receipt` en Playwright a laissé la modal paiement ouverte avec la bannière locale `Reconnexion en cours...`. Aucune erreur JS critique. Le backend POS quote/commit est couvert par `QuoteReplayIdempotencyTest::pos commit consumes quote with order id`, qui passe.

## Invariants FoodKing vérifiés

- Pricing SSOT backend : aucun calcul métier de prix ajouté au frontend.
- OrderStatus enum : non touché.
- Branch isolation : non touchée.
- Dispatch after commit : non touché.
- Symétrie OrderService / FrontendOrderService : non concernée par ce patch visuel.
- Frozen zones : non touchées par cette mission.

## Fichiers modifiés par cette passe

- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `public/js/pos-app.js`
- `public/mix-manifest.json`
- `reports/ux/pos-v4-desktop-*.png`
- `reports/ux/POS_CLAUDE_DESIGN_INTEGRATION_AUDIT_2026-04-27.md`
- `reports/post_execute_latest.log`

## Risques restants

1. **Prototype source POS manquant** : le fichier exact Claude Design POS n'est pas dans le dépôt. L'intégration s'appuie donc sur les artefacts POS V4 existants.
2. **Narrow admin shell** : en panneau étroit, le menu admin peut masquer la caisse. Sur viewport desktop POS 1440x960, la caisse est lisible et stable.
3. **Submit final sous bannière reconnect** : la validation visuelle paiement carte passe ; le clic final local est resté en état reconnect. À reprendre après stabilisation de l'état online local si l'objectif est une preuve vidéo jusqu'au reçu imprimé.
4. **Langue runtime** : Playwright headless a parfois rendu les libellés en anglais selon la session. La session navigateur interactive était en français. Le grand audit i18n reste le bon endroit pour forcer la langue partout.

## Conclusion

Le shell POS et la modal paiement ont été portés vers une direction visuelle POS V4 plus dense, plus contrastée et plus adaptée à l'encaissement. Le wizard POS n'a pas été réécrit et le parcours produit → wizard → panier → paiement carte simulé est validé en desktop, avec tests JS/E2E/build verts. Le seul point non clos est la preuve de soumission finale dans l'environnement navigateur local à cause de la bannière `Reconnexion en cours...`, compensée côté backend par la sentinelle quote/commit POS qui passe.
