# V3-DEPTH — Aucun UNIQUE sur `orders.transaction_id` → double commande Uber concurrente

- **Cible** : `app/Http/Controllers/Webhook/UberWebhookController.php:34-101` (idempotence) + `110-116, 123-165` (`createFromUber`)
- **Angle** : 4 Concurrency-idempotence / 10 Zero-doubling
- **Sévérité revendiquée** : P2
- **Verdict** : **CONFIRMED (P2)**

## Repro rejouée (LECTURE SEULE, LIVE foodking_e2e)

1. `SHOW INDEX FROM orders` filtré `Column_name='transaction_id'` → **AUCUN index** (retour vide, "done").
   → pas de UNIQUE, pas d'index sur `orders.transaction_id`.
2. Le seul `UNIQUE(transaction_id)` du schéma est sur `pending_payment_confirmations`
   (`uniq_pending_payment_transaction_id`, migration `2026_05_08_120000`) — **autre table**, ne
   protège pas `orders`.
3. `createFromUber()` dédupe par **read-then-write non atomique** :
   `Order::withoutGlobalScopes()->where('transaction_id','uber:'.$id)->first()` (L113) puis
   `DB::transaction(fn => (new Order)->save())` (L123+). Le SELECT est un **read simple** :
   `grep lockForUpdate|Cache::lock|FOR UPDATE` → **0 occurrence**. Aucun verrou.
4. `webhook_events` a bien `UNIQUE(provider, webhook_id)` (`uk_webhook_provider_id`, vérifié live),
   MAIS l'idempotence webhook **ne bloque pas la concurrence** (voir ci-dessous).

## Deux chemins de double-création confirmés

### Chemin A — event_id distincts, resource_id identique (scénario revendiqué)
`webhookId = event_id` (1er du `??`), `uberOrderId = meta.resource_id`. Deux events avec
`event_id` différents mais même `resource_id`, tous deux `event_type` contenant `'order'`
(filtre large `str_contains(strtolower($eventType),'order')`, L73) :
- webhook_ids différents → les 2 inserts `webhook_events` réussissent (pas de collision UNIQUE).
- les 2 appellent `createFromUber(mêmeUberOrderId)` en parallèle.
- les 2 SELECT `transaction_id='uber:X'` → null (aucun encore commité).
- pas de UNIQUE sur `orders.transaction_id` → **2 INSERT réussissent → 2 commandes**.

### Chemin B — MÊME event_id rejoué en chevauchement (aggravé par le 503-rejeu de F1)
Le garde d'idempotence a une **faille de fall-through** (L54-70) :
```
$event = SELECT webhook_events ...
if ($event && $event->status === 'processed') return already_processed;  // seul return early
if (! $event) { INSERT (status=pending); }                              // insert SEULEMENT si absent
// ... tombe TOUJOURS dans createFromUber()
```
Un rejeu (503 → Uber renvoie le MÊME event_id, ou livraison at-least-once dupliquée) qui arrive
pendant que la 1ʳᵉ requête est encore dans `createFromUber` (fenêtre = HTTP `fetchOrder` vers Uber,
~100-500 ms) voit `$event.status='pending'` → **ni return early ni insert** → tombe directement
dans `createFromUber`. Les 2 requêtes concurrentes, MÊME uberOrderId, SELECT→null→INSERT → **2
commandes**. Le UNIQUE(provider,webhook_id) ne protège PAS ce cas : la 2ᵉ requête ne tente aucun
insert (elle voit la ligne pending), donc aucune violation de contrainte ne l'arrête. Aucun claim
atomique (`UPDATE ... SET status='processing' WHERE status='pending'`) n'existe ; `markProcessed`
n'est appelé qu'à la FIN (L80).

## Impact
Zéro-doublage NON garanti : sous livraison dupliquée/concurrente Uber (comportement at-least-once
documenté + rejeu 503 de F1), une seule commande Uber peut générer **2 commandes internes → 2
tickets cuisine → 2 plats préparés**. Distinct du mythe « double ticket = cache/service-worker » :
ici ce sont **2 lignes `orders` réelles** créées par un défaut de code (dédup non atomique + pas de
UNIQUE backstop), pas un artefact de rendu.

## Pourquoi P2 (pas P1)
Intégration Uber = fondation, Production Access « EN ATTENTE », endpoint pas encore live
(« À VALIDER EN LIVE »). Fenêtre de course étroite (latence fetchOrder) et exige duplication de
livraison. Défaut structurel réel et à corriger avant mise en prod Uber, mais impact V1 LOCAL
actuel nul tant que le webhook n'est pas branché → P2 approprié.

## Correctif suggéré (non appliqué — read-only)
Ajouter `UNIQUE(transaction_id)` sur `orders` (partiel/nullable-safe) comme backstop, OU claim
atomique du webhook (`UPDATE webhook_events SET status='processing' WHERE webhook_id=? AND
status IN('pending','failed')`, ne traiter que si `affectedRows=1`), OU `lockForUpdate` +
`Cache::lock('uber:'.$id)` autour de `createFromUber` (pattern déjà utilisé pour
`fiscal_sequence_no`).
