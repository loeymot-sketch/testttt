# Round 2 — Attaque Sync/Snapshot : la vraie commande 5179

**Verdict : HOLD** (la défense tient — prouvé). Aucune faille reproductible.
**Sévérité : NONE.**

Cible : commande borne réelle **5179** (PAID, fiscal 2574). Re-attaque du rendu/sync
sur code ACTUEL (heals round-1 `10e462149` + `4fe7c2a7f` inclus).

---

## Données réelles (foodking_e2e, SELECT only)

`orders` 5179 : `status=8` (PREPARED), `payment_status=5` (PAID), `order_type=10`
(TAKEAWAY), `pos_payment_method=1`, `is_advance_order=10` (Ask::NO), `source_surface=kiosk`,
`source=5`, `order_serial_no=2606265179`, `fiscal_sequence_no=2574`, `branch_id=1`,
`queue_number=A0001`, `created_at=2026-06-26 14:21:20`, `updated_at=2026-06-26 15:28:31`,
`order_datetime=2026-06-26 14:21:20`, `token=NULL`.

`order_items` (1 ligne) : `id=4937, item_id=52 (Coca-Cola 33cl), quantity=1, price=1.90,
total_price=1.90, item_variations=[], item_extras=[], allergens_snapshot=[]`.

`composition_snapshot` (lu en DB) :
```json
{"lines": [], "addons": [], "extras": [], "captured_at": "2026-06-26T14:21:20+02:00", "schema_version": 1}
```

---

## (a) composition_snapshot figé/cohérent — OK

`captured_at=2026-06-26T14:21:20+02:00` == `created_at` → **figé à la création** (NF525 SSOT).
`lines/addons/extras` vides = correct pour un Coca-Cola (produit simple, 0 composition).
`schema_version=1`. Aucune incohérence, aucune valeur orpheline.

## (b) Rendu KDS lisible (pas blanc/inversé) — OK

`app/Http/Resources/OrderItemResource.php:33,73-80` (chemin réel de 5179 via
`KDSOrderDetailsResource:50`) et `app/Http/Resources/KDSOrderItemsResource.php:67-89`
(items-board) : `lines` vide → fallback legacy `item_variations`=`[]` → `[]`. Pas de
ligne fantôme, pas d'inversion groupe/valeur (il n'y a aucune ligne à inverser).

**Preuve — render réel de 5179 via `KDSOrderDetailsResource` (tinker READ-ONLY, foodking_e2e) :**
```
item_name="Coca-Cola 33cl" qty=1 total=1.9
item_variations=[] item_extras=[] item_addons=[]
composition_snapshot={"lines":[],"addons":[],"extras":[],"captured_at":"2026-06-26T14:21:20+02:00","schema_version":1}
```
`item_name` résolu (non blanc), arrays propres. Note : item 52 existe toujours en DB,
donc `orderItem?->name` (nom live) résout bien.

## (c) Une commande PAID apparaît-elle correctement (ou disparaît) du KDS/OSS — OK, apparaît

- **KDS board** (`KitchenDisplaySystemOrderService::list` + `KitchenReleaseRule`) :
  `status=8 PREPARED ∈ visibleStatuses [4,7,8]` ✓ ; `applyBoardReleaseFilter` admet
  `payment_status=5 PAID` ✓ ; fenêtre `order_datetime` aujourd'hui + `is_advance_order=Ask::NO`
  ✓. **Repro SQL** du filtre board → verdict `VISIBLE_ON_KDS_BOARD`.
- **KDS history** (`historyToday`, `:221-250`) : `PREPARED ∈ [PREPARED,OUT,DELIVERED]` +
  `updated_at` aujourd'hui ✓ → présent.
- **KDS sync delta** (`app/Services/KdsSyncService.php:50,96-117`) : `PREPARED ∈ activeStatuses`
  ✓ ; pas dans `deleted_ids` (inactiveStatuses = [DELIVERED,CANCELED,REJECTED]) ✓.
- **OSS** (`OrderStatusScreenOrderService::list` `:45-63`) : `order_type=10 TAKEAWAY ∈
  [KIOSK,TAKEAWAY]` ✓ ; `queue_number=A0001` non-null ✓ ; `status=8 PREPARED ∈ [PREPARING,
  PREPARED]` ✓ ; aujourd'hui + non-advance ✓ → apparaît sur le mur client (TTL 8h
  `oss.stale_window_hours` — comportement voulu, pas une perte).

Aucune disparition incorrecte. Note : KDS items-board (`orderItems()`, `itemBoardStatuses
=[ACCEPT,PREPARING]`) n'affiche PAS 5179 (PREPARED) — par design (l'item quitte le poste
de prépa une fois prêt) ; 5179 reste visible via board complet + history + OSS + strip
« Récemment servies ».

## (d) Payload KdsOrder a-t-il created_at_iso/updated_at/version — OK

`KDSOrderDetailsResource` expose `created_at_iso` (`:33`) et `updated_at` (`:37`).
Le champ **`version`** N'EST PAS dans `KDSOrderDetailsResource` mais est **enrichi côté
endpoint sync** : `app/Services/KdsSyncService.php:121-130` ajoute
`version => computeOrderVersion($model)` = `updated_at->getTimestamp()` (`:174-181`).
C'est l'endpoint `/api/admin/kds-order/sync` que consomme le client
`resources/js/services/KdsSyncService.js:145,176`. L'endpoint `index` (full-load) n'a pas
`version` mais n'alimente jamais la `_versionMap` (peuplée seulement dans le handler sync,
JS `:185`) → la première vue n'est jamais gated (`previousVersion===undefined`). Aucune
couture cassée.

**Preuve — render réel de 5179 :** `created_at_iso=2026-06-26T14:21:20+02:00`,
`updated_at=2026-06-26T15:28:31+02:00`, `version=1782480511` (= unix de 15:28:31 +02:00).
Les 3 champs présents et cohérents.

---

## Heal round-1 dans mon domaine (vérifié, non régressif)

`10e462149` P3 KDS : clamp 8h sur `recentlyServed()`
(`resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:237-258`). Vérifié :
`this.now=Date.now()` ms (ticker 1s `:171,295`) et `Date.parse(updated_at)` ms → unités
cohérentes ; ISO8601 `+02:00` parsé correctement (pas de dérive TZ) ; **fail-safe** :
`updated_at` manquant/illisible → exclu du strip (acceptable ; jamais présent en pratique,
`KDSOrderDetailsResource:37` toujours set). 5179 (`updated_at` 15:28:31) reste affiché
jusqu'à 23:28, puis sort du strip vif (toujours en history) — comportement voulu. Pas de
perte de commande PAID, chaîne fiscale intacte.

## Dette technique connue (NON un nouveau finding)

`version = updated_at` en **secondes** (`KdsSyncService.php:180`) : deux écritures dans la
même seconde → même version → 2ᵉ delta gated. **Déjà documenté** (TODO F-03/D-03bis
`:165-172`), auto-réparé au poll complet suivant, non exploitable sur 5179 (état terminal
PREPARED). Sévérité basse, déféré amont. Ne pas re-surfacer.

---

## Evidence / repro

- `mysql -u root foodking_e2e` : `composition_snapshot`, header 5179, repro filtre board
  (`VISIBLE_ON_KDS_BOARD`).
- Render réel via `KDSOrderDetailsResource` + `computeOrderVersion` (tinker READ-ONLY,
  `DB_DATABASE=foodking_e2e`, 0 écriture).
- `php artisan test --filter KdsSyncControllerTest` → **8/8 PASS** (since-filter,
  deleted_ids, branch-isolation, server_now monotonic, cache per-branch).

## Lentille

Snapshot-SSOT + parité de surfaces (board/history/sync/OSS) + alignement payload↔consommateur
JS. Le cas 5179 est le cas « simple » (0 composition) : aucune ligne à inverser/blanchir,
donc la classe de bug doublure/inversion est structurellement absente ici.

## Reco

Aucune. HOLD. La défense tient pour 5179 sur les 4 axes (a/b/c/d).
