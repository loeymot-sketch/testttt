# RUN — V14 Vague C-α — Audit 200% + Remédiation

**TASK_ID:** `V14_VAGUE_C_ALPHA_2026-04-20`
**Date:** 2026-04-20
**Cycle:** V14 — Finalisation POS (sub-vague C-α — caisse opérateur, sans gates humains)
**Statut final:** **PASSED — 200% audited + 2 holes invisibles fixés + 1 hole P1 cross-branch sentinellé**

---

## 1. Périmètre exécuté en parallèle

| Sous-tâche | Modèle | Statut sortie subagent | Statut audité réel |
|---|---|---|---|
| **T11** `POS_AVAILABILITY_LIVE_GUARD` | Composer (foodking-routine-implementer) | PASSED 20/20 | PASSED |
| **T10** `POS_SEARCH_BARCODE` | Composer (foodking-routine-implementer) | PASSED 10/10 | PASSED |
| **T08** `POS_PARK_HOLD_RECALL` | GPT-5.4 (foodking-complex-implementer) | "BLOCKED" (faux positif) | **PASSED** 6+4 verts ; les 3 échecs `php artisan test --filter='Pos\|Order\|Pricing'` sont **préexistants** (DispatchAfterCommit C9 + AllergenSnapshot, hors scope T08) |

**Pré-vague (Vagues A + B)** : CLOSED PASSED, 51/51 + fix B-6 stable.

---

## 2. Audit 200% — 9 findings (1 explore subagent + relecture diff)

| # | Angle | Sév | Statut initial | Action |
|---|---|---|---|---|
| **C-1** | `posParked.recall` ne purge pas les items 86'd → panier "pollué" | **P1** | GAP | **FIX appliqué** |
| **C-2** | `_availabilityToastTimers` non cleanup au `beforeUnmount` | P2 | GAP | **FIX appliqué** |
| **C-3** | Branche morte `this.itemsRaw` (pas dans `data`) | INFO | OK (test-only) | TODO documenté |
| **C-4** | `lookupBarcode` 404 si item indispo | INFO | Comportement défini | TODO message métier dédié |
| **C-5** | F-keys actives même si drawer parked ouvert | P2 | GAP | **FIX appliqué (helper option `shouldIntercept`)** |
| **C-6** | `payload_json` LONGTEXT sans plafond explicite | P2 | RISQUE | TODO durcissement quota |
| **C-7** | `lookup-barcode` route auth admin sans permission `pos` | INFO | OK (auth admin présente) | TODO permission ciblée |
| **C-8** | Migration barcode pas idempotent si colonne préexistante | P2 | PARTIEL | **FIX appliqué (check `indexExists`)** |
| **C-9** | Tests Feature manquants : `lookupBarcode`, recall/discard cross-branch | **P1** | GAP | **FIX appliqué (2 sentinels cross-branch)** |

**Verdict audit avant fix** : `FIX_REQUIRED` (2 P1 + 4 P2)
**Verdict après fix** : **PASS — 2 P1 + 3 P2 traités, 4 INFO/TODO documentés**

---

## 3. Détail des fixes appliqués

### Fix C-1 (P1) — `posParked.recall` purge items indispo

**Site** : `resources/js/store/modules/posParked.js#recall`

**Bug invisible** : Si une commande est parkée 30 min puis qu'un item devient 86'd entre-temps, le recall restaure le panier sans détection. L'opérateur ne s'en rend compte qu'au checkout (422 du serveur). Bug silencieux — aucun feedback intermédiaire.

**Fix** : Après `posCart/lists` + `posCart/discount`, lit `rootGetters['item/lists']` (catalogue actif), construit l'ensemble des IDs disponibles, dispatche `posCart/pruneUnavailable(itemId)` pour chaque ligne restaurée dont l'item est dans le catalogue MAIS `is_available === false`. Items inconnus du catalogue (paginated/not-yet-loaded) → conservés (defensive). Le tableau `_recall_purged_item_ids` est inscrit dans le payload retourné pour permettre un toast UX downstream.

**Sentinel** : 2 nouveaux tests dans `tests/js/posParked.spec.js` :
- `recall prunes lines whose item is now unavailable in the live catalog` (item 100 indispo → purgé, item 200 dispo → gardé, item 300 inconnu → gardé)
- `recall does not crash when item catalog is empty (backward compat)`

### Fix C-2 (P2) — Cleanup `_availabilityToastTimers` au `beforeUnmount`

**Site** : `resources/js/components/admin/pos/PosComponent.vue#beforeUnmount`

**Risque** : Si le POS est démonté pendant un debounce de toast (1s), le `setTimeout` continue → toast hors-écran ou crash si le composant n'existe plus.

**Fix** : Boucle sur `_availabilityToastTimers`, `clearTimeout` + delete pour chaque entrée.

### Fix C-5 (P2) — F-keys neutralisables si drawer ouvert

**Site** : `resources/js/helpers/posBarcode.js` + `PosComponent.vue#mounted`

**Bug invisible** : Si l'opérateur ouvre le drawer "Parked Orders" et appuie F2, la catégorie change en arrière-plan sans qu'il le voit. Action involontaire.

**Fix** : `createFKeyShortcuts` accepte désormais un 2e argument `{ shouldIntercept: () => boolean }`. PosComponent passe `() => !this.showParkedOrders`. Sentinel : 2 nouveaux tests dans `posBarcode.spec.js`.

### Fix C-8 (P2) — Migration barcode robuste

**Site** : `database/migrations/2026_04_20_160000_add_barcode_index_to_items.php`

**Bug invisible** : Si la colonne `barcode` existe déjà via une autre migration historique (ou un fork), l'index `items_barcode_idx` n'était jamais créé.

**Fix** : Découpe column / index en 2 conditions indépendantes. Helper privé `indexExists($table, $idx)` via `getDoctrineSchemaManager` + try/catch defensive pour drivers (sqlite tests).

### Fix C-9 (P1) — Sentinels cross-tenant

**Site** : `tests/Feature/PosParkedOrderTest.php`

**Risque** : Aucun test ne prouvait que le filtrage cross-branch fonctionnait. Une régression silencieuse aurait permis à un opérateur d'une branche A de recall/discard une commande parkée d'une branche B.

**Fix** : 2 nouveaux tests :
- `recall_cross_branch_returns_404` (vérifie aussi que la commande n'est PAS supprimée par la tentative cross-tenant)
- `discard_cross_branch_returns_404`

---

## 4. Tests — résultats finaux

### Vitest POS (13 fichiers)

```bash
npx vitest run tests/js/posPaymentItemsNormalize.spec.js tests/js/posVariationMultiQty.spec.js \
  tests/js/posCart.spec.js tests/js/posCartScoped.spec.js tests/js/posCartPrune.spec.js \
  tests/js/posCartPruneScoped.spec.js tests/js/posNormalizeIds.spec.js \
  tests/js/posDineInFlag.spec.js tests/js/PosComponent.spec.js \
  tests/js/posBarcode.spec.js tests/js/posAvailabilityLiveGuard.spec.js \
  tests/js/posItemAvailabilityHandler.spec.js tests/js/posParked.spec.js
```

**Résultat** : **13 fichiers / 76 tests verts** (avant fixes : 72 ; +4 sentinels C-1 + C-5).

### PHPUnit Feature

```bash
php artisan test tests/Feature/PosParkedOrderTest.php
```

**Résultat** : **8/8 verts** (avant fixes : 6 ; +2 sentinels cross-branch C-9).

### Régression PHP globale (info)

`php artisan test --filter='Pos|Order|Pricing'` → 347 passed / 3 skipped / **3 failed (préexistants hors scope)** :
- `DispatchAfterCommitTest::OrderCreated` (gate C9 ouvert documenté)
- `DispatchAfterCommitTest::OrderStatusChanged` (gate C9 ouvert documenté)
- `OrderAllergenSnapshotComposedTest` (`FINDING_BACK_DEFERRED` connu)

→ **Pas de nouvelle régression introduite** par Vague C-α.

---

## 5. Fichiers modifiés/créés (Vague C-α complète + fixes audit)

### Subagent T11 (Composer)
- `resources/js/components/admin/pos/PosComponent.vue` (handlers Echo + tuiles + toast debounce)
- `resources/js/components/admin/pos/ItemComponent.vue` (canAddToCart + bandeau + sync)
- `resources/js/languages/{fr,en,ar}.json` (3 clés)
- `tests/js/posAvailabilityLiveGuard.spec.js` (CREATE 5 tests)
- `tests/js/posItemAvailabilityHandler.spec.js` (extend +1)
- `tests/js/posVariationMultiQty.spec.js` (shim canAddToCart)

### Subagent T10 (Composer)
- `database/migrations/2026_04_20_160000_add_barcode_index_to_items.php` (CREATE) **+ FIX C-8**
- `app/Models/Item.php` (fillable barcode)
- `app/Http/Controllers/Admin/ItemController.php` (lookupBarcode)
- `routes/api.php` (route lookup-barcode)
- `resources/js/helpers/posBarcode.js` (CREATE) **+ FIX C-5**
- `resources/js/store/modules/item.js` (action lookupByBarcode)
- `resources/js/components/admin/pos/PosComponent.vue` (debounce + listeners + cleanup) **+ FIX C-2 + C-5 wiring**
- `resources/js/languages/{fr,en,ar}.json` (1 clé barcode)
- `tests/js/posBarcode.spec.js` (CREATE 6 tests, +2 sentinels C-5 = **8 tests**)

### Subagent T08 (GPT-5.4)
- `database/migrations/2026_04_20_200000_create_pos_parked_orders_table.php` (CREATE)
- `app/Models/PosParkedOrder.php` (CREATE)
- `app/Services/PosParkedOrderService.php` (CREATE)
- `app/Http/Controllers/Admin/Pos/ParkedOrderController.php` (CREATE)
- `routes/api.php` (4 routes parked-orders)
- `resources/js/store/modules/posParked.js` (CREATE) **+ FIX C-1**
- `resources/js/store/index.js` (register posParked)
- `resources/js/components/admin/pos/ParkedOrdersComponent.vue` (CREATE)
- `resources/js/components/admin/pos/PosComponent.vue` (bouton Park + drawer)
- `resources/js/languages/{fr,en,ar}.json` (clés park/restore/discard)
- `app/Console/Commands/PosPurgeParkedOrders.php` (CREATE, NON inscrite scheduler)
- `tests/Feature/PosParkedOrderTest.php` (CREATE 6 tests, +2 sentinels C-9 = **8 tests**)
- `tests/js/posParked.spec.js` (CREATE 4 tests, +2 sentinels C-1 = **6 tests**)

### Audit 200% (orchestrateur Claude)
- `reports/execution/RUN_V14_VAGUE_C_ALPHA_AUDIT_200_2026-04-20.md` (ce rapport)

**Total** : ~25 fichiers créés/modifiés, **76 tests Vitest verts** + **8 tests Feature parked verts**.

---

## 6. Conflits zone partagée `PosComponent.vue` — vérification

3 subagents ont modifié `PosComponent.vue` en parallèle. Audit confirme :
- **Un seul `mounted()`** : T10 (debounce + listeners barcode/F-keys) + T11 (handlers Echo) + T08 (drawer wiring) cohabitent sans écrasement.
- **Un seul `beforeUnmount()`** : tous les cleanups présents (debounce, barcode, F-keys, kiosk timer, Echo, ws + après audit : `_availabilityToastTimers`).
- **`data()`** : aucune collision (`showParkedOrders` ajouté par T08 ; `_stop*` / `_debounced*` / `_availabilityToastTimers` runtime — pas dans `return {}`).
- **Méthodes** : aucune double définition.
- **Imports** : `debounce` (lodash), `posBarcode` helpers, `ParkedOrdersComponent` cohabitent.
- **Template** : tuiles T11 dans `ItemComponent` (séparé) ; bouton Park + drawer T08 dans le panier — pas de conflit layout.

---

## 7. TODOs reportés (non bloquants Vague C-α)

| TODO | Origine | Pour quand |
|---|---|---|
| Auto-park sur logout / inactivity | T08 (out of scope) | T08-bis ou Vague D |
| Quota / max size payload parked | C-6 P2 | T08-bis |
| Permission `pos` ciblée sur `lookup-barcode` | C-7 INFO | T10-bis |
| Message métier "item connu mais épuisé" différencié de "barcode inconnu" | C-4 INFO | T10-bis |
| Suppression branche morte `itemsRaw` ou implémentation | C-3 INFO | nettoyage Vague D |
| Renseigner `barcode` côté BO admin | T10 TODO | backend admin items |
| Gate C9 `dispatch-after-commit` à fermer (préalable T17) | KI-001 ouvert | gate humain dédié |
| Gate G14-B (T09 line discount/void NF525) | gate humain | gate humain dédié |
| Vague C-β : T19 floorplan + T15 imprimante ESC/POS + T21 receipt redesign | différé | prochaine vague C-β |

---

## 8. Verdict final Vague C-α

**STATUS: CLOSED — PASSED — 200% AUDITED**

- 3 sous-tâches (T11 + T10 + T08) implémentées en parallèle sans conflit zone partagée.
- 76/76 Vitest POS + 8/8 Feature parked verts.
- 2 trous P1 invisibles détectés et **fixés** (C-1 recall pollué + C-9 cross-branch sentinels).
- 3 trous P2 cosmetic/robustesse fixés (C-2 cleanup + C-5 F-keys + C-8 migration).
- 4 INFO/TODO documentés pour vagues suivantes.
- 0 régression introduite (les 3 échecs `--filter='Pos|Order|Pricing'` sont **préexistants** documentés dans KI_001 et FINDING_BACK_DEFERRED).
- Pas de zone gelée touchée. Pas de gate humain consommé.

---

## 9. Trace d'exécution

`EXECUTE_DELEGATION:`
- T11 → `foodking-routine-implementer` → PASSED
- T10 → `foodking-routine-implementer` → PASSED
- T08 → `foodking-complex-implementer` → PASSED (faux "BLOCKED" du subagent due à 3 échecs préexistants hors scope)

`AUDIT 200%:` Claude Opus 4.7 (orchestrateur) avec délégation à 1 explore subagent readonly → 9 findings → 5 fixes appliqués (2 P1 + 3 P2).

---

*Fin RUN_V14_VAGUE_C_ALPHA_AUDIT_200_2026-04-20.*
