# TASK_REALTIME_001 — Real-time & WebSocket

## Meta
- **Priority**: P0 (CRITICAL — bloquant production)
- **PRIMARY_MODEL**: claude-sonnet-4-5-20250514
- **TEST_STRATEGY**: playwright-critical-flow
- **DEPENDS_ON**: (none)
- **BLOCKS**: TASK_KIOSK_RELIABILITY_001

## Constats couverts
| ID | Severity | Titre |
|----|----------|-------|
| F-01 | CRITICAL | Echo/Pusher non initialisé dans app.js |
| F-05 | MAJOR | Pas de heartbeat/reconnexion WebSocket |
| F-17 | MINOR | Broadcast driver sans fallback |

## Contexte

Le fichier `resources/js/bootstrap.js` contient le code Echo/Pusher mais il est **commenté**. Aucun événement WebSocket ne peut être reçu. Les surfaces temps réel (KDS, OSS, POS) dépendent toutes de ce mécanisme pour recevoir les mises à jour de commandes.

Sans cette correction, le système fonctionne uniquement en mode polling (30s), ce qui est inacceptable pour un KDS en production.

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/bootstrap.js` — décommenter et configurer Echo
- `resources/js/app.js` — vérifier l'import et l'initialisation
- `.env` / `config/broadcasting.php` — configuration Pusher/Redis
- `resources/js/components/admin/kitchenDisplaySystem/` — reconnexion listener
- `resources/js/components/admin/orderStatusScreen/` — reconnexion listener
- `resources/js/components/admin/pos/` — reconnexion listener

### Hors scope
- Changement de driver broadcast (rester sur Pusher si déjà configuré)
- Modification des événements Laravel (OrderStatusChanged, etc.)
- Migration base de données

## Étapes d'exécution

### E1 — Activer Echo (F-01)
1. Décommenter le bloc Echo dans `bootstrap.js`
2. Vérifier la configuration `.env` : `BROADCAST_DRIVER`, `PUSHER_APP_KEY`, etc.
3. S'assurer que `config/broadcasting.php` a les bonnes valeurs
4. Tester : `php artisan tinker` → `event(new \App\Events\OrderStatusChanged(...))`

### E2 — Heartbeat & reconnexion (F-05)
1. Ajouter un mécanisme de reconnexion automatique dans un service dédié `WebSocketService.js`
2. Heartbeat toutes les 30s via `Echo.connector.pusher.connection.bind('state_change', ...)`
3. Sur déconnexion : tentative de reconnexion avec backoff exponentiel (1s, 2s, 4s, max 30s)
4. Notification visuelle discrète sur KDS/OSS : "Connexion perdue — reconnexion..."

### E3 — Fallback polling (F-17)
1. Si Echo échoue au boot (erreur Pusher, config manquante), activer un fallback polling
2. Polling intervalle : 10s (plus agressif que le 30s actuel)
3. Log warning : "WebSocket unavailable, falling back to polling"
4. Quand Echo se reconnecte, désactiver le polling automatiquement

## Validation attendue

- [ ] `php artisan test` — 0 failures
- [ ] `npm run build` — 0 errors
- [ ] Echo initialisé au chargement de chaque surface (vérifier console : "Echo connected")
- [ ] Déconnexion réseau simulée → reconnexion automatique en < 10s
- [ ] Si Pusher indisponible → fallback polling actif, log visible

## Invariants
- Aucune modification aux événements Laravel existants
- Aucune modification à OrderService / FrontendOrderService (frozen zones)
- Le polling existant (30s) reste en place comme fallback, pas de suppression

## Gate
- **Gate requise** : NON (pas de modification frozen zone)
- Si le scope s'étend aux événements backend → STOP et gate humaine
