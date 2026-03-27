# Configuration Temps Réel — FoodKing / Soketi

## Architecture

```
[Vue Frontend] ←→ [Soketi WebSocket :6001] ←→ [Laravel Backend]
     ↑                                               ↑
  Echo.private()                          ShouldBroadcastNow
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

## Auth canaux privés

- Route : `POST /api/broadcasting/auth`
- Middleware : `auth:sanctum`
- Token : Bearer injecté automatiquement par `_refreshEchoAuth()` après login
- Autorisation : `routes/channels.php` — admin = toutes branches, staff = branche propre, kiosk = branche machine

## Queue

`QUEUE_CONNECTION=sync` est suffisant car tous les events broadcast utilisent `ShouldBroadcastNow`
(exécution synchrone dans la requête HTTP, pas de worker nécessaire).

Pour les notifications FCM (futures), passer à `QUEUE_CONNECTION=database` :
```bash
php artisan queue:table
php artisan migrate
php artisan queue:work --daemon
```
