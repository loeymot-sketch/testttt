# T14 — Offline queue K-3 : IDB / SW / backoff — RAPPORT D'AUDIT

**Date** : 2026-04-20
**Auditeur** : sous-agent `explore` read-only (lecture/glob/grep uniquement, aucune modification, aucun test exécuté)
**Profondeur** : very thorough
**Worktree audité** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93`
**Branche cible** : `feat/kiosk-phase-9-3` (selon VERIFY_K3_OFFLINE_QUEUE_2026-04-18.md)
**Plan / source d'autorité** : `tasks/k-hardening/PLAN_K3_OFFLINE_QUEUE_2026-04-18.md`,
`reports/execution/VERIFY_K3_OFFLINE_QUEUE_2026-04-18.md`,
`reports/review/AUDIT_KIOSK_110_HARDWARE_OFFLINE_2026-04-19.md`

---

## 1. Verdict global

### ❌ FAIL — 3 V cochées sur 7, 4 PARTIAL/FAIL

Le critère PASS de la fiche T14 exige **les 7 V cochées**. Trois V manquent ou sont
partielles, dont V7 (whitelist analytics `offline.*`) qui est totalement absente.

**Le pipeline order-replay reste sûr** (zéro perte d'order démontrable : conflict
bucket capture les 409, idempotency lock release backend prouvé, budget 30 min,
CB offline guard) — le critère « FAIL ⇔ perte d'order possible » n'est PAS atteint.
Mais l'ensemble *strict* des sept points exigés par la fiche T14 ne l'est pas non
plus, donc verdict **FAIL** au sens de la grille T14 (« 7 V cochées »).

| ID | Item | Statut | Évidence file:line |
|----|------|--------|--------------------|
| V1 | Mirror IDB sync sur invalidation K-2 (item 86, dispo) | ⚠️ PARTIAL | `resources/js/store/modules/kioskMenu.js:185-228` `UPDATE_ITEM` mute `state.items` mais **ne réécrit pas** `saveSnapshot()` ; snapshot IDB stale tant que `fetchMenu()` ne retombe pas |
| V2 | Backoff 30 min + jitter + max retries documenté | ⚠️ PARTIAL | `resources/js/helpers/kioskOfflineQueue.js:42-46, 105-118` — schedule 7 paliers + budget 30 min ✓, **JITTER absent** (`_nextBackoffMs` retourne valeur déterministe) |
| V3 | Conflict bucket : politique claire (UI message + logging) | ⚠️ PARTIAL | `kioskOfflineQueue.js:121-127, 179-198` (bucket + `getConflictedOrders`) ; UI = compteur seul (`KioskOfflineBannerComponent.vue:25-32, 78-80`) ; **pas de flux de résolution** ITEM_UNAVAILABLE prévu par ADR-4 (« liste items 86 + bouton Re-sélectionner ») ; logging `order_abandoned` OK (`kioskOfflineQueue.js:284-293`) mais aucun log spécifique `conflict_*` |
| V4 | Circuit breaker testé (OPEN / HALF_OPEN / CLOSED) | ❌ FAIL au sens strict | Pas de circuit-breaker formel à 3 états dans le code. `networkStatus.js` est un **latch binaire** online/offline (`services/networkStatus.js:48-76`). Le « CB offline guard » désigne **Carte Bancaire** (ADR-5), pas Circuit Breaker (`store/modules/kioskCart.js:348-353`). Aucun compteur N erreurs consécutives, aucun cooldown HALF_OPEN |
| V5 | Service worker scope correct (kiosk uniquement) | ✅ PASS | `public/kiosk-sw.js:55-73` (filtres path `/kiosk`, `/js`, `/css`, …) + `KioskAppComponent.vue:256-261` (`scope: '/kiosk'`, registration conditionnée à `pathname.startsWith('/kiosk')`) ; bypass explicite `/api/*`, `/broadcasting/*`, `/socket.io/*` |
| V6 | Heal K-3.1 boot recovery présent (frontend + backend lock release) | ✅ PASS | Front : `kioskOfflineQueue.js:90-101` (`_hydrateFromIdbIfEmpty`) + `:303-309` (hydration AVANT `getPendingCount` early-return) ; test `tests/js/kioskOfflineQueueK3BootRecovery.spec.js:33-64`. Backend : `app/Services/FrontendOrderService.php:139-145` (`optional($idempotencyLock)->release()` avant early-return) + test `tests/Feature/Frontend/OfflineQueueReplayIdempotencyTest.php` |
| V7 | Whitelist `offline.*` dans analytics | ❌ FAIL | `resources/js/helpers/kioskAnalytics.js:37-137` — aucune entrée `offline.queued`, `offline.replayed`, `offline.conflicted`, `offline.abandoned`, `offline.recovered`. Cohérence K-9 cassée. Seul fallback : `kioskOfflineQueue.js:284-293` poste `type:'order_abandoned'` sur `frontend/kiosk-event` (générique, hors namespace analytics) |

**Score** : 2 PASS / 2 PARTIAL / 1 FAIL strict / 1 FAIL absolu / 1 FAIL absolu = **3 V validables sur 7** → ne satisfait pas le critère PASS.

---

## 2. Méthodologie

Lectures exhaustives sur :

- `resources/js/helpers/kioskOfflineQueue.js` (357 lignes) — queue / backoff / conflict / boot recovery / abandon report
- `resources/js/helpers/kioskMenuCache.js` (102 lignes) — snapshot menu IDB
- `resources/js/services/networkStatus.js` (224 lignes) — latch online/offline tri-source
- `resources/js/helpers/kioskAnalytics.js` (341 lignes) — whitelist event_name (vérification présence `offline.*`)
- `resources/js/store/modules/kioskCart.js:342-360` — guard CB offline (ADR-5)
- `resources/js/store/modules/kioskMenu.js:138-228` — mutation `UPDATE_ITEM` broadcast `ItemAvailabilityChanged`
- `resources/js/components/frontend/kiosk/KioskOfflineBannerComponent.vue` (170 lignes)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue:240-290` — registration SW + autosync + interceptor
- `public/kiosk-sw.js` (92 lignes) — vanilla SW network-first + bypass /api/*
- `app/Services/FrontendOrderService.php:120-148` — idempotency lock + heal release
- `tests/js/kioskOfflineQueueK3.spec.js` (185 lignes), `kioskOfflineQueueK3BootRecovery.spec.js` (65 lignes), `kioskOfflineBanner.spec.js`, `kioskOfflineQueue.spec.js`
- `tests/Feature/Frontend/OfflineQueueReplayIdempotencyTest.php`
- Audit antérieur : `reports/review/AUDIT_KIOSK_110_HARDWARE_OFFLINE_2026-04-19.md` (axes AX9-01..04)
- Plan : `tasks/k-hardening/PLAN_K3_OFFLINE_QUEUE_2026-04-18.md` (13 items K-3.1..K-3.13)
- Verify K-3 : `reports/execution/VERIFY_K3_OFFLINE_QUEUE_2026-04-18.md`

Recherches grep ciblées :
- `circuit.?breaker|CIRCUIT|CircuitBreaker` → 1 hit (rapport antigravity uniquement, aucun code applicatif)
- `K-3\.1|boot.recovery|stale.lock` côté `app/` → aucun match dédié K-3.1, uniquement les références de l'OutboxRescue / KDS / OtpManager
- `offline\.` dans `kioskAnalytics.js` → 0 match
- `serviceWorker|kiosk-sw|registerSW` → 1 hit (`KioskAppComponent.vue:257-260`)
- `public/sw.js`, `**/serviceWorker*.js`, `resources/js/composables/useKioskOffline*.js` → 0 match (le projet n'a pas ces noms : c'est `public/kiosk-sw.js` et l'autosync vit dans `KioskAppComponent.mounted`, pas dans un composable)

Aucun test n'a été exécuté.

---

## 3. Détail par checklist V1..V7

### V1 — Mirror IDB synchronisé sur invalidation K-2 (item 86, dispo) — ⚠️ PARTIAL

**Code observé**

- Snapshot menu : `resources/js/helpers/kioskMenuCache.js:26-42` `saveSnapshot(categories, items)` (clé `kiosk_menu_snapshot_v1`, fallback LS)
- Persistance déclenchée uniquement après `fetchMenu()` réussie : `resources/js/store/modules/kioskMenu.js:263` `saveSnapshot(state.categories, state.items).catch(() => {})`
- Mutation broadcast `UPDATE_ITEM` : `kioskMenu.js:185-228` mute `state.items[idx]` en mémoire mais **ne réécrit pas le snapshot IDB**
- Le path offline (`kioskMenu.js:282-293`) lit `loadSnapshot()` quand `fetchMenu()` échoue — ne réécrit pas non plus

**Conséquence**

Si la borne reçoit un broadcast `ItemAvailabilityChanged` (item 86 = stock_rupture)
puis perd la connexion AVANT le prochain `fetchMenu()`, l'IDB conserve l'ancien
snapshot qui présente l'item comme disponible. Les commandes hors-ligne contenant
cet item seront acceptées localement et capturées en `conflict bucket` au reconnect
(409 ITEM_UNAVAILABLE). **Pas de perte d'order** (le bucket gère), mais le contrat
« mirror IDB synchronisé sur invalidation K-2 » n'est pas tenu littéralement.

**Mitigation possible** : ajouter `saveSnapshot(state.categories, state.items)` dans
`UPDATE_ITEM` (mutation patchée) ou exposer une action `actions.persistSnapshot` qui
serait appelée depuis le subscriber broadcast.

### V2 — Queue backoff 30 min + jitter + max retries documenté — ⚠️ PARTIAL

**Code observé**

- Schedule : `kioskOfflineQueue.js:43` `BACKOFF_SCHEDULE_MS = [5_000, 10_000, 20_000, 40_000, 80_000, 160_000, 300_000]`
- Budget total : `kioskOfflineQueue.js:46` `TOTAL_RETRY_BUDGET_MS = 30 * 60 * 1_000`
- Sélection palier : `kioskOfflineQueue.js:105-108` `Math.min(attempts, BACKOFF_SCHEDULE_MS.length - 1)` puis valeur fixe — **aucun jitter** (`Math.random()` absent du module hors `localKey`)
- Max retries / abandon : implicite via budget, géré par `_hasExhaustedBudget` (`:115-118`) et marquage `entry.abandoned = true` dans `syncQueue()` (`:253-258`)
- Test contractuel : `tests/js/kioskOfflineQueueK3.spec.js:46-87` (paliers monotones + budget ≥ 15 min + abandon ≥ 30 min)

**Verdict** : 30 min ✓ et abandon ✓ et constantes exposées via `__K3__` (`:354-357`),
mais **jitter explicitement requis par la fiche est absent**. Risque concret :
tempête de retries simultanés si N kiosks reconnectent ensemble (mass-reconnect
après panne backend), tous alignés sur le même `nextAttemptAt`.

### V3 — Conflict bucket : politique claire (UI message + logging) — ⚠️ PARTIAL

**Code observé**

- Classification : `kioskOfflineQueue.js:121-127` (`_classifyFailure` → 409 ITEM_UNAVAILABLE / 422 VALIDATION / 401 AUTH_EXPIRED, sinon retry)
- Persistance : `kioskOfflineQueue.js:240-247` (mute `entry.conflict`, comptabilise `conflictedNew`)
- API publique : `getConflictedOrders()` (`:179-188`) + `markConflictResolved(localKey)` (`:194-198`)
- Banner UI : `KioskOfflineBannerComponent.vue:25-32, 65, 78-80` — affiche **un compteur** ; classes `is-conflict` / message i18n `kiosk.offline_banner.conflict_one|many`
- Logging : `_reportAbandoned` (`:284-293`) émet `order_abandoned` sur `frontend/kiosk-event` ; **aucun event spécifique `conflict_created`** côté analytics
- Tests : `kioskOfflineQueueK3.spec.js:91-138` (3 codes mappés + résolution drop)

**Verdict** : la **mécanique** conflict bucket est complète et testée. Le **UX flow
de résolution** prévu par ADR-4 (« liste des items 86, bouton Re-sélectionner »)
n'est **pas implémenté** : le banner n'expose qu'un compteur statique, pas de modal
ni d'écran de résolution opérateur. Le risque AX9-02 documenté dans
`AUDIT_KIOSK_110_HARDWARE_OFFLINE_2026-04-19.md` (« Conflits = action manuelle
uniquement », `kioskOfflineQueue.js:191-197`) est confirmé.

### V4 — Circuit breaker testé (OPEN / HALF_OPEN / CLOSED) — ❌ FAIL strict

**Code observé**

- Aucune classe `CircuitBreaker` ni structure d'état à 3 niveaux dans `resources/js/`.
- Le seul mécanisme proche est le **latch online/offline** binaire de `networkStatus.js:48-76` (`_flipOffline` / `_flipOnline`) avec **debounce 3 s** (`:69-76`) sur la transition online et **flip immédiat** sur offline.
- Aucun compteur de N erreurs consécutives (l'axios interceptor flip dès le **premier** ERR_NETWORK : `KioskAppComponent.vue:266-269`).
- Aucun état `HALF_OPEN` (test ping de sortie de panne).
- L'expression « CB offline guard » du plan K-3 désigne **Carte Bancaire** (ADR-5, `kioskCart.js:348-353` `'CARD_OFFLINE_BLOCKED'`), **pas Circuit Breaker**.

**Verdict** : Si la fiche T14 attend un circuit-breaker au sens classique, c'est **FAIL**.
Si elle conflate « guard offline » et « circuit breaker », l'intention fonctionnelle
(éviter les retries sur backend KO) est **partiellement** assurée par le latch
networkStatus + `_isDueForRetry` (qui empêche les replays inutiles tant que
`nextAttemptAt > now`). Mais la sémantique stricte OPEN/HALF_OPEN/CLOSED **n'existe pas**.

### V5 — Service worker scope correct — ✅ PASS

**Code observé**

- Fichier : `public/kiosk-sw.js:1-92`
- Cache versioné : `:25` `'kiosk-shell-v1'` ; `activate` purge anciens (`:45-53`)
- Bypass explicite `/api/*` (`:62`), `/broadcasting/*` (`:63`), `/socket.io/*` (`:64`)
- Whitelist path : `/kiosk`, `/js/`, `/css/`, `/fonts/`, `/images/`, `/favicon.ico` (`:66-73`) — tout autre path retourne sans intercepter
- Registration : `KioskAppComponent.vue:256-261` — conditionnée par `(window.location.pathname || '').startsWith('/kiosk')` + `scope: '/kiosk'`
- Stratégie : network-first + fallback cache + fallback shell `/kiosk` pour `req.mode === 'navigate'` (`:75-91`)
- Risque résiduel documenté : AX8-01 / AX8-03 (pas de test headless du SW, possibles 404 silencieux sur addAll bundle Mix)

### V6 — Heal K-3.1 boot recovery présent — ✅ PASS

**Code observé**

- Front (hydration LS depuis IDB) : `kioskOfflineQueue.js:90-101` `_hydrateFromIdbIfEmpty` + appel **AVANT** le gate `getPendingCount` dans `startAutoSync.run()` (`:303-310`)
- Test boot recovery : `tests/js/kioskOfflineQueueK3BootRecovery.spec.js:33-64` (mock `idb-keyval`, IDB plein + LS vide → POST avec `X-Idempotency-Key='offline_boot_key'`)
- Backend (lock release sur idempotency hit) : `app/Services/FrontendOrderService.php:139-145`

  ```141:148:app/Services/FrontendOrderService.php
                  $this->loyaltyApplied = ($existing->discount > 0);
                  // [K-3 FIX] Release the lock before the early return. Otherwise
                  // a third replay would block against the still-held lock until
                  // its 10s TTL expires, producing sporadic latency / timeouts
                  // on kiosk offline queue reconcile bursts. Bug surfaced by
                  // OfflineQueueReplayIdempotencyTest::test_same_idempotency_key_replayed_10_times_creates_exactly_one_order.
                  optional($idempotencyLock)->release();
                  return $this->frontendOrder;
  ```

- Test PHPUnit : `tests/Feature/Frontend/OfflineQueueReplayIdempotencyTest.php` (replay N×, single row)

**Note** : la nomenclature « K-3.1 » de la fiche T14 est ambigüe. Dans le PLAN K-3,
K-3.1 = « IDB wrapper ». Le « heal boot recovery » est ce qui a été ajouté
post-audit (cf. VERIFY_K3 §2 ligne K-3.1) ainsi que le fix lock release backend.
Les deux artefacts sont présents.

### V7 — Whitelist `offline.*` analytics — ❌ FAIL

**Code observé**

- `resources/js/helpers/kioskAnalytics.js:37-137` `ALLOWED_EVENTS` Set
- Familles présentes : `wizard_*`, `payment_*`, `tpe_*`, `printer_*`, `camera_*`, `buzzer_*`, `perf.*` (K-5), `security.*` (K-6), `ui.*` (K-7), `observability.*` (K-9)
- **Aucune** entrée commençant par `offline.` (recherche grep `offline\.` → 0 match dans `kioskAnalytics.js`)
- Le plan K-3 ADR-7 différe explicitement « telemetry offline persistence » à K-9, mais la **whitelist** d'event names devrait néanmoins exister (cohérence K-9). Aucune trace.
- `kioskOfflineQueue.js:284-293` poste `type:'order_abandoned'` sur `frontend/kiosk-event` — c'est un kiosk-event **générique** routé via `KioskEventController.allowlist`, pas un event analytics tracké par `kioskAnalytics.track()`. Donc absent du dashboard K-9.

**Conséquence** : l'observabilité offline (queued / replayed / conflicted / abandoned /
recovered / boot_recovered) est **silencieuse** côté analytics. Seuls les abandons
sont visibles via l'endpoint `kiosk-event` générique.

---

## 4. Cohérence avec K-2 (broadcast item 86) et K-9 (analytics)

### K-2 (item 86 / dispo)

- Backend : 409 `ITEM_UNAVAILABLE` honoré (cf. ADR-4) → conflict bucket OK.
- Front : `UPDATE_ITEM` mute uniquement la mémoire ; snapshot IDB non resynchro.
  → V1 partial (cf. supra).

### K-9 (analytics)

- Aucune entrée `offline.*` dans la whitelist (V7 FAIL).
- Le plan ADR-7 délègue volontairement à K-9 mais sans poser la whitelist en avance
  → dette assumée mais **non cochée** par le critère T14.

---

## 5. Invariants projet — re-vérification

| Invariant | Résultat | Note |
|---|---|---|
| SSOT pricing backend | ✅ | `kioskOfflineQueue.js:230-234` replay envoie `entry.payload` brut + header idempotency ; aucun calcul prix |
| `branch_id` isolation | ✅ | Queue sans `branch_id` ; backend lit depuis le `KioskMachine` token (`FrontendOrderService.php:161-168`) |
| Idempotency global | ✅ | `Cache::lock('frontend_order_idempotency_'.sha1($key), 10)` + DB unique + 23000 fallback (`FrontendOrderService.php:131-146`) |
| EventContract V1 figé | ✅ | Aucun nouveau type d'event broadcast ; `order_abandoned` sur `kiosk-event` générique |
| OrderStateMachine | ✅ | Replay = `POST /api/frontend/order` standard, pipeline normal |

---

## 6. Tests présents

| Spec | Lignes | Couverture |
|------|--------|------------|
| `tests/js/kioskOfflineQueueK3.spec.js` | 185 | Backoff schedule, abandon 30 min, conflict 409/422/401, 20-orders scenario, idempotent partial |
| `tests/js/kioskOfflineQueueK3BootRecovery.spec.js` | 65 | Hydration IDB→LS au boot |
| `tests/js/kioskOfflineQueue.spec.js` | (pré-K3) | Base saveOrder/syncQueue/clear |
| `tests/js/kioskOfflineBanner.spec.js` | — | Visibility, counter, conflict |
| `tests/Feature/Frontend/OfflineQueueReplayIdempotencyTest.php` | — | Replay N×, 1 row, lock release |

**Trous identifiés** (cf. AX9-01..04 du `AUDIT_KIOSK_110_HARDWARE_OFFLINE`) :

- **AX9-01** : `public/kiosk-sw.js` non testé headless (Playwright + service worker fixture).
- **AX9-02** : conflict bucket = compteur seulement, pas de flux opérateur testé.
- **AX9-03** : retries pilotés par `Date.now()` ; aucun test de skew horloge client (NTP / clock drift).
- **AX9-04** : `/api/health/live` peu asserté (les specs `networkStatus.spec.js` injectent l'état, ne pingent pas réellement).
- **Aucun test** n'asserte la présence de la whitelist `offline.*` côté analytics (logique : elle n'existe pas).
- **Aucun test** Playwright reload offline pour valider que le shell SW ressuscite la borne.

---

## 7. Gates K-3 originaux — état au 2026-04-20

| # | Gate | Cible | Évidence | Statut |
|---|------|-------|----------|--------|
| G1 | 20 orders queue / reconcile | 0 perte | `kioskOfflineQueueK3.spec.js:140-159` | ✅ |
| G2 | IDB recovery | LS vide + IDB plein → replay | `kioskOfflineQueueK3BootRecovery.spec.js:33-64` | ✅ |
| G3 | Backoff monotonique | 7 paliers + budget 30 min | `__K3__` + spec | ✅ |
| G4 | Conflict 409/422/401 | bucket exposé, no retry | spec K3 + classify | ✅ |
| G5 | CB offline rejet | `CARD_OFFLINE_BLOCKED` | `kioskCart.js:342-353` | ✅ |
| G6 | Idempotency replay 10× = 1 order | lock release | `OfflineQueueReplayIdempotencyTest` | ✅ |
| G7 | Vitest non-régression | full suite | (pas réexécuté ici, audit READ-ONLY) | n/a |

**Note** : les 7 gates K-3 originaux sont satisfaits, mais la grille T14 ajoute
4 exigences (V1 mirror sync, V2 jitter, V3 UI résolution, V4 circuit breaker
formel, V7 whitelist analytics) qui ne le sont pas. La divergence vient d'une
attente plus stricte en T14 que ce que le plan K-3 avait formellement engagé.

---

## 8. Risques actuels (priorisés)

| ID | Risque | Sévérité | Probabilité | Conséquence |
|----|--------|----------|-------------|-------------|
| R1 | Pas de jitter backoff | Moyenne | Haute (mass-reconnect) | Tempête de POST simultanés au reconnect d'une flotte → backend saturé |
| R2 | Snapshot IDB non resynchro sur broadcast item 86 | Moyenne | Moyenne | Commande offline avec item 86 → 409 → conflict bucket → pas de perte mais UX dégradée |
| R3 | Whitelist `offline.*` absente | Moyenne | Certaine | Aveuglement K-9 sur durée moyenne offline / volume conflits / boot_recovered |
| R4 | UI conflict = compteur seul | Moyenne | Certaine | Opérateur doit deviner et inspecter manuellement (`getConflictedOrders()` API) ; aucune CTA Re-sélectionner pour client |
| R5 | Pas de circuit breaker formel à 3 états | Faible | Faible | Latch binaire + backoff per-entry suffisent en pratique ; risque résiduel = pas de cooldown global après N erreurs HTTP 5xx |
| R6 | Clock skew client | Faible | Faible | Documenté AX9-03, peut faire abandonner trop tôt |
| R7 | Service worker non testé Playwright | Moyenne | Documenté AX8-01 | Régression silencieuse possible (precache, scope) |

---

## 9. Top 3 actions recommandées (FAIL → remediation)

> Ces actions sont **proposées** ; aucune modification n'a été effectuée par cet audit.

### Action #1 — Whitelist `offline.*` dans `kioskAnalytics.js` (V7) — Sévérité **HAUTE**, effort **XS**

Ajouter aux `ALLOWED_EVENTS` (cf. `kioskAnalytics.js:37-137`) la famille minimale :

- `offline.queued` (saveOrder)
- `offline.replayed` (success in syncQueue)
- `offline.conflicted` (entry.conflict set)
- `offline.abandoned` (existant en kiosk-event générique → migrer ici)
- `offline.boot_recovered` (`_hydrateFromIdbIfEmpty` non-empty path)
- `offline.recovered` (transition `_flipOnline` après `_flipOffline`)

Puis poser les `track()` correspondants dans `kioskOfflineQueue.js` (saveOrder /
syncQueue success / classify / abandon / hydrate) et `networkStatus.js`
(`_flipOnline` source !== 'navigator-init').

→ Débloque V7, ouvre la mesure K-9 SLO offline (durée moyenne offline, N conflicts/jour, taux replay réussi).

### Action #2 — Jitter sur `_nextBackoffMs` (V2) — Sévérité **MOYENNE**, effort **XS**

Patcher `kioskOfflineQueue.js:105-108` :

```javascript
function _nextBackoffMs(attempts) {
    const idx = Math.min(attempts, BACKOFF_SCHEDULE_MS.length - 1);
    const base = BACKOFF_SCHEDULE_MS[idx];
    // K-3 ADR-3 — jitter ±20 % pour casser la corrélation mass-reconnect.
    const jitter = base * 0.2 * (Math.random() * 2 - 1);
    return Math.max(1_000, Math.floor(base + jitter));
}
```

Ajouter spec `kioskOfflineQueueK3.spec.js` : sur 100 entrées appellant `_nextBackoffMs(0)`,
asserter écart-type > 0 et plage `[4000, 6000]` ms. → Débloque V2.

### Action #3 — Snapshot IDB resync sur broadcast `ItemAvailabilityChanged` (V1) + flux UI conflict (V3) — Sévérité **MOYENNE**, effort **S**

(a) Dans `store/modules/kioskMenu.js`, après chaque `UPDATE_ITEM` mute, déclencher
une persistance débouncée (5 s) :

```javascript
// kioskMenu.js – nouvelle action
persistSnapshotDebounced({ state }) {
    clearTimeout(this._snapTimer);
    this._snapTimer = setTimeout(() => {
        saveSnapshot(state.categories, state.items).catch(() => {});
    }, 5000);
},
```

…et appeler depuis le subscriber broadcast Echo `ItemAvailabilityChanged`. → Débloque V1.

(b) Dans `KioskOfflineBannerComponent.vue`, ajouter un CTA « Voir » quand
`conflictCount > 0` qui ouvre une modal listant `getConflictedOrders()` avec, par
entrée :

- code (ITEM_UNAVAILABLE / VALIDATION / AUTH_EXPIRED) et libellé localisé
- bouton « Re-sélectionner » → vide partiellement le panier vers l'item bloqué + `markConflictResolved(localKey)`
- bouton « Annuler » → `markConflictResolved(localKey)` sans replay

→ Débloque V3 (UI résolution + traçabilité).

> V4 (circuit-breaker formel à 3 états) est laissé hors top 3 : le besoin
> opérationnel actuel est couvert par `_isDueForRetry` + latch networkStatus.
> Si la fiche T14 exige strictement la sémantique OPEN/HALF_OPEN/CLOSED, prévoir
> en T14b un wrapper `circuit.js` autour de `postFn` avec compteur per-host et
> cooldown 60 s.

---

## 10. Sortie

- **Verdict** : ❌ **FAIL** (3 V cochées sur 7 ; aucune mécanique offline cassée
  produisant perte d'order, mais le critère « 7 V cochées » de la fiche n'est pas atteint).
- **Suite recommandée** : T14b `generalPurpose` — appliquer Actions #1, #2, #3
  ci-dessus + tests rouges→verts ciblant V1, V2, V3, V7. V4 à arbitrer
  (interprétation stricte vs. sémantique fonctionnelle).
- **Auteur** : sous-agent `explore` (audit read-only).
