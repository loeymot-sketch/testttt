# RUN — V14 T11 POS_AVAILABILITY_LIVE_GUARD

**TASK_ID:** V14_07_T11_POS_AVAILABILITY_LIVE_GUARD  
**Date:** 2026-04-20  
**Agent:** foodking-routine-implementer (Composer 2)  
**Statut:** PASSED

## Résumé exécutif

- Grille POS : tuiles avec `is_available === false` / `0` / `'0'` → classe `pos-item-tile is-unavailable`, badge `pos.item_86_d`, pas d’ouverture du modal (clic neutralisé + `pointer-events: none`).
- Modal : `canAddToCart` inclut `catalogItemAvailable` (absence de `is_available` = dispo) ; bandeau `pos.item_unavailable_during_edit` si indispo en cours d’édition ; pas de fermeture forcée du modal.
- Broadcast `ItemAvailabilityChanged` : toast debounce ~1s par `item_id` via `alertService.warning` + `pos.item_no_longer_available` ; synchronisation du détail modal via `ItemComponent.syncItemAvailabilityFromBroadcast` (sans toucher `pruneUnavailable` existant).
- **Mutation Vuex `frozenItemIds` : non utilisée** — propagation réactive suffisante (choix conservateur, voir § Choix).

## Fichiers modifiés / créés (périmètre T11)

| Fichier | Action |
|---------|--------|
| `resources/js/components/admin/pos/PosComponent.vue` | `_onItemAvailabilityChanged` : toast + sync enfant ; `_maybeToastItemUnavailableLost` ; `data._availabilityToastTimers` |
| `resources/js/components/admin/pos/ItemComponent.vue` | Tuiles indispo, CSS, `canAddToCart` / computed dispo, bandeau, `syncItemAvailabilityFromBroadcast` |
| `resources/js/languages/fr.json`, `en.json`, `ar.json` | Bloc `pos.*` (3 clés) |
| `tests/js/posAvailabilityLiveGuard.spec.js` | **CREATE** — 5 cas sentinel |
| `tests/js/posItemAvailabilityHandler.spec.js` | `makeHandler` aligné + 1 cas modal/sync |
| `tests/js/posVariationMultiQty.spec.js` | Shim computed (`catalogItemAvailable` chaîne) pour éviter régression après extension de `canAddToCart` |

**OFF_LIMITS respectés :** aucune modification de `PaymentComponent.vue`, backend events/listeners, migrations.

## Diff fonctionnel (condensé)

### PosComponent.vue

- Après mise à jour in-place de `itemsRaw` / `items`, si passage à indispo : `pruneUnavailable` (inchangé), `_maybeToastItemUnavailableLost(itemId, name)` avec garde anti-spam par clé `itemId` sur ~1s.
- Appel `this.$refs.posItemComponent.syncItemAvailabilityFromBroadcast(itemId, isAvailable, reason)` lorsque l’item est trouvé dans la liste (dispo ou indispo).

### ItemComponent.vue

- `tileClassList` / `isCatalogTileUnavailable` / `onProductTileClick` pour la grille.
- Computed `catalogItemAvailable`, `itemUnavailabilityBannerVisible`, `canAddToCart` étendu.
- `syncItemAvailabilityFromBroadcast` remplace `this.item` par un merge pour déclencher la réactivité Vue 3.

### i18n

- `pos.item_86_d`, `pos.item_unavailable_during_edit`, `pos.item_no_longer_available` (FR / EN / AR).

## Tests Vitest (régression demandée §7)

Commande :

```bash
npx vitest run tests/js/posItemAvailabilityHandler.spec.js \
  tests/js/posAvailabilityLiveGuard.spec.js \
  tests/js/posCart.spec.js \
  tests/js/posCartScoped.spec.js \
  tests/js/PosComponent.spec.js
```

**Résultat :** 20/20 tests passés (5 fichiers).

**Contrôle additionnel (hors liste stricte mais recommandé) :** `tests/js/posVariationMultiQty.spec.js` — 8/8 après correction du shim computed (même cause que T11 : `canAddToCart` dépend de `catalogItemAvailable`).

## Parcours UX (texte)

1. L’opérateur voit la grille : article encore en stock → tuile normale, clic → chargement `item/details` → modal.
2. Un broadcast rend l’article indispo : tuile passe en grisée + badge « Épuisé », clics sans effet.
3. Si le modal était déjà ouvert sur cet article : liste catalogue + objet modal mis à jour ; toast une fois (debounce) ; bouton « Ajouter au panier » désactivé ; bandeau d’information ; le modal reste ouvert jusqu’à action utilisateur.
4. Article sans champ `is_available` : toujours traité comme disponible (rétrocompat).

## Choix / notes conservatrices

1. **Pas de `frozenItemIds` dans `posCart.js`** : la mise à jour passe par `syncItemAvailabilityFromBroadcast` sur l’instance enfant ; évite état dupliqué et reste dans `SUBSYSTEMS_TOUCHED` minimal.
2. **Toast** : `alertService.warning` (déjà utilisé ailleurs dans le projet), pas de nouvelle lib.
3. **`posVariationMultiQty.spec.js`** : ajustement du shim `createVm` pour refléter la chaîne de computed Vue — nécessaire tant que les tests n’utilisent pas `mount` complet.

## Risque résiduel / suivi validateur

- Faible : si `ref="posItemComponent"` pointait vers plusieurs instances, seul le dernier monté reçoit la sync — structure actuelle (un `ItemComponent` visible) inchangée.
- Vérifier en manuel : toast + Echo sur environnement Soketi réel.

## Trace exécution

`EXECUTE_DELEGATION: foodking-routine-implementer`
