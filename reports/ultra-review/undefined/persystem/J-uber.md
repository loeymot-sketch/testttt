# Système J — UBER EATS · Ultra-review confirmation

HEAD audité (working-tree AS-IS) · date 2026-07-02 · verify-before-report STRICTE (lecture seule).

Fichiers : `app/Http/Controllers/Webhook/UberWebhookController.php`, `app/Services/Uber/UberOrderMapper.php`,
`app/Services/Uber/UberClient.php`, `config/uber.php`, `config/uber_menu_map.php`,
`routes/api.php:161`, `database/migrations/2026_05_09_120000_create_webhook_events_table.php`,
`tests/Feature/Uber/UberIntegrationTest.php`.

## Verdict : GREEN_WITH_NOTES

Intégration solide, cohérente, fail-closed. Aucun NOUVEAU P0/P1. Toutes les réserves = latentes
(Production Access Uber en attente) et déjà actées dans le code / les garde-fous.

## Claims confirmés

1. **HMAC fail-closed** — `signatureValid()` (UberWebhookController.php:174-187) : secret vide → `return false`
   (refuse tout), signature fournie vide → false, comparaison `hash_equals(hash_hmac('sha256',$raw,$secret), $provided)`.
   Réponse **401** `invalid_signature` (ligne 39-42). NB : la mission dit « 403 » ; le code renvoie 401 — correct
   pour un échec d'auth de signature. Test `webhook_refuse_une_signature_invalide` + `..._si_aucun_secret_configure` verts.

2. **Idempotence webhook_events** — lookup `provider=uber_eats + webhook_id` (ligne 54), court-circuit si
   `status==='processed'` (ligne 55-56, 200 `already_processed`), insert unique sinon (58-70). Renforcé par
   contrainte DB `UNIQUE(provider,webhook_id)` (migration:83) + **dédup AU NIVEAU COMMANDE** sur
   `transaction_id='uber:'.$id` (createFromUber ligne 113-116) contre 2 event_id distincts pour la même commande.
   Test `..._est_idempotent` vert.

3. **HEAL 503-retry** (UberWebhookController.php:89-98) — sur exception : `status=failed`, `attempts+1`, puis
   **503 `retry_requested` si attempts<5** (Uber rejoue → commande payée non perdue, dédup empêche le doublon),
   **200 `error_gave_up` au poison définitif** (≥5) pour ne pas boucler. Remplace bien l'ancien 200-on-fail.
   Colonnes `attempts`/`error_message` présentes au schéma (migration:71,74) — insert/update cohérents.

4. **Mapping → composition_snapshot** — `UberOrderMapper::map/mapLine` (OrderMapper.php:22-77) produit
   `item_id` (résolu par map titre/uber_id puis fallback nom DB) + `composition_snapshot{schema_version,source:'uber_eats',
   lines:[],extras,addons,uber_title}` réutilisé par ticket ESC/POS + KDS. Test `le_mapper_convertit...` vert
   (Tacos M ×2, total 12.90, extra Cheddar 0.90).

5. **forceFill total Uber (agrégateur correct)** — createFromUber (ligne 123-161) : `total/subtotal=mapped['total']`
   Uber tel quel (PAS de recalcul PricingService — canal séparé, prépayé), `source=WEB` + `source_surface='uber_eats'`,
   `status=ACCEPT` + `payment_status=PAID` (board-release KDS OK), non-fiscal par défaut. Enums vérifiés existants
   (Source::WEB=5, OrderType::DELIVERY=5, OrderStatus::ACCEPT=4, PaymentStatus::PAID=5). Colonnes orders
   (queue_number, order_serial_no, transaction_id, source_surface, subtotal) présentes.

**Tests : 5/5 verts** (`php artisan test tests/Feature/Uber` — 1.04s).

## Notes latentes (NON P0/P1 — Production Access en attente, déjà actées)

- **item_id non résolu** : `resolveItemId` peut retourner `null` (OrderMapper.php:101) ; `order_items.item_id` est
  `foreignId->constrained` (NOT NULL). Un produit Uber non mappé → save() FK-fail → catch → retry → give-up →
  commande perdue. Couvert par le garde-fou « Uber map vide (Production Access en attente) » : la table de mapping
  doit être remplie avant l'activation. À valider en live.
- **Insert hors try** (ligne 58-70 avant le `try` ligne 78) : deux webhooks concurrents même event_id → 2e insert
  viole l'UNIQUE → QueryException 500 non catché. **Auto-guérit** (Uber rejoue, la 2e fois la ligne existe → 200).
  Concurrence rare sur mono-poste ; cloud/scale ≠ P0/P1.
- **Give-up 200 sans alerte active** : le comment reconnaît « à brancher : superviser webhook_events
  provider=uber_eats status=failed ». Backlog monitoring, latent.
- **Doc drift bénin** : docblock UberWebhookController.php:22-23 dit encore « Répond TOUJOURS 200 … 401 seulement si
  signature invalide » — obsolète depuis le HEAL 503. Cosmétique.

Aucune écriture code/DB effectuée. Frozen §7 non touché.
