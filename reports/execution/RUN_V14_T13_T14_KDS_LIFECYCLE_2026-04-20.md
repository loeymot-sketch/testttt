# RUN V14 — T13 + T14 KDS lifecycle (station + bump/recall + timer + sound)

**Date:** 2026-04-20  
**Cycle:** `V14_VAGUE_D_PHASE1_2026-04-20`  
**Task:** `T13_T14_KDS_STATION_BUMP_RECALL_LIFECYCLE_FUSED`  
**Statut:** **PASSED** (livraison code + tests ciblés ; régressions PHPUnit hors scope signalées)

## Résumé

- Migration `items.kds_station` (enum + index, idempotent `Schema::hasColumn`).
- Modèle `Item` : `$fillable` + cast.
- API KDS : `OrderItemResource` expose `kds_station` depuis le catalogue article (nécessaire pour filtrer côté UI).
- Frontend KDS (`KitchenDisplaySystemComponent.vue`) : filtre station (localStorage `kds.station_filter`), regroupement par table (tri + en-têtes repliables, `kds.group_by_table`), bump / rappel 60s (Vuex `kds` + `localStorage` `kds.bumped_items_v1`), classes d’attente `<5 / 5–10 / >10 min`, son sur nouvelle commande (`/sounds/kds-new-order.mp3` — voir note asset).
- Store `resources/js/store/modules/kds.js` enregistré dans `store/index.js`.
- Helper `resources/js/helpers/kdsDisplay.js` (filtre + classes d’escalade + parse date).
- i18n FR / EN / AR : 11 clés (`label.*`, `button.*`, `message.kds_recall_grace_expired`).

## Tests

| Suite | Résultat |
|--------|----------|
| `npx vitest run tests/js/kds*.spec.js` | **11/11** verts |
| `npx vitest run` (complet) | **680/680** verts |
| `php artisan migrate` | OK (migration déjà appliquée sur l’environnement de dev utilisé) |
| `php artisan test --filter='Item\|Order'` | **2 échecs préexistants / hors changement KDS** : `DispatchAfterCommitTest` (3), `OrderAllergenSnapshotComposedTest` (1) — pas liés à `kds_station` ni à `OrderItemResource` pour KDS |

## Fichiers livrés

- `database/migrations/2026_04_20_230000_add_kds_station_to_items.php`
- `app/Models/Item.php`
- `app/Http/Resources/OrderItemResource.php` (wiring `kds_station`)
- `resources/js/helpers/kdsDisplay.js`
- `resources/js/store/modules/kds.js`
- `resources/js/store/index.js`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `resources/js/languages/fr.json`, `en.json`, `ar.json`
- `public/sounds/kds-new-order.mp3` (fichier vide / placeholder — **à remplacer par ops** avec un MP3 valide)
- `tests/js/kdsStationFilter.spec.js`
- `tests/js/kdsBumpRecall.spec.js`
- `tests/js/kdsTimerEscalation.spec.js`

## Scope son

- Lecteur `<audio>` + volume (`kds.sound_volume`) + on/off (`kds.sound_enabled`).
- Le dépôt contient un **placeholder** à la racine `public/sounds/` ; en production, fournir un vrai fichier audio ou un chemin CDN pour éviter erreurs de lecture navigateur.

## TODOs / backlog

- **Multi-station par ligne** : aujourd’hui une ligne hérite du `kds_station` du catalogue `items` ; un même article multi-stations mériterait une modélisation dédiée (hors scope).
- **Sync backend des bumps** : l’état « bumped » est **local** (session + `localStorage`) ; pour multi-postes KDS, prévoir persistance API + conflits.
- **Admin item form** : exposer la sélection `kds_station` sur la fiche article (si pas déjà présent ailleurs).

## Risques résiduels

- Son sur « nouvelle commande » : déclenché quand la longueur du store `kitchenDisplaySystemOrder/lists` augmente après le premier chargement (`_kdsOrdersHydrated`) — un rechargement filtre/pagination atypique pourrait théoriquement jouer le son ; acceptable pour MVP.
- `OrderItemResource` est partagé par d’autres surfaces : le champ `kds_station` supplémentaire est neutre pour les clients existants.
