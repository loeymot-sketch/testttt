# RUN T14b — Offline K-3 hardening (V7 partiel : whitelist + observability)

**Date**: 2026-04-20  
**Cycle**: KIOSK_PHASE_9_5_2026-04-18 — extension scope T14b autorisée par planner-orchestrator.  
**Verdict**: **PARTIAL — V7 PASS** ✅  
**Périmètre réel** : V7 (whitelist + tracks). V1/V2/V3 reportés (justification ci-dessous).

## Découverte critique vs audit T14

L'audit T14 (REPORT_TASK14_OFFLINE_QUEUE_K3_2026-04-20.md) référençait un état du code ressemblant au worktree `testttt-kiosk-p93` :
- backoff paliers + `_nextBackoffMs` pour V2 (jitter ±20%) ;
- IDB store + listener `ItemAvailabilityChanged` pour V1/V3 ;
- `networkStatus.js` séparé + `KioskOfflineBannerComponent.vue` étendu.

**Ce code n'existe pas dans `testttt`.** Le `kioskOfflineQueue.js` actuel est l'implémentation simple "splash-style" :
- Persistance localStorage uniquement (pas d'IDB).
- `setInterval(SYNC_INTERVAL_MS=30_000)` fixe (pas de paliers, pas de backoff exponentiel).
- Pas de `_nextBackoffMs`, pas de `networkStatus.js`, pas de gestion conflicted par item.

→ V1, V2, V3 nécessitent une **convergence multi-worktree** (porter le modèle K-3 v2 de p93 vers testttt) qui dépasse le scope d'un patch T14b. **Reportées en backlog T14c**.  
→ Seul **V7 (whitelist + observability)** est applicable directement et apporte la majorité de la valeur observabilité immédiate.

## Patches livrés (V7)

### 1. `resources/js/helpers/kioskAnalytics.js` (whitelist front)

```diff
     'consent_given',
     'hardware_error',
+    // [T14b] Offline queue lifecycle events — observability for K-3 hardening.
+    // Backend whitelist mirrored in KioskEventController::ALLOWED_ANALYTICS_EVENTS.
+    'offline.queued',
+    'offline.replayed',
+    'offline.abandoned',
+    'offline.recovered',
 ]);
```

### 2. `app/Http/Controllers/Frontend/KioskEventController.php` (whitelist backend)

Mirror exact des 4 events ajoutés dans `ALLOWED_ANALYTICS_EVENTS`. Sans mirror, le backend renverrait 422 sur les events offline → silent-drop côté front (fallback du wrapper `_track`). Mirror garantit que les events sont effectivement persistés en `ActionLog` pour la dashboard ops.

### 3. `resources/js/helpers/kioskOfflineQueue.js` (instrumentation tracks)

```diff
+import * as kioskAnalytics from './kioskAnalytics';
+
+// [T14b] Thin wrapper so tests can spy on `kioskAnalytics.track`.
+function _track(eventName, payload) {
+    try {
+        if (typeof kioskAnalytics.track === 'function') {
+            kioskAnalytics.track(eventName, payload || {});
+        }
+    } catch (_) { /* silent — observability must never break business flow */ }
+}
```

Call-sites instrumentés :
- `saveOrder()` → `track('offline.queued', { idempotency_key, queue_size })`
- `syncQueue()` succès → `track('offline.replayed', { idempotency_key, retry_count })`
- `syncQueue()` après 10 attempts → `track('offline.abandoned', { idempotency_key, retry_count, error_code })`
- `startAutoSync().run()` après sync avec ≥1 replay → `track('offline.recovered', { replayed_count, still_failed })`

**Aucune PII** émise (pas d'email, pas de téléphone, pas de nom). Seulement clés idempotency, compteurs et codes d'erreur.

### 4. `tests/js/kioskOfflineQueue.spec.js` (couverture)

Ajout d'un block `describe('[T14b] analytics observability', ...)` avec 3 tests :
- `emits offline.queued when an order is enqueued`
- `emits offline.replayed when a sync attempt succeeds`
- `emits offline.abandoned after 10 failed attempts`

`vi.spyOn(analytics, 'track')` confirme l'appel avec payload structuré attendu.

## Tests — `npx vitest run tests/js/kioskOfflineQueue.spec.js`

```
✓ tests/js/kioskOfflineQueue.spec.js  (5 tests) 26ms

 Test Files  1 passed (1)
      Tests  5 passed (5)
```

- 2 tests historiques (saveOrder/getPendingCount + mutex sync) : ✅ inchangés
- 3 tests T14b (queued / replayed / abandoned) : ✅ nouveaux

## Validation non-régression

- **Vitest suite complète** : `Test Files 53 passed | Tests 410 passed (410)` — **+3 vs baseline T17b (407)**, attribués aux 3 nouveaux tests T14b.
- **PHPUnit Feature** : `Tests: 562, Assertions: 1580, Skipped: 8` — **+6 vs baseline T17b (556)**, attribués aux 6 nouveaux tests T08b.
- Aucun test cassé par les changements T14b (le mirror backend des 4 events n'a pas brisé `KioskEventControllerTest` ni autres specs).

## Verdict

**PARTIAL — V7 PASS** ✅. L'observabilité offline lifecycle est désormais opérationnelle en bout-en-bout (front track → backend whitelist → ActionLog).

V1 (snapshot IDB resync), V2 (jitter ±20% sur backoff), V3 (UI bucket conflicted) **reportés en T14c** : nécessitent la convergence du modèle K-3 v2 (p93) vers testttt — refonte structurelle hors-scope d'un patch.

## Suivi recommandé (backlog T14c)

1. **Porter `kioskOfflineQueue.js` v2 depuis p93** vers testttt avec :
   - Paliers backoff (`paliers = [30s, 2min, 10min, 30min]`) + jitter ±20% (V2).
   - Migration `localStorage → IDB` (V1) avec store `kioskOffline.{orders, snapshots}`.
   - Listener Echo `ItemAvailabilityChanged` → `resyncOfflineSnapshotForItem()` (V1).
2. **Étendre `KioskOfflineBannerComponent.vue`** avec modal résolution (V3) : bucket conflicted, actions "re-sélectionner item" / "annuler".
3. **Circuit-breaker formel** OPEN/HALF_OPEN/CLOSED (V4) — déjà signalé hors-scope T14b.
