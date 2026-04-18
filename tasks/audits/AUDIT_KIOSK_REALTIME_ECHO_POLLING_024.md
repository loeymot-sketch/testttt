# AUDIT_KIOSK_REALTIME_ECHO_POLLING_024 — Temps réel Kiosk (Echo + fallback polling)

## Meta
- **Priority** : P1
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_KIOSK_ORDER_CREATION_016
- **Estimation** : 0.75 j-h
- **Vague** : C9

## Contexte

Après paiement, le kiosk affiche un écran "Votre commande est en préparation" (`KioskWaitingComponent.vue`, ligne 189 subscribe Echo). Il doit suivre les transitions status en temps réel :
- PENDING/PAID/ACCEPT → PREPARING → PREPARED → "Prête ! File X".
En cas de coupure Echo/WebSocket : fallback polling. Risques : kiosk bloqué sur "En préparation" alors que commande est prête (client part fâché), ou événement reçu deux fois (double refresh), ou kiosk abonné à la mauvaise branche.

## Questions d'audit

1. `KioskWaitingComponent.vue` subscribe-t-il au channel correct `private-branch.{branchId}` avec le token kiosk ?
2. Filtre-t-il bien sur `order_id` courant (ignore les events des autres commandes) ?
3. Gère-t-il la réception de `OrderStatusChanged` pour transiter l'UI vers "Prête" ?
4. En cas de déconnexion Echo : reconnexion automatique tentée ? Backoff exponentiel ?
5. Fallback polling : un interval GET `/api/frontend/order/{id}` toutes les X secondes si Echo KO ?
6. Le polling s'arrête-t-il quand la commande passe en terminal ou reste-t-il actif jusqu'à timeout UI ?
7. Double réception event (Echo + polling) : dédup ?
8. L'envelope reçue est-elle validée côté client (eventContract.js mentionné dans EventContract.php) ?
9. Les channels présence (listes actifs) utilisés ou uniquement private ?
10. La perf : combien de connexions Pusher simultanées max (plusieurs kiosks branche × plusieurs clients en attente) ?

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/bootstrap.js` — Echo config
- `resources/js/services/eventContract.js` (client-side)
- `resources/js/services/polling*.js` (si existe)
- `routes/channels.php`

## Invariants at Risk
- [x] Synchronisation temps réel
- [x] Dispatch after DB commit (client-side cohérence)

## Fichiers à lire
1. `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
2. `resources/js/bootstrap.js`
3. `resources/js/services/eventContract.js`
4. `resources/js/components/frontend/kiosk/**/*.vue` — autres composants temps réel
5. `routes/channels.php`

## Grep patterns

```
grep -rn "Echo.private\|Echo.channel" resources/js/components/frontend/kiosk/
grep -rn "subscribe\|listen" resources/js/components/frontend/kiosk/
grep -rn "polling\|setInterval" resources/js/components/frontend/kiosk/
grep -rn "reconnect\|disconnect\|backoff" resources/js/
grep -rn "OrderStatusChanged" resources/js/
grep -rn "eventContract" resources/js/
```

## Evidence required
- Code abonnement Echo du KioskWaitingComponent.
- Existence fallback polling.
- Gestion dédup Echo + polling.
- Validation envelope client.
- Comportement reconnexion.

## Grille de verdict
- **PASS** : Echo + fallback polling, dédup, validation envelope, reconnexion backoff.
- **WARN** : Echo seul, pas de polling (acceptable si infra stable) mais UX dégradée en coupure.
- **BLOCKED** : kiosk bloqué si coupure, événements doublés affichent erreurs, channel mal filtré.

## Livrable
`reports/review/AUDIT_KIOSK_REALTIME_ECHO_POLLING_024_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
