# AUDIT_KIOSK_HARDWARE_BRIDGE_021 — Hardware bridge (imprimante, tiroir, TPE)

## Meta
- **Priority** : P1
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : —
- **Estimation** : 0.75 j-h
- **Vague** : C6

## Contexte

Le kiosk Electron (Windows) expose `window.borne.*` : `openDrawer()`, `print(buffer)`, `tpeCharge(amount)`, plus info signaux. Interface critique : une erreur matérielle doit être détectée, loggée, remontée côté user ET côté backend monitoring. Risques : erreurs silencieuses, pas de health check, pas de fallback.

## Questions d'audit

1. Quels sont tous les appels `window.borne.*` utilisés dans le kiosk (imprimante, tiroir, TPE, audio, écran) ?
2. Chaque appel retourne-t-il un `Promise` typé (success/error) ou fire-and-forget ?
3. Les erreurs (imprimante offline, TPE déconnecté) sont-elles remontées au backend (endpoint observabilité) ?
4. Existe-t-il un healthcheck périodique (tous les X min) du hardware, stocké DB, visible admin ?
5. L'utilisateur reçoit-il un feedback immédiat en cas d'erreur matérielle ?
6. Le code ESC/POS généré pour l'impression est-il centralisé (service) ou éparpillé ?
7. Le tiroir-caisse sur kiosk : quel cas d'usage ? (cash kiosk dédié avec tiroir ?)
8. La TPE adapter est-elle un driver unique ou multi-providers (Ingenico, Verifone) via interface ?
9. Les versions de firmware hardware sont-elles remontées (lecture `window.borne.info()`) ?
10. En cas de nouveau type de hardware (future imprimante), comment ajouter le support sans toucher au code kiosk critique ?

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/services/hardware*.js`, `printer*.js`, `tpe*.js`
- `resources/js/components/frontend/kiosk/**/*Hardware*`, `*Print*`
- `app/Http/Controllers/Frontend/KioskEventController.php` (observability) — lu dans summary
- Éventuel main process Electron (hors repo Laravel ?)

## Invariants at Risk
- [ ] Aucun invariant métier direct, mais qualité opérationnelle critique

## Fichiers à lire
1. `resources/js/services/hardware*.js` (grep)
2. Composants kiosk impression / paiement
3. `app/Http/Controllers/Frontend/KioskEventController.php`

## Grep patterns

```
grep -rn "window.borne\|window\.borne" resources/js/
grep -rn "openDrawer\|print\|tpeCharge\|printReceipt" resources/js/
grep -rn "KioskEvent\|kiosk-event\|observability" app/ resources/js/
grep -rn "healthcheck\|health_check\|heartbeat" app/ resources/js/
grep -rn "ESC/POS\|ESCPOS\|escpos" resources/js/
```

## Evidence required
- Inventaire des appels `window.borne.*`.
- Gestion erreur par appel.
- Stratégie observability (endpoint + fréquence).
- Service ESC/POS centralisé ou dispersé.

## Grille de verdict
- **PASS** : Promise typés, erreurs remontées backend, healthcheck, service centralisé, feedback user.
- **WARN** : erreurs loggées Vue seulement (pas backend), pas de healthcheck mais bridge propre.
- **BLOCKED** : fire-and-forget, erreurs silencieuses, ESC/POS dupliqué, aucun monitoring.

## Livrable
`reports/review/AUDIT_KIOSK_HARDWARE_BRIDGE_021_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
