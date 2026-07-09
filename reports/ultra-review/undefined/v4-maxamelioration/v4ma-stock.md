# v4ma-stock — Attaque STOCK / inventaire (decrement race, stock négatif, availability bypass)

HEAD 61e9ea7b7 + working-tree. DB foodking_e2e. Posture refute-by-default, repro LIVE via tinker (transactions rolled back = zéro écriture nette).

## Verdict : IMPROVABLE — 1 finding P2 reproduit LIVE.

---

## FINDING P2 — 86 puis dé-86 d'un supplément/variation le rend DÉFINITIVEMENT incommandable (422 stock_rupture fantôme)

**Fichier** : `app/Services/Menu/AvailabilityService.php:536-565` (`toggleStockable`) ×
`app/Services/Stock/ChoiceAvailabilityResolver.php:286-288` (`availabilityFromLevel`).

**Cause** : extras (`ItemExtra`) et variations (`ItemVariation`) n'ont PAS de suivi de
stock réel en V1 — leur 86 passe par la colonne `manual_unavailable_reason` de
`stock_levels`. Or `toggleStockable()` crée la ligne `stock_levels` avec `on_hand => 0`
(ligne 541). Quand le manager RÉ-ACTIVE (available=true), la méthode remet
`manual_unavailable_reason = null` (ligne 563) **mais laisse `on_hand` à 0**.

Ensuite `availabilityFromLevel()` (appelé par `assertSelectionsOrderable`, elle-même
appelée par `PricingService` = SSOT prix/commande) interprète `on_hand=0` sans raison
manuelle comme **`stock_rupture` → is_available=false**. Le supplément reste bloqué au
moment de la commande (422) alors que :
- l'API `POST /api/admin/menu/availability/extra/toggle` renvoie `is_available: true`,
- l'event `ItemExtraAvailabilityChanged(isAvailable: true)` est broadcasté,
- `catalog-overview` (qui ne teste QUE `manual_unavailable_reason != null`) l'affiche
  comme disponible (vert).

→ Incohérence tri-surface : dashboard admin = dispo, kiosk = 422 « Supplément indisponible ».
Impact opérationnel réel : « plus de jambon » → 86 → livraison arrive → dé-86 → le
client ne peut toujours PAS ajouter le jambon, sans message d'erreur côté manager.
Le même chemin frappe `toggleVariation` (viandes, tailles…).

Le path item-niveau (`AvailabilityService::toggle` → `ItemBranchAvailability`, booléen
`is_available`) n'est PAS touché — le bug est spécifique aux extras/variations qui
transitent par `stock_levels.on_hand`.

**Repro LIVE (tinker, rollback)** :
```
BASELINE (aucune ligne stock_levels) : ORDERABLE
toggleExtra(id=1 "Jambon de dinde", branch=1, available=false, 'manual_86')  → on_hand=0 reason=manual_86
toggleExtra(id=1, branch=1, available=true, null)                            → on_hand=0 reason=NULL
assertSelectionsOrderable(extra id=1) → BLOCKED(422) "Supplément ID 1 indisponible pour cette branche (stock_rupture)."
```
(`availabilityFromLevel` : ligne existe, manual=null, on_hand=0 → stock_rupture.)

**Fix proposé** (fichier NON-frozen `AvailabilityService.php`) : dans `toggleStockable()`,
sur ré-activation (`available=true`), restaurer l'état baseline « ligne absente = dispo »
pour un modèle 86-manuel-seul : si `on_hand <= 0 && reserved <= 0`, **supprimer la ligne
`stock_levels`** au lieu de la laisser à `on_hand=0/reason=null` ; sinon (`on_hand>0`,
donc réellement stock-tracké) simplement effacer la raison manuelle. Alternative plus
défensive : dans `availabilityFromLevel()` / `isStockableAvailable()`, ne traiter
`on_hand=0` comme rupture que si la ligne a un jour porté un suivi de stock réel
(ex. colonne `is_stock_tracked` ou `threshold_low` non nul) — mais le delete-on-reenable
est le patch chirurgical minimal qui rétablit exactement la baseline testée.
Ajouter un test régression : 86 → un-86 d'un extra → `assertSelectionsOrderable` passe.

---

## Attaques menées qui n'ont RIEN donné (le stock est sûr sur ces axes)

- **Stock négatif via commande** : `StockService::mutateForOrderInTransaction` prend un
  `lockForUpdate()` par ligne `stock_levels`, teste `on_hand < qty` AVANT le save et lève
  `StockUnavailableException` sinon (l.111-113). Deux commandes concurrentes du dernier
  article se sérialisent sur le verrou → pas d'`on_hand` négatif. DB live : 0 ligne
  `on_hand < 0`.
- **Double-décrément (retry POST)** : idempotence via `stock_movements.idempotency_key`
  (sha1 reason|order|line_uid|stockable) l.102-104, + garde per-order `Cache::add` SETNX
  dans `AvailabilityService::decrementForOrder` (quota journalier) l.321-324. Rejeu = no-op.
- **Quota journalier (max_daily_qty)** : l'UPDATE conditionnel atomique
  `CASE WHEN daily_consumed_qty+qty > max_daily_qty THEN max_daily_qty ...` (l.349-351)
  plafonne le compteur ; flip 86 CAS-style émis une seule fois. `$qty` casté `(int)` →
  pas d'injection SQL.
- **Oversell (commande d'un article en rupture acceptée)** : le décrément tourne
  after-commit + isolé (`DecrementStockOnOrderCreated` log+event, ne re-throw pas) — la
  commande EXISTE même si le stock est insuffisant. C'est la **politique WG-2 documentée**
  (l'Outbox SSOT + listeners frères doivent survivre ; la cuisine tranche). Pour V1 LOCAL
  mono-poste ce n'est pas un P0/P1. Non retenu.
- **Release / refund** : `requireOriginalDecrement` exige la présence du mouvement
  `order_created` avant de relâcher (l.106-108, 402-404), clé de release inclut
  `released_qty:delta` → pas de sur-libération / double-release. `min(requestedQty,
  remainingQty)` borne la libération partielle.
- **Endpoints stock admin** : `scan-rupture/run`, `low-alerts`, `catalog-overview`
  gardés `permission:items_show/items_create` + `authorizeBranchScope` + double garde
  `abort_unless can('items_create')` en prod. Branch-scope enforced sur toggles
  (`resolveScopedBranchIds`, 403 hors scope). Pas de bypass RBAC/branche trouvé.
