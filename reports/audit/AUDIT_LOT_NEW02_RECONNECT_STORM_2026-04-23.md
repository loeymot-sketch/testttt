# Audit Lot NEW-02 — Echo reconnect-storm + decorrelated-jitter circuit breaker

**Date** : 2026-04-23
**Cycle** : `sync_hardening_v3`
**Plan** : `plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md` (Phase 1bis, Vague 3)
**Implémenteur** : GPT-5.5-high via `agents/codex.runner.mjs`
**Auditeurs** :
1. GPT-5.5-high (audit indépendant — `missions/T-AUDIT-NEW02/output_codex.json`)
2. Claude Code CLI (audit indépendant — `bash scripts/foodking-claude-orchestrate.sh audit`)

---

## Verdict final : ✅ CLOSED — PASS (toutes warnings résolues)

| Audit | Verdict initial | Findings | Statut après remédiation |
|---|---|---|---|
| GPT-5.5-high (T) | PASS_WITH_WARNINGS | G2, G6, G7, G10 (warnings) ; G1, G3, G4, G5, G8, G9, G11 (info) ; T-MISS-A..E (5 tests) | ✅ Tous warnings corrigés ; 5 tests ajoutés |
| Claude Code (terminal) | PASS_WITH_WARNINGS | A1 (warning) ; A2, A3, A4, A5 (info) ; T-NEW-1..3 (3 tests P1/P2) | ✅ A1 + A2 + A3 corrigés ; A4/A5 documentés ; T-NEW-1 (P1) ajouté |

---

## Livrables techniques

### Modifiés
- `resources/js/services/WebSocketService.js` (+~120 lignes) — détection storm sliding window, breaker decorrelated-jitter, F-12 préservé, retours `unsubscribe` sur `on()`/`onAuthError()`.
- `resources/js/services/KdsSyncService.js` (~25 lignes ajoutées dans `_bindWsState`) — subscription `reconnect_storm` + `forceSync()` avec jitter 0–500 ms client-side.

### Créés
- `tests/js/wsReconnectStormDetection.spec.js` (3 tests)
- `tests/js/wsReconnectStormCircuitBreaker.spec.js` (8 tests, dont T-MISS-A/B/D/E)
- `tests/js/wsAuthAndStormCohabitation.spec.js` (4 tests, dont T-MISS-C + T-NEW-1)
- `tests/js/kdsReactsToReconnectStorm.spec.js` (3 tests)

### Patché (dérive pré-existante détectée pendant la régression)
- `tests/js/eventContractDedupe.spec.js` — cap LRU 512 → 2048 pour s'aligner sur NEW-01 (`SEEN_CORRELATION_CAP`).

---

## Synthèse des findings et remédiations

### Audit T (GPT-5.5-high)

| ID | Sévérité | Finding | Remédiation appliquée |
|---|---|---|---|
| **G2** | warning | Reentrancy synchrone : un listener `state_change` qui rappelle `_setState(CONNECTED)` peut laisser un timestamp storm orphelin. | `_setState()` réordonné : bookkeeping (reset auth/storm, record disconnect, flag session-invalid) AVANT toute émission externe. Flag `shouldEmitSessionInvalid` capturé pour préserver l'idempotence F-12. |
| **G6** | warning | Après timer-fire, breaker se referme mais `_disconnectTimestamps` non vidé → un seul disconnect post-cool-down ré-ouvre le breaker. | Le callback du `setTimeout` vide `_disconnectTimestamps = []` avant `_circuitBreakerOpen = false` → contrat « 4 disconnects frais par cycle » respecté. |
| **G7** | warning | `WebSocketService.on()` ne retournait pas d'unsubscribe → `KdsSyncService._wsUnsubscribers` accumulait des `undefined` → leak listeners cross-mount. | `on()` retourne `() => this.off(event, fn)`. `onAuthError()` (audit-2 A2) propage aussi le retour. |
| **G10** | warning | 50+ KDS recevant `reconnect_storm` simultanément → burst sur `/api/admin/kds-order/sync`. | Jitter uniforme 0–500 ms côté client (`Math.floor(Math.random()*500)`) avant `forceSync()`. Tests forcent `Math.random=0` pour déterminisme. |
| G1, G3, G5, G9, G11 | info | Validations sliding-window, formule AWS canonique, gating breaker, cohabitation F-12. | Confirmés conformes ; aucun changement. |
| G4, G8 | info | `_lastStormDelayMs` survit volontairement à CONNECTED ; `destroy()` adéquat pour singleton. | Documenté ; tests `T-MISS-A` et T-MISS-B couvrent le cycle. |

**Tests manquants T-MISS-A..E** :
- T-MISS-A (P1) : `destroy()` pendant breaker ouvert → `pusher.connect()` jamais appelé. ✅
- T-MISS-B (P1) : Storms successives → `_lastStormDelayMs` croît, borné par `STORM_MAX_DELAY_MS`. ✅
- T-MISS-C (P1) : Cohabitation F-12 ↔ NEW-02 sans corruption croisée. ✅ (3 scénarios)
- T-MISS-D (P2) : `pusher.disconnect()` lance → breaker s'ouvre quand même + reconnect planifié. ✅
- T-MISS-E (P2) : `window.Echo` absent → breaker s'ouvre, ne crashe pas. ✅

### Audit Claude Code (second-opinion terminal)

| ID | Sévérité | Finding | Remédiation appliquée |
|---|---|---|---|
| **A1** | warning | Si `SESSION_INVALID` arrive pendant le cool-down, le `setTimeout` rappelle quand même `pusher.connect()` → spin auth-failed silencieux. | Double défense : (i) `_setState(SESSION_INVALID)` appelle `_resetReconnectStormState()` qui annule le timer ; (ii) le callback du timer guard `if (this._state !== STATE.SESSION_INVALID)` avant `pusher.connect()`. |
| **A2** | info | `onAuthError()` n'exposait pas l'unsubscribe (pré-existant F-12). | `onAuthError(fn)` retourne désormais `this.on('auth_error', fn)`. |
| **A3** | info | `attempts_in_window` pouvait reporter 5 si Pusher émet sync-disconnect pendant `pusher.disconnect()`. | Snapshot `const attemptsSnapshot = this._disconnectTimestamps.length;` avant `pusher.disconnect()` → payload exact. |
| A4 | info | `_emit('disconnected')` utilise `newState` local et non `this._state` post-mutation. | Documenté (intentionnel — JS single-thread). |
| A5 | info | `reconnect_storm` précède `state_change` du 4e disconnect. | Commentaire ajouté : précedence intentionnelle pour engager les fallbacks polling immédiatement. |

**Tests proposés** :
- T-NEW-1 (P1) : SESSION_INVALID pendant cool-down → `pusher.connect()` jamais appelé. ✅ Ajouté à `wsAuthAndStormCohabitation.spec.js`.
- T-NEW-2 (P2) : Décroissance non-monotone du jitter avec `Math.random=0.001`. Documenté en backlog.
- T-NEW-3 (P2) : `pusher.disconnect()` sync-emet DISCONNECTED → `attempts_in_window === 4` (pas 5). Documenté en backlog (couvert implicitement par A3 fix).

---

## Validations finales

| Vérif | Résultat |
|---|---|
| `npx vitest run tests/js/wsReconnectStorm*.spec.js tests/js/wsAuthAndStormCohabitation.spec.js tests/js/kdsReactsToReconnectStorm.spec.js` | **18/18 PASS** (4 fichiers) |
| `npx vitest run` (suite complète) | **790/790 PASS** (108 fichiers) |
| `php artisan test --filter='OutboxTest|EventContractTest|OutboxConcurrentWorkerDedupeTest'` | **21/21 PASS** (régression backend) |
| `bash scripts/check-invariants.sh` | **6/6 OK** |

---

## Conformité plan + invariants

- ✅ `commit_before_dispatch` : non touché (lot frontend pur).
- ✅ Frozen zones (OrderService, FrontendOrderService, OrderStateMachine, etc.) : intactes.
- ✅ F-12 (auth-failure SESSION_INVALID) : préservé bit-for-bit + cohabitation prouvée par 4 tests dédiés.
- ✅ API publique `WebSocketService` : extension uniquement (nouveaux getters + nouvel événement `reconnect_storm`). `on()`/`onAuthError()` retournent désormais une valeur supplémentaire (rétro-compatible — appelants existants ignorent le retour).
- ✅ API publique `KdsSyncService` : pas de changement de signature ; nouvel événement re-émis (`reconnect_storm`).

---

## Décisions tracées en mémoire

- `memory/episodes/12_decisions_log.jsonl` (entry 2026-04-23T22:00:00Z, lot=NEW-02).
- `missions/T-NEW02-RECONNECT-STORM/input.json` (brief implémentation).
- `missions/T-AUDIT-NEW02/input.json` + `output_codex.json` (audit T).
- Le présent rapport (audit Claude + synthèse + remédiations).

**Prochain lot** : NEW-03 — Queue scalability (high/default routing + retries).
