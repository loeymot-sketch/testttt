# V3-depth verdict — Uber item non résolu → item_id NULL → rollback → commande payée perdue

- **Cible** : `app/Http/Controllers/Webhook/UberWebhookController.php:146-157`, `app/Services/Uber/UberOrderMapper.php:80-102`, `config/uber_menu_map.php`
- **Angle** : 2 Failure-path / 8 Degradation
- **Sévérité revendiquée** : P1
- **Verdict** : **DOWNGRADE → P2** (mécanisme entièrement reproduit, mais inerte en V1 LOCAL)

## Mécanisme — REPRODUIT (LIVE, lecture seule)

1. `config/uber_menu_map.php` : `by_title` et `by_uber_id` **entièrement commentés** → `config('uber_menu_map.by_title')` renvoie `array(0){}` (vérifié tinker).
2. `UberOrderMapper::resolveItemId()` retourne **NULL** pour un titre non présent au catalogue (fallback exact puis LIKE échoue) :
   - `resolveItemId('Menu Maxi Best Of XL Uber Special','')` → `NULL`
   - `resolveItemId('ZZZ Nonexistent Uber Combo 12345','')` → `NULL`
3. `order_items.item_id` : `SHOW COLUMNS` LIVE → `Null: NO`, `Key: MUL` (migration `2022_11_17_110832` : `foreignId('item_id')->constrained('items')`). Aucun observer/défaut sur OrderItem ne rattrape un `item_id` null (grep observers = néant).
4. Donc `OrderItem::forceFill(['item_id'=>null,...])->save()` (controller:150) → violation NOT NULL/FK → `QueryException` levée **dans** `DB::transaction` (controller:123) → rollback (Order + items) → propagée au `catch` (controller:85) → 503 `retry_requested`.
5. L'échec est **déterministe** (config vide, résolution null à chaque rejeu) → les 5 tentatives échouent → 200 `error_gave_up`. La commande Uber **payée n'est jamais créée** (absente KDS/cuisine) ; seule trace = `webhook_events.status=failed` (monitoring **non branché**, cf. commentaire ligne 92-93). La mitigation [UBER-RETRY 2026-07-02], pensée pour ne pas perdre une commande payée, **ne protège pas** ce mode d'échec déterministe.

## Pourquoi DOWNGRADE (pas P1) — impact NUL en V1 LOCAL aujourd'hui

- `config('uber.webhook_signing_secret')` = **vide** → `signatureValid()` fail-closed → **401 sur tout** : le webhook ne peut traiter AUCUNE commande actuellement.
- `config('uber.client_id')` = vide ; **Production Access Uber EN ATTENTE** (MEMORY) → aucun trafic webhook live possible.
- `config/uber_menu_map.php` est vide **par design documenté** (« À remplir avec la vraie carte Uber de l'owner ») : remplir la carte est une étape **obligatoire de go-live**, pas un bug de code.

Le défaut résiduel réel = **absence de dégradation gracieuse** : un article Uber non mappé (oubli de mapping, nouvel article ajouté côté Uber) fait crasher + perdre une commande **payée** au lieu de la mettre en quarantaine (item placeholder + flag revue manuelle). C'est un durcissement go-live légitime (P2), pas un P1 actif dans V1 LOCAL (Uber inerte : pas de secret, pas de Production Access).

## Recommandation (hors périmètre correctif ici)
Résolution null → item placeholder « Article Uber non mappé » + `instruction` = titre brut + alerte `webhook_events.status=failed` réellement supervisée, afin qu'une commande payée soit TOUJOURS créée/visible en cuisine plutôt que silencieusement perdue.
