# Synchronisation & temps réel — direct et indirect

## 1. Ce que voit l’utilisateur (direct)

- **REST** : toutes les surfaces rafraîchissent via appels API.
- **Echo (WebSocket)** : souscription `private-branch.{branch_id}` après auth Sanctum sur `/api/broadcasting/auth` — voir `resources/js/bootstrap.js`.
- **Polling ~30 s** : filet de sécurité sur KDS, OSS, POS si Echo indisponible.

## 2. Ce qui est caché (indirect)

- **`OrderCreated`** / **`OrderStatusChanged`** : `ShouldBroadcastNow` → envoi synchrone vers Soketi (try/catch sur les dispatches dans les services pour ne pas casser la requête).
- **Listeners** sur ces événements :
  - `SendFcmOnOrderCreated` / `SendFcmOnOrderStatusChange` → jobs FCM.
  - `AwardLoyaltyPointsOnDelivery` → points fidélité sur certains statuts.
- **Événements Mail/SMS/Push** (`SendOrderPush`, etc.) : parallèles au WebSocket, déclenchés sur certains chemins `OrderService`.
- **`ItemAvailabilityChanged`** : mise à jour menu kiosk sans attendre le TTL cache ; broadcast sur **chaque branche active** (voir `app/Events/ItemAvailabilityChanged.php`).

## 3. Fichiers clés

| Fichier | Rôle |
|---------|------|
| `routes/channels.php` | Autorisation canal branche (kiosk limité à sa machine) |
| `app/Providers/BroadcastServiceProvider.php` | Route broadcast + `auth:sanctum` |
| `app/Events/OrderCreated.php`, `OrderStatusChanged.php` | Contrat payload WS |
| `reports/review/AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md` | Audit détaillé (chemins dispatch, gaps historiques) |

## 4. Configuration critique

- **`BROADCAST_DRIVER`** : défaut possible `null` → **aucun** message WS ; seul le polling reste.
- **`QUEUE_CONNECTION=sync`** : les jobs partent dans la même requête (latence).
- **Fallback polling POS** : `config/broadcasting.php` expose
  `polling_fallback.enabled`, `polling_fallback.interval_ms` et
  `polling_fallback.hint_when_off`. Le store `posOrder` expose
  `realtimeFallback` / `realtimeFallbackHint` pour afficher un hint operateur
  quand le broadcast est off et que l'ecran fonctionne en polling.

Valeurs par defaut du fallback:

```env
BROADCAST_POLLING_FALLBACK_ENABLED=true
BROADCAST_POLLING_FALLBACK_MS=30000
BROADCAST_POLLING_FALLBACK_HINT_WHEN_OFF=true
```

## 5. Incohérence documentaire connue

- **`docs/DEVICE_FLOW.md`** mentionne encore Firebase pour le POS à certains endroits ; le code actuel s’appuie sur **Echo + événements Laravel** et **FCM** pour une autre couche. En cas de doute, **le code et** `REALTIME_SETUP.md` font foi.

Voir aussi : [`../REALTIME_SETUP.md`](../REALTIME_SETUP.md).

## 6. Kiosk : paiement différé

Pour carte / ticket restaurant, **`OrderCreated`** peut être **retardé** jusqu’à confirmation paiement (`finalizePaidKioskOrder`) pour éviter d’afficher en cuisine des commandes non payées. C’est une règle **métier de synchro**, pas seulement UI.
