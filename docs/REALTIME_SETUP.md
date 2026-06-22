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

## Queue (post outbox refactor)

> [AUDIT-F-015 — 2026-05-08] La phrase "QUEUE_CONNECTION=sync est suffisant car
> tous les events broadcast utilisent ShouldBroadcastNow" qui figurait ici
> avant le refactor outbox a ete retiree. Les events broadcast NE sont PLUS
> `ShouldBroadcastNow` ; ils sont persistes en `domain_events` puis dispatches
> par `DispatchDomainEventsJob` via la queue `high`. Voir
> `app/Listeners/PersistOrderCreatedToOutbox.php` + `app/Jobs/DispatchDomainEventsJob.php`.

Core FoodKing events use the durable outbox pattern documented in
`docs/OUTBOX_PATTERN.md`. A queue worker MUST process
`DispatchDomainEventsJob` outside the request path.

### Production — REQUIRED

```env
QUEUE_CONNECTION=redis        # ou database (throughput inferieur)
BROADCAST_DRIVER=pusher       # ou soketi / ably (jamais null/log)
```

```bash
# Lancer 1 ou 2 workers en supervisord / systemd
php artisan queue:work --queue=high,default \
    --tries=6 --backoff=1,5,15,60,300 --daemon
```

Sans worker, les broadcasts s'accumulent en `domain_events` avec
`dispatched_at = NULL`. KDS / OSS / POS ne recoivent rien en realtime, seul
le polling 30s en filet de securite masque le defaut a l'oeil nu.

### Health checks et alerting (AUDIT-F-015)

`GET /api/health/ready` (port deploy probe) retourne **503** si :

- plus de 10 lignes `domain_events` sont stale (>30s, `dispatched_at = NULL`)
  -> worker probablement down ou en retard
- en production, `QUEUE_CONNECTION=sync` -> incompatible avec outbox
- en production, `BROADCAST_DRIVER` est `null` ou `log` -> realtime desactive

Cron toutes les minutes (`app/Console/Kernel.php`) :

```
foodking:outbox:rescue          # re-enqueue les events stuck quand le worker est UP
foodking:outbox:monitor          # alerte (Log::error + exit non zero) si stale > 10
```

`foodking:outbox:rescue` agit (re-queue), `foodking:outbox:monitor` alerte
(le rescue est silencieux si le worker entier est down — le monitor couvre
ce trou). Les deux sont complementaires, pas redondants.

### Dev / CI

`QUEUE_CONNECTION=sync` reste valide en dev / CI / Playwright local —
le job s'execute inline dans la requete HTTP, pas besoin de worker.
La gate production de `/health/ready` ne s'applique pas hors prod.

### Notifications FCM (futures)

Pour passer plus tard a `QUEUE_CONNECTION=database` (alternative a Redis) :

```bash
php artisan queue:table
php artisan migrate
php artisan queue:work --daemon
```
