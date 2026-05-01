# Configuration Temps Réel — FoodKing / Soketi

> VA-SYS-09 note: this is legacy setup documentation for the broadcaster.
> The canonical runtime sync contract is now `docs/sync/CATALOG_COMPOSER_DATA_FLOW.md`
> and `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md`.
> Core order/catalog events are persisted in `domain_events` and dispatched by
> `DispatchDomainEventsJob`; do not reintroduce direct `ShouldBroadcastNow` for
> core FoodKing sync events.

## Architecture

```
[Vue Frontend] ←→ [Soketi WebSocket :6001] ←→ [Laravel Backend]
     ↑                                               ↑
  Echo.private()                          domain_events outbox + DispatchDomainEventsJob
  authEndpoint: /api/broadcasting/auth    OrderCreated / OrderStatusChanged
```

## Prérequis

- Soketi installé : `npm install -g @soketi/soketi`
- `.env` configuré (voir ci-dessous)

## Configuration `.env`

```env
BROADCAST_DRIVER=pusher

PUSHER_APP_ID=app-id
PUSHER_APP_KEY=app-key
PUSHER_APP_SECRET=app-secret
PUSHER_HOST=127.0.0.1
PUSHER_PORT=6001
PUSHER_SCHEME=http
PUSHER_APP_CLUSTER=mt1

VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_HOST="${PUSHER_HOST}"
VITE_PUSHER_PORT="${PUSHER_PORT}"
VITE_PUSHER_SCHEME="${PUSHER_SCHEME}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
```

> En production, remplacer les clés par des valeurs sécurisées (min 32 chars).

## Démarrage Soketi

```bash
# Depuis la racine du projet (utilise soketi.json)
soketi start --config=soketi.json

# Ou directement avec les variables d'env
SOKETI_DEFAULT_APP_ID=app-id \
SOKETI_DEFAULT_APP_KEY=app-key \
SOKETI_DEFAULT_APP_SECRET=app-secret \
soketi start --port=6001
```

## Vérification

```bash
# Tester la connexion Laravel → Soketi
php artisan tinker --execute="
\$p = new \Pusher\Pusher(
    config('broadcasting.connections.pusher.key'),
    config('broadcasting.connections.pusher.secret'),
    config('broadcasting.connections.pusher.app_id'),
    config('broadcasting.connections.pusher.options')
);
echo json_encode(\$p->trigger('test', 'test', ['ok' => true]));
"
# Résultat attendu : {"ok":true}
```

## Composants qui utilisent le temps réel

| Composant | Canal | Événements écoutés |
|-----------|-------|-------------------|
| `KitchenDisplaySystemComponent` | `branch.{id}` | `OrderCreated`, `OrderStatusChanged` |
| `PreparingAndReadyComponent` (OSS) | `branch.{id}` | `OrderCreated`, `OrderStatusChanged` |
| `PosComponent` | `branch.{id}` | `OrderCreated`, `OrderStatusChanged` |
| `KioskWaitingComponent` | `branch.{id}` | `OrderStatusChanged` |

Tous ont un **fallback polling 30s** si Echo n'est pas disponible.

## Fallback polling explicite

Quand `BROADCAST_DRIVER` est absent, `null`, `log`, ou que la cle Pusher front
`MIX_PUSHER_APP_KEY` n'est pas exposee, le temps reel est considere indisponible.
Les surfaces POS/KDS/OSS doivent alors rester correctes via REST polling, sans
supposer qu'un evenement WebSocket arrivera.

Configuration serveur:

```env
BROADCAST_POLLING_FALLBACK_ENABLED=true
BROADCAST_POLLING_FALLBACK_MS=30000
BROADCAST_POLLING_FALLBACK_HINT_WHEN_OFF=true
```

Contrat front:

- `resources/js/store/modules/posOrder.js` expose `realtimeFallback` et
  `realtimeFallbackHint`.
- Si le broadcast est off, le hint operateur indique que l'ecran fonctionne en
  rafraichissement automatique.
- Le polling ne change pas les invariants: `branch_id` reste cote API, et le
  backend demeure la source de verite.

## Auth canaux privés

- Route : `POST /api/broadcasting/auth`
- Middleware : `auth:sanctum`
- Token : Bearer injecté automatiquement par `_refreshEchoAuth()` après login
- Autorisation : `routes/channels.php` — admin = toutes branches, staff = branche propre, kiosk = branche machine

## Queue

Core FoodKing events do not rely on direct `ShouldBroadcastNow`; they use the
durable outbox pattern documented in `docs/OUTBOX_PATTERN.md`. A queue worker
must process `DispatchDomainEventsJob` outside the request path.

Pour les notifications FCM (futures), passer à `QUEUE_CONNECTION=database` :
```bash
php artisan queue:table
php artisan migrate
php artisan queue:work --daemon
```
