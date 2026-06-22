# Plan v2 — extrait pertinent pour 1.E

## 1.E — F-12 · Echo auth expiration : feedback + refresh proactif (avancé avant 1.D)

**Cible :** `resources/js/services/WebSocketService.js`, `resources/js/bootstrap.js`, `ConnectionStatusBanner.vue`.

- WS service : écouter Pusher `subscription_error` ; émettre `auth-expired` sur le bus.
- Bootstrap : `_refreshEchoAuth()` proactif → REVISÉ par orchestrateur en mode REACTIF (subscription_error → reinject), pas de timer (pas d'endpoint refresh-token côté backend).
- Banner : nouvelle bannière « Session expirée — recharger la page » non-dismissible.
- Tests : wsAuthExpired.spec.js, wsAuthRefreshLoop.spec.js (3 échecs → state session_invalid).

**Critère mesurable :** token expiré côté backend → user voit banner + state explicit `session_invalid`. Pas de zombie UI.
