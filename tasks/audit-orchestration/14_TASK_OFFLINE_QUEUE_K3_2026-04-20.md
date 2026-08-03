# T14 — Offline queue K-3 : IDB / SW / backoff

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Vérifier le mode offline kiosk : mirror IndexedDB du menu, queue d'orders avec backoff
30 min, conflict bucket, circuit-breaker offline guard, service worker, fix backend lock
release au reboot.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Lire :
   - resources/js/helpers/kioskOffline.js / kioskQueue.js
   - resources/js/helpers/idb*.js
   - resources/js/serviceWorker.js (ou public/sw.js)
   - resources/js/composables/useKioskOffline*.js
2) Vérifier :
   - Mirror IDB du menu : trigger d'invalidation cohérent avec K-2 (item 86, dispo).
   - Queue orders : backoff 30 min + jitter, max retries.
   - Conflict bucket : commande refusée backend (409) → quoi ?
   - Circuit breaker : ouvre quand N erreurs consécutives, ferme après cooldown.
   - SW scope kiosk uniquement (pas de pollution admin/POS).
3) Backend : recherche du fix « lock release boot recovery K-3.1 » :
   `rg -n "K-3.1|boot.recovery|stale.lock" app/`
4) Tests :
   - tests/js/kioskOffline*.spec.js
   - tests/js/kioskQueue*.spec.js
   - tests/Feature/Kiosk/OfflineRecoveryTest.php
5) Audit : reports/review/AUDIT_KIOSK_110_HARDWARE_OFFLINE_2026-04-19.md.
6) Cohérence avec K-9 (analytics offline.* whitelist).

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK14_OFFLINE_QUEUE_K3_2026-04-20.md
```

## Lecture obligatoire

- `resources/js/helpers/kioskOffline.js`, `kioskQueue.js`
- `tasks/k-hardening/PLAN_K3_OFFLINE_QUEUE_2026-04-18.md`
- `reports/execution/VERIFY_K3_OFFLINE_QUEUE_2026-04-18.md`

## Checklist multi-points

- [ ] V1. Mirror IDB synchronisé sur invalidation K-2
- [ ] V2. Queue backoff 30 min + jitter + max retries documenté
- [ ] V3. Conflict bucket : politique claire (UI message + logging)
- [ ] V4. Circuit breaker testé (état OPEN/HALF_OPEN/CLOSED)
- [ ] V5. Service worker scope correct
- [ ] V6. Heal K-3.1 boot recovery présent
- [ ] V7. Whitelist `offline.*` analytics

## Critères PASS / FAIL

- **PASS** : 7 V cochées.
- **FAIL** : ≥ 1 mécanique offline cassée → perte d'order possible.

## Output

`reports/audit-orchestration/REPORT_TASK14_OFFLINE_QUEUE_K3_2026-04-20.md`

## Si FAIL → action

→ T14b `generalPurpose` : patch + test rouge → vert.
