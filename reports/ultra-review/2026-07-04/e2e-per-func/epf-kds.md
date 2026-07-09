# E2E per-functionality audit — KDS (board + statuts + timing + symboles + OSS)

HEAD 3c7145bf4 · DB foodking_e2e · read-only (tinker LECTURE SEULE + code file:line). Posture refute-by-default.

## Fonctionnalités testées

### 1. Board-release (visible par status, PAS kds_station) — OK
- `orders.kds_station` column **N'EXISTE PAS** (tinker `Schema::hasColumn` = NO) → "kds_station=mythe" confirmé empiriquement.
- Release SSOT = `KitchenReleaseRule` : `visibleStatuses()`=[ACCEPT,PREPARING,PREPARED] + `applyBoardReleaseFilter()` (PAID | PENDING_COUNTER | POS+CASH).
- Live: 367 orders en statut visible → 364 released-for-board (3 exclus = non-payés/non-PENDING_COUNTER) → filtre effectif.
- `list()` (KitchenDisplaySystemOrderService:73-78) et `changeStatus()` guard (447) partagent le même prédicat → "visible == bumpable".

### 2. Transitions statut posent le timing — **BROKEN (P2)** sur le chemin KDS réel
- Le board KDS POST `admin/kds-order/change-status` (frontend `kitchenDisplaySystemOrder.js:39`) → `KitchenDisplaySystemController@changeStatus` → `KitchenDisplaySystemOrderService::changeStatus`.
- Ce service (**lignes 451-452**) fait `$locked->status = $newStatus; $locked->save();` **SANS jamais poser** `accepted_at`/`preparing_at`/`prepared_at`.
- L'instrumentation timing (commit 16f89b0b2, `[KITCHEN-TIMING 2026-07-03]`) a été ajoutée UNIQUEMENT dans `OrderService.php:2213-2218`, servi par `pos-order/change-status` (PosOrderController), PAS le chemin KDS.
- Le test de couverture (commit caa70a6d3, `KdsSyncTimingExposureE2ETest`) tape `/api/admin/pos-order/change-status/` → VERT sur le mauvais chemin. GREEN ≠ correct.
- Preuve DB: 0/2807 orders ont un timestamp timing; 2354 orders ont atteint status>=PREPARED avec `prepared_at`=NULL. (les orders existants pré-datent le heal, mais le chemin KDS ne le posera jamais non plus).

### 3. actual_prep_seconds exposé — PARTIEL (clé exposée, valeur toujours null sur chemin KDS)
- `KDSOrderDetailsResource:51-52` calcule `actual_prep_seconds = prepared_at.diff(accepted_at)` seulement si les deux sont non-null.
- Live sync payload: la clé `actual_prep_seconds` + `accepted_at_iso` sont **présentes** dans chaque entrée (structure OK) mais **valeur=null** pour les 23 orders du board (car chemin KDS ne pose pas le timing → cf. #2).
- Conséquence: le "socle analytique productivité" ne produit AUCUNE donnée dès qu'un chef bump depuis le KDS.

### 4. Version-gate avance (updated_at) — OK
- `computeOrderVersion()` = `updated_at->getTimestamp()`. Live: version=1783016584 == updated_at unix 1783016584 (match exact).
- `changeStatus()` fait `$locked->save()` → `updated_at` touché → version avance à chaque bump → refetch version-gate correct.
- Note (non-bug, documentée dans le code): TODO status_changed_at + cache sync 5s sert du stale à un poll same-since (client réel avance `since`).

### 5. Format symbolique cuisine (parité PHP↔JS) — OK
- `tests/Unit/Hardware/KitchenSymbolPhpJsParityTest` = **5/5 PASS** (meat/sauce/crudité symbols match, print order match, tables non-empty).
- SSOT: `KitchenTicketSymbolicFormatter.php` (print) ↔ `kdsSymbolic.js` (écran) restent en parité.

### 6. OSS reflète les statuts — OK
- `OrderStatusScreenOrderService::list()` filtre `whereIn('status', [PREPARING, PREPARED])` + allowlist fail-closed order_type [KIOSK, TAKEAWAY] → reflète bien les statuts cuisine côté mur client.

### 7. Zéro-doublage (1 commande = 1 entrée) — OK
- `list()` retourne des `Order` models (1 ligne/order), pas des items. Live: slice board 50 rows → 50 ids distincts (aucun doublon).
- Le board d'items (`orderItems()`) fusionne par hash (item_id+variations+extras+addons+instruction+allergens) — split allergènes intentionnel (food safety). Distinct des cards.

## Défaut confirmé
**P2 — Kitchen timing jamais instrumenté sur le chemin KDS réel** (`app/Services/KitchenDisplaySystemOrderService.php:451-452`). Le bump board KDS ne pose pas accepted_at/preparing_at/prepared_at → `actual_prep_seconds` toujours null en usage réel. Le heal + test 2026-07-03 ne couvrent que `pos-order/change-status` (OrderService). Non-fiscal, non-bloquant fonctionnel (board/statuts/OSS OK) → V1 LOCAL analytics.

Fix proposé: dupliquer le bloc first-write-wins de `OrderService.php:2213-2218` dans `KitchenDisplaySystemOrderService::changeStatus` juste avant `$locked->save()` (ligne 451), mappé sur `$newStatus` (ACCEPT→accepted_at, PREPARING→preparing_at, PREPARED→prepared_at). Additif, hors zone gelée. Étendre `KdsSyncTimingExposureE2ETest` à `/api/admin/kds-order/change-status/`.
