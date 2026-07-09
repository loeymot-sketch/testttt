# ULTRA-INTERSECTIONS — `composition_snapshot` × 5 consommateurs (identité stricte)

HEAD `48050af80` · DB `foodking_e2e` · read-only · slug `ui-composition-snapshot`

## Fonction partagée
`composition_snapshot` (état figé de la commande, produit par `CompositionSnapshotBuilder::build`
et par `UberOrderMapper::map`), consommé par : KDS (items-board + cards), OSS, ticket ESC/POS
(client + cuisine), fiscal (montants), refund (copie), Uber (production).

## Chemins vérifiés (COHÉRENTS)
1. **KDSOrderItemsResource** (`resolveVariationsForKds/ExtrasForKds/AddonsForKds`) — snapshot-first, fallback legacy. ✅
2. **OrderItemResource** (OSS/POS, `resolveVariationsForApi/…`) — même logique, même sortie. Prouvé LIVE (OI #5216) : `item_variations` KDS == OSS byte-identiques. ✅
3. **OrderReceiptEscPosRenderer** — ticket cuisine via `KitchenTicketSymbolicFormatter` ; ticket client via `lines()`. Lecture snapshot `lines/extras/addons` cohérente. `$oi->name ?? catalog ?? 'Article'` (name NULL → fallback catalogue "Tacos L" OK). ✅
4. **KitchenTicketSymbolicFormatter (PHP) ↔ kdsSymbolic.js (KDS écran)** — parité STRICTE des tables (MEAT/SAUCE/CRUDITE identiques), extraction group/value identique (`kdsVariationGroupValue`), free/paid `unit_price ?? line_total` identique, isMenuItem `\bmenu\s*\(|\bformule\b` identique. LIVE OI #5216 → PHP mainLine `G | TACOS | L | K Mex | MAY` == symboles JS attendus. ✅
5. **RefundWithCounterEntryService** — copie `composition_snapshot` verbatim (ligne 163). ✅
6. **fiscal** — ne lit pas le contenu du snapshot (agrège `total_price`/tax colonnes) ; identité = non applicable. ✅

Scan LIVE 400 derniers order_items : both(legacy+snapshot)=191, snapOnly=0, legacyOnly=0, neither=209 → **pour le flux normal (POS/kiosk) legacy ET snapshot sont co-écrits**, donc toute lecture legacy-vs-snapshot converge.

## INCOHÉRENCE confirmée (P3 latent — Uber déféré)
**KitchenDisplaySystemOrderService::orderItems() ligne 567-588** — la clé de fusion (groupBy)
mélange deux sources :
- `variations`/`extras` → colonnes LEGACY `item_variations`/`item_extras` (ligne 568-569)
- `addons` → `composition_snapshot.addons` (ligne 570)

Tous les AUTRES consommateurs lisent variations/extras **snapshot-first**. Pour une ligne
snapshot-only (Uber : `UberWebhookController:146-156` écrit `composition_snapshot` mais PAS
`item_variations`/`item_extras`), deux items même `item_id` avec extras snapshot DIFFÉRENTS
produisent la MÊME clé → fusionnés en « xN » n'affichant que la compo du 1er → la sauce/viande
du 2e client DISPARAÎT du board cuisine (sécurité alimentaire / exactitude).

**Repro LIVE** (simulation clé sur 2 items Uber-shaped, sauces Samourai vs Andalouse) :
```
keyA == keyB (variations="[]",extras="[]",addons="[]") → SAME KEY -> BUG
```
Non-impactant flux normal (legacy co-peuplé, scan 0 snapOnly) ; latent tant qu'Uber pas live.

**Fix proposé** : lire snapshot-first dans la clé, en miroir de `resolveVariationsForKds` :
`data_get($item,'composition_snapshot.lines', $item['item_variations'])` et `…extras`.

## Verdict
Identité du snapshot **tenue sur tous les chemins du flux V1 live** (POS/kiosk/caisse/borne).
Seule fissure = clé de fusion KDS legacy-vs-snapshot, exclusivement sur lignes snapshot-only
(Uber, déféré) → P3.
