# TASK_KIOSK_RELIABILITY_001 — Kiosk Fiabilité

## Meta
- **Priority**: P1 (CRITICAL + MAJOR)
- **PRIMARY_MODEL**: claude-sonnet-4-5-20250514
- **TEST_STRATEGY**: local-validation
- **DEPENDS_ON**: TASK_REALTIME_001 (broadcast nécessaire pour sync offline)
- **BLOCKS**: (none)

## Constats couverts
| ID | Severity | Titre |
|----|----------|-------|
| F-02 | CRITICAL | Impression ticket sans gestion d'erreur |
| F-08 | MAJOR | File d'attente offline sans sync au démarrage |
| F-09 | MAJOR | Tiroir-caisse : échec non loggé |
| F-13 | MINOR | Panier : pas de limite de quantité |

## Contexte

Le kiosk est une surface autonome face au client. Les pannes (imprimante, réseau, tiroir-caisse) doivent être gérées gracieusement car aucun employé n'est à proximité pour intervenir immédiatement.

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/services/PrinterService.js` — try/catch + fallback
- `resources/js/services/OfflineQueueService.js` — sync au boot
- `resources/js/services/CashDrawerService.js` — logging échec
- `resources/js/components/kiosk/CartComponent.vue` — limite quantité
- `resources/js/components/kiosk/OrderConfirmation.vue` — fallback impression

### Hors scope
- Driver imprimante (ESC/POS, CUPS)
- Configuration matérielle kiosk
- UI POS (couvert par d'autres tâches)

## Étapes d'exécution

### E1 — Impression avec fallback (F-02)
1. Envelopper l'appel imprimante dans try/catch
2. Si erreur : afficher le numéro de commande en GRAND sur l'écran kiosk
3. Stocker l'erreur dans un log accessible depuis l'admin
4. Option fallback : QR code avec numéro de commande si imprimante KO
5. La commande reste VALIDE même si l'impression échoue

### E2 — Offline sync au démarrage (F-08)
1. Au mount de l'app kiosk, vérifier `localStorage` pour commandes en attente
2. Si commandes trouvées → tenter l'envoi immédiat en FIFO
3. Afficher un indicateur discret "Synchronisation en cours..." pendant le flush
4. Si le serveur est toujours injoignable → garder en queue, retenter dans 30s

### E3 — Cash drawer logging (F-09)
1. Après l'envoi de commande d'ouverture tiroir, vérifier la réponse
2. Si timeout ou erreur → `console.error` + envoi log au backend `/api/logs/hardware`
3. Côté backend : stocker dans `hardware_logs` table (ou fichier log dédié)

### E4 — Limite quantité panier (F-13)
1. Dans `CartComponent.vue`, ajouter une constante `MAX_ITEM_QUANTITY = 20`
2. Désactiver le bouton "+" quand la limite est atteinte
3. Afficher un message : "Quantité maximale atteinte"
4. Validation backend aussi : refuser > 20 dans `OrderController::store()`

## Validation attendue

- [ ] `php artisan test` — 0 failures
- [ ] `npm run build` — 0 errors
- [ ] Simulation erreur imprimante → numéro affiché à l'écran
- [ ] Kiosk démarrage avec commandes offline → sync automatique
- [ ] Panier : impossible d'ajouter > 20 du même item

## Invariants
- La commande est TOUJOURS validée même si l'impression échoue
- L'offline queue respecte l'ordre FIFO
- La limite de quantité est aussi validée backend (pas seulement frontend)

## Gate
- **Gate requise** : NON
- Si création d'une nouvelle table `hardware_logs` → gate pour migration DB
