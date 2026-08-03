# CONFIRMED (P3, latent) — KDS items-board merge key reads legacy for variations/extras but snapshot for addons/display

## Cible
`app/Services/KitchenDisplaySystemOrderService.php:567-588` (orderItems groupBy merge key)
vs display path `app/Http/Resources/KDSOrderItemsResource.php:67-89` (resolveVariations/ExtrasForKds = snapshot-first).

## Inconsistance prouvée (fonction partagée, 2 chemins migrés à moitié)
- Le DISPLAY (resource) a été migré snapshot-first (commentaire l.22-26 : « items-board was the ONLY KDS surface still reading the raw legacy item_variations/item_extras columns »).
- La MERGE KEY (service l.568-569) lit toujours les colonnes legacy `item_variations`/`item_extras` pour variations+extras, alors que le sous-clé `item_addons` (l.570) lit `composition_snapshot.addons`. Migration incomplète → clé de fusion incohérente avec ce qu'affiche la carte.

## Repro LIVE (tinker, read-only)
2 items Uber-shaped (item_id=97, item_variations=NULL, item_extras=NULL), composition_snapshot.extras différents (Samourai vs Andalouse), addons=[]:
- keyA === keyB (variations="[]" extras="[]" addons="[]") → SAME KEY
- groupBy → 1 seule ligne mergée, shown_extra=[{"label":"Samourai"}] → Andalouse (2e client) DISPARAÎT du board cuisine.

## Portée = latente (Uber non live)
- Scan LIVE 3139 order_items : `snapOnly_extras=0` (flux normal POS/kiosk/web co-écrit toujours item_extras legacy → clé diverge correctement).
- `source_surface` distincts : NULL/pos/kiosk/mobile/delivery/web — AUCUN `uber_eats`. `UberWebhookController:146-158` écrit `composition_snapshot`+`instruction` SANS item_variations/item_extras (legacy NULL) → seul producteur de lignes snapshot-only. Uber Production Access en attente (déféré « Uber go-live »).

## Fix (dirigé)
Miroir de resolveVariationsForKds dans la clé de fusion :
`$variations = data_get($item,'composition_snapshot.lines', $item['item_variations']);`
`$extras = data_get($item,'composition_snapshot.extras', $item['item_extras']);`
(garder fallback legacy). Aligne variations/extras/addons sur la même source SSOT snapshot.

## Verdict
CONFIRMED — inconsistance réelle et reproduite, impact latent (P3) jusqu'au go-live Uber. Sévérité inchangée.
