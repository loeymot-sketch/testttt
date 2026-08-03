# Axe 13 — Sync POS ↔ Kiosk ↔ KDS (événements & dispo)

| Mécanisme | Statut |
|-----------|--------|
| **Broadcast** | `OrderStatusChanged`, `OrderCreated` — whitelist events (cf. rapports VERIFY P9.x antérieurs si besoin). |
| **Echo** | `bootstrap.js` + `WebSocketService` — reconnexion / polling fallback (plan REALTIME antérieur). |
| **Item 86 / dispo** | P1 `AvailabilityService` + prune kiosk + events — **contredit** `BUSINESS_RULES.md` §Stock absent (`F-SYNC-001`). **Action :** mettre à jour doc. |
| **X-Correlation-ID** | Présent sur transitions KDS / state machine record (header lu dans `OrderStateMachine` si défini) — cohérence partielle. |
| **P4 KDS** | 409 + refresh liste — anti-dérive opérateurs. |

**Tests existants :** `SyncComprehensiveTest`, `KioskIdsOnlyPayloadTest`, E2E kiosk flow (nommage dans `tests/Feature/OrderPipeline/`).

**Liens tracker :** F-SYNC-001, F-KDS-001.
