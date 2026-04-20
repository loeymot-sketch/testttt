# Axes 14–15 — Observabilité & performance

## 14 — Logs

- **Canal `fiscal`** : utilisé sur ouverture Z (`ZReportService` lignes log citées en audit fiscal).
- **PII** : pas d’audit exhaustif des payloads logs — **risque** si totaux + noms dans traces applicatives génériques.
- **Corrélation** : `X-Correlation-ID` sur transitions auditées — utile si front envoie toujours l’en-tête (non prouvé global).

## 15 — Performance (rush hour)

- **Preuve** : **aucun** test charge 500+ ord/h **dans** le dépôt pour ce run (`F-PERF-001`).
- **Locks** : `lockForUpdate` sur séquence commandes + Z close ; **risque deadlock** théorique faible (scopes différents).
- **N+1** : `OrderService` non profilé ici.
- **WebSocket** : saturation Pusher — **hors mesure** read-only.

**Recommandation** : surcharge DB sur `orders` peak → index + monitoring `slow_query` prod.

**Liens tracker :** F-OBS-001, F-PERF-001.
