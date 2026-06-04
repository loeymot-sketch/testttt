# ULTRA-PLAN — Living Synchronization Validation (Supervisor map, 2026-05-29)

Owner mandate (/goal): superviseur autonome ; régler les **3 états non-vivants** ; architecture d'abord, puis agents spécialisés (GStack/Superpowers/Adversaires) + E2E visuel + analyse screen + corrections ; décomposition maximale (1 agent par système → sous-système → micro-système) ; ne revenir que **validé**, audit après ; carte/architecture globale de la synchro.

## 0. Anti-hallucination — architecture d'auth/synchro ANCRÉE (vérifiée 2026-05-29)
- **Auth = Bearer partout** : axios interceptor (`resources/js/shared/axios-setup.js:76-85`) ajoute `Authorization: Bearer ${token}` + `x-api-key` sur CHAQUE requête. Le main-fetch KDS, le poll (`KdsSyncService._authHeaders:416-435`), ET l'auth de canal WebSocket (`bootstrap.js:309-346`, Echo `authEndpoint=/api/broadcasting/auth` avec `Bearer`) utilisent TOUS le même token.
- **TTL = 480 min (8h)** (`config/sanctum.php:51`, `SANCTUM_TOKEN_EXPIRATION`).
- **Pas de refresh proactif** : `bootstrap.js:367` « No timer-based proactive refresh ». Le seul refresh est réactif sur `pusher:subscription_error` et ne fait que **ré-injecter le token LOCAL** (pas un refresh serveur). Sur 401 → redirect `/login` (interceptor ne touche pas 401).
- **MAIS l'endpoint de refresh EXISTE** : `RefreshTokenController@refreshToken` (`routes/api.php:155`, `POST /api/refresh-token`, middleware `installed`+`apiKey`) — supprime l'ancien token + `createToken('auth_token', abilities-preserved)` → token frais 8h. Le commentaire bootstrap.js:367 est **périmé**.
- **Cascade sync** (rappel, du from-roots audit) : Outbox (`DomainEvent` + `DispatchDomainEventsJob` 3-phase claim + backoff) → Soketi/Pusher broadcast → consommateurs par surface (`eventContract.js` WS + `KdsSyncService` delta-poll fallback) → crons (rescue/monitor/retry/z-membership).

## 1. Les 3 états non-vivants (cible)
| # | Problème | Type | Plan |
|---|---|---|---|
| **P-AUTH** | Falaise TTL 8h : à 8h le token expire → WS-auth ET poll-auth 401 → KDS/admin meurent en plein service (pas de refresh proactif). | **FIX code** | Timer de refresh proactif → `/api/refresh-token` (endpoint existant) avant l'expiry ; ré-injecter dans Vuex+Echo+axios ; corriger le commentaire périmé. Non-frozen (bootstrap.js / un service token). |
| **P-LIVE-SYNC** | La synchro n'a jamais été validée VIVANTE : push WS temps-réel, delta-poll, cohérence multi-surface, dégradation WS-down→poll. Seulement navigation séquentielle mono-surface. | **VALIDATE live** | E2E multi-surface live : 2 onglets, 1 commande, mesurer la propagation (WS, pas reload) ; dégrader le WS, confirmer le poll rattrape ; analyser les captures. |
| **P-COMES-OUT** | Cycle jamais poussé jusqu'au terminal : Prêt→Livré/servi ; colonne « Prêt » OSS + chime jamais exercés. | **VALIDATE live** | Drive borne→encaissement→KDS bump→Prêt→Livré, sur chaque surface, captures analysées. |

## 2. Carte des systèmes + décomposition agents (1 agent / système, sous-agents / sous-système)
Synchro = le système CENTRAL ; chaque surface est un consommateur. Décomposition pour l'AUDIT adversarial (read-only, parallèle) :
- **SYS-OUTBOX** (producteur) : DomainEvent + DispatchDomainEventsJob + crons. Sous : claim 3-phase / backoff / dead-letter / z-membership.
- **SYS-BROADCAST** (transport) : Soketi/Pusher + Echo + `/api/broadcasting/auth`. Sous : channel-auth Bearer / subscription_error / reconnect.
- **SYS-CONSUME-KDS** : KdsSyncService (WS + delta-poll) + KitchenDisplaySystemComponent. Sous : WS-handler / poll-fallback / version-gate / dedupe.
- **SYS-CONSUME-OSS** : OrderStatusScreen + PreparingAndReadyComponent. Sous : En-préparation / Prêt+chime / poll.
- **SYS-CONSUME-POS-TRACKER** : PosOrdersTracker. Sous : buckets / encaisser / live-refresh.
- **SYS-AUTH-LIFETIME** : token TTL / refresh / Echo re-auth / 401-handling. (P-AUTH lives here.)
- **SYS-ORDER-LIFECYCLE** : OrderStateMachine transitions PENDING→…→DELIVERED across surfaces. (P-COMES-OUT lives here.)
Agents par système : Architect + SRE/Sync + Adversarial-RED + (UX/Visual pour les surfaces). Verify-before-report. Chaque finding P0/P1 vérifié adversairement.

## 3. Waves
- **W0 — Architecture** (ce doc). ✓
- **W1 — FIX P-AUTH** : implémenter le refresh proactif ; test (token refresh avant expiry, KDS survit simulé) ; full suite.
- **W2 — VALIDATE P-LIVE-SYNC** (live, main thread) : navigateur propre, 2 surfaces, 1 commande → mesurer propagation WS ; dégrader WS → poll ; captures Read+analysées. + agents SRE/adversarial décomposent l'architecture sync en parallèle (read-only).
- **W3 — VALIDATE P-COMES-OUT** (live) : drive jusqu'à Livré, chaque surface, captures.
- **W4 — Heal** : toute régression/finding des agents → fix → re-test.
- **W5 — AUDIT final** : agent-army par système (adversarial) → 0 P0/P1 NEW ; full vitest + full PHP + NF525 chain + frozen ; 2 rounds convergence identique.
- **W6 — Le livre** : verdict honnête « validé / non-validé » par état, + analyse vision/goal.

## 4. Convergence (ne PAS revenir avant)
- P-AUTH : token se rafraîchit avant expiry, prouvé (test) ; KDS poll+WS survivent une fenêtre simulée > TTL.
- P-LIVE-SYNC : propagation WS observée + mesurée live (pas reload) ; poll-fallback observé sous WS dégradé ; multi-surface cohérent.
- P-COMES-OUT : commande poussée jusqu'à Livré, observée sur toutes les surfaces.
- Gates : full vitest 0-fail + full PHP 0-fail + NF525 CHAIN OK + frozen 15/15 ; audit adversarial 0 new P0/P1, 2 rounds identiques.
- Discipline : suite COMPLÈTE (jamais `--filter` étroit) ; frozen = LOCK+gate ; no push ; no --no-verify.

Statut : W0 fait → W1 (FIX P-AUTH) en cours.
