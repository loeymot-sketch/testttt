# TASK_V1_SYNC_BACKBONE_001 — Activation broadcast & queue production-ready

## Meta
- **Priority** : P0 (bloquant toute la vague 1)
- **Vague** : 1 — Synchro foundation
- **PRIMARY_MODEL** : Composer (routine config + adapter frontend)
- **TEST_STRATEGY** : `local-validation`
- **DEPENDS_ON** : (aucun)
- **BLOCKS** : TASK_V1_OUTBOX_001, TASK_V1_EVENT_CONTRACT_001, TASK_V1_OBS_HEALTH_CORR_001
- **Estimation** : 2 j-h

## Contexte

Aujourd'hui FoodKing a :
- `BROADCAST_DRIVER=null` par défaut → les events `ShouldBroadcastNow` sont **silencieusement ignorés**.
- `QUEUE_CONNECTION=sync` par défaut → tout job queué bloque la requête HTTP appelante.
- Aucun heartbeat client Echo → déconnexion WebSocket invisible côté surface (POS/KDS/Kiosk/OSS).

Sans cette task, aucun des autres travaux temps-réel de la V1 n'a de fondation fiable. C'est le pré-requis strict pour l'outbox, le contrat d'événement, et les tests Playwright.

## Acceptance Criteria
- [ ] `.env.example` force `BROADCAST_DRIVER=pusher` et `QUEUE_CONNECTION=redis`. Doc `.env.production` générée.
- [ ] Fail-fast boot : `App\Providers\AppServiceProvider::boot()` refuse de démarrer en `APP_ENV=production` si `BROADCAST_DRIVER=null` ou `QUEUE_CONNECTION=sync`. Exception explicite dans les logs de boot.
- [ ] Heartbeat Echo : ping émis toutes les 30s, banner discrète "Reconnexion…" sur POS/KDS/Kiosk/OSS si déconnexion > 5s. Reconnect exponentiel (1s, 2s, 4s, plafond 30s).
- [ ] Horizon (ou Supervisor) documenté : `docs/PRODUCTION_SETUP.md` contient : exemple systemd, exemple `.env.production` complet, commandes de diagnostic (`php artisan queue:work`, `php artisan horizon:status`).
- [ ] Test local : `php artisan config:show broadcasting` retourne `pusher` quand `.env` le déclare.
- [ ] Test manuel : couper le réseau 10s sur POS → bannière visible, reconnexion auto < 10s après rétablissement, aucun event loupé entre temps (via polling fallback 30s toujours actif).

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `config/broadcasting.php` | defaults hardening | Write | No | Yes |
| `config/queue.php` | defaults hardening | Write | No | Yes |
| `.env.example` + `.env.production` | documentation valeurs prod | Write | No | No |
| `app/Providers/AppServiceProvider.php` | fail-fast boot guard | Write | No | No |
| `resources/js/bootstrap.js` | Echo heartbeat + reconnect | Write | No | No |
| `resources/js/services/WebSocketService.js` | à créer — service central reconnexion | Write | No | No |
| `resources/js/components/admin/pos/` | banner "reconnexion" | Write | No | No |
| `resources/js/components/admin/kitchenDisplaySystem/` | banner "reconnexion" | Write | No | No |
| `resources/js/components/admin/orderStatusScreen/` | banner "reconnexion" | Write | No | No |
| `resources/js/kiosk/` | banner "reconnexion" | Write | No | No |
| `docs/PRODUCTION_SETUP.md` | création doc | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- `app/Services/OrderService.php` — **frozen zone**, aucune modification.
- `app/Services/FrontendOrderService.php` — **frozen zone**.
- `app/Events/*` — pas de modification des classes d'events dans cette task (réservé à OUTBOX_001 et EVENT_CONTRACT_001).
- Migrations DB — aucune.

## Invariants at Risk
- [x] None — cette task ne touche ni OrderStatus, ni pricing, ni branch_id.
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum
- [ ] branch_id data isolation
- [ ] Dispatch after DB commit
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps (plan guide)

### E1 — Config hardening (backend)
1. Modifier `config/broadcasting.php` : `default` lit `env('BROADCAST_DRIVER')` sans fallback silencieux vers `null` — throw si absent en prod.
2. Idem `config/queue.php` : `default` throw si `sync` en prod.
3. Ajouter dans `AppServiceProvider::boot()` :
   ```php
   if (app()->environment('production')) {
       if (config('broadcasting.default') === null) {
           throw new \RuntimeException('BROADCAST_DRIVER must be set in production (expected: pusher|redis).');
       }
       if (config('queue.default') === 'sync') {
           throw new \RuntimeException('QUEUE_CONNECTION must not be sync in production (expected: redis|database).');
       }
   }
   ```
4. Commit message : `chore(boot): fail-fast if broadcast/queue defaults are unsafe in production`.

### E2 — WebSocketService frontend
1. Créer `resources/js/services/WebSocketService.js` :
   - Singleton exposé sur `window.foodkingWS`.
   - Event bus interne (`ws:connected`, `ws:disconnected`, `ws:reconnecting`).
   - Méthode `bindHeartbeat()` : `setInterval(30_000, () => echo.connector.pusher.send_event('pusher:ping', {}))`.
   - Méthode `bindReconnect()` : sur `disconnected`, backoff exponentiel 1s → 2s → 4s → max 30s.
2. Ajouter émission d'état sur le event bus global (utilisé par les composants pour afficher la bannière).

### E3 — Bannière UI
1. Créer composant `resources/js/components/common/ConnectionStatusBanner.vue` :
   - Listener sur `window.foodkingWS` event bus.
   - Affichage conditionnel : caché si `connected`, banner jaune "Reconnexion…" si `reconnecting` > 5s, banner rouge "Hors ligne" si > 30s.
2. Intégrer le composant dans les layouts : POS, KDS, OSS, Kiosk.

### E4 — Documentation
1. Créer `docs/PRODUCTION_SETUP.md` :
   - Section `.env.production` exemple (Pusher keys, Redis URL, Horizon tag).
   - Section Supervisor (systemd unit pour `php artisan horizon` ou `queue:work`).
   - Section diagnostic (`php artisan horizon:status`, `queue:work --verbose`, logs).

### E5 — Validation locale
1. `php artisan config:clear && php artisan config:show broadcasting` doit afficher driver attendu.
2. `APP_ENV=production php artisan serve` avec `BROADCAST_DRIVER=null` → refuse de démarrer, exception claire.
3. `npm run build` : 0 erreur.
4. Smoke manuel : ouvrir POS, `DevTools → Network → Offline`, vérifier bannière apparaît en < 5s.

## SYMMETRY_NOTE
N/A — OrderService / FrontendOrderService non touchés.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Stop-gate si : un agent propose de toucher `app/Events/*` ou `OrderService.php` pendant cette task → scope expansion.

## Status
- [x] Pending plan
- [x] Plan approved
- [x] In execution
- [x] Validation
- [x] Audit
- [ ] Gate open
- [x] Closed — 2026-04-15
