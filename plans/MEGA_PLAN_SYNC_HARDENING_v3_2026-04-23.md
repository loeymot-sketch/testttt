# MEGA-PLAN — Sync POS ↔ Kiosk ↔ KDS Hardening v3

**Date** : 2026-04-23
**Auteur orchestrateur** : Claude Opus (cerveau)
**Implémenteur principal** : GPT-5.4-pro (codex runner) via `agents/codex.runner.mjs`
**Auditeur indépendant** : Claude Code CLI (`scripts/foodking-claude-orchestrate.sh`) sur chaque lot critique
**Source** :
- `reports/audit/AUDIT_MASSIF_POS_KIOSK_KDS_SYNC_2026-04-23.md` (25 findings classés P0/P1/P2)
- `reports/audit/SECOND_OPINION_GPT54PRO_SYNC_PLAN_2026-04-23.json` (NEW-01..05 + reclassifs)
- `plans/PLAN_POS_KIOSK_KDS_SYNC_REPAIR_v2_2026-04-23.md` (plan v2 consolidé)
- `memory/episodes/12_decisions_log.jsonl` (décisions G-1..G-5 + frozen zone F-21)

---

## 0. Méthodologie d'orchestration (gold standard)

```
[Orchestrator Claude Opus]
      ├─ décisions architecturales / gates / arbitrages
      ├─ rédaction missions/<TASK>/input.json (contrat machine-lisible)
      │
      ├──▶ [GPT-5.4-pro via codex.runner.mjs]
      │        ├─ génère output_codex.json (code_blocks + diff + tests + risks)
      │        └─ artefact lu par orchestrator
      │
      ├─ Patch chirurgical par orchestrator (tables, exceptions, cohérence projet)
      ├─ Tests sentinelles (vitest + phpunit + invariants)
      │
      ├──▶ [Claude Code CLI via foodking-claude-orchestrate.sh]
      │        ├─ audit indépendant (lecture filesystem entière)
      │        ├─ génère reports/audit/AUDIT_<LOT>_<DATE>.md
      │        └─ flag findings résiduels
      │
      └─ orchestrator résout findings audit, commit memory log, passe au lot suivant
```

**Règles invariantes** :
- Aucun lot ne ferme sans `bash scripts/check-invariants.sh` clean.
- Aucun lot ne ferme sans suite phpunit + vitest verts.
- Chaque lot écrit une entrée dans `memory/episodes/12_decisions_log.jsonl`.
- Frozen zones (F-21 style) : ne jamais toucher sans gate orchestrator + doc dédiée dans `tasks/gates/`.
- Toute modification d'event class doit garder `DispatchableAfterCommit` et passer invariant 4/6.
- Toute nouvelle event broadcast doit être ajoutée à `EventContract::BROADCAST_MAP` + `eventContract.js` + `EventType::all()`.

---

## 1. État au début du méga-plan (Vague 1 livrée)

| Lot | Finding | Statut | Fichiers clés | Tests verts |
|-----|---------|--------|---------------|-------------|
| 1.G | F-21 (P0 promu) | ✅ closed | `app/Services/FrontendOrderService.php` | KioskPaymentStateMachineTest |
| 1.E | F-12 | ✅ closed | `WebSocketService.js`, `bootstrap.js`, `ConnectionStatusBanner.vue`, i18n | wsAuthExpired + wsAuthRefreshLoop |
| 1.A | F-04bis | ✅ closed | `PersistItemAvailabilityChangedToOutbox.php`, `PosComponent.vue`, `KioskAppComponent.vue` | posItemAvailabilityHandler + AvailabilityControllerTest |
| 1.B.1 | F-01 + NEW-05 | ✅ closed | `OrderCanceled`, `RefundCreated`, `Release*` listeners, `releaseForOrderItems` | StockReleaseTest (5/5) |
| 1.B.2 | F-01 wiring | ✅ closed | `OrderService`, `FrontendOrderService`, `CleanupStalePendingKioskOrders` | full regression |
| 1.D | F-02 | ✅ closed | `OrderTableChanged`, `PersistOrderTableChangedToOutbox`, KDS flash CSS | FloorplanControllerTest (3 new) |

**Métriques sortie Vague 1** : 869 tests verts, 8 skipped (MySQL-only), 6/6 invariants clean, 0 régression.

---

## 2. VAGUE 2 — Lot 1.C (F-03) : KDS adaptive polling fallback

> **Pourquoi P0** : quand WebSocket tombe (Pusher down, réseau borne, token expiré), le KDS devient aveugle aux nouvelles commandes. La file s'allonge, la cuisine ne sait pas. Le timer audit est passé d'un fallback "30s polling fixe" jugé trop lent à un **polling adaptatif avec version-gate**.

### 2.1 Décisions techniques (orchestrator pre-locked)

- **Cadence adaptative** :
  - WS `CONNECTED` → polling désactivé (interval = ∞), 1 ping toutes les 60s pour drift detection.
  - WS `RECONNECTING` ou `DEGRADED` → polling actif `5s + jitter(0..2s)`.
  - WS `DISCONNECTED` ou `SESSION_INVALID` → polling actif `10s + jitter(0..3s)` (préserve la batterie tablette).
  - Activité kiosk élevée (création > 5 ordres/min observés) → polling 3s même en DEGRADED.
- **Version-gate** : chaque card KDS porte `version = max(updated_at, status_changed_at)`. Le polling NE remplace UNE card que si `serverVersion > clientVersion`. Évite les overwrites du WS arrivé entretemps.
- **Dedupe par (id, version)** : un Set local côté store évite de re-render une carte déjà à jour.
- **Backoff** : sur 503/504 du polling, doubler l'interval jusqu'à 30s max, puis revenir à la cadence normale dès qu'une réponse 2xx revient.
- **Resync stamp** : à chaque polling 2xx, écrire `lastSyncAt` dans le store. Un badge `Synchronized · 8s ago` est affiché en bas du KDS pour rassurer la cuisine.

### 2.2 Backend

- Nouveau endpoint `GET /api/admin/kds/sync` :
  - Query `?branch_id={id}&since={iso8601}` (last sync stamp client).
  - Retourne `{ orders: [...], deleted_ids: [...], server_now: iso8601, version: int }`.
  - **Branch isolation** : MUST filter by `branch_id = $request->user->branch_id` (or admin override).
  - Cache: `Cache::remember("kds.sync.{branch_id}.{since_minute}", 5)` pour absorber les pics.
- Tests sentinelles backend (PHPUnit) :
  1. `kds_sync_returns_orders_updated_since`.
  2. `kds_sync_returns_deleted_ids_for_canceled_orders`.
  3. `kds_sync_isolated_per_branch` (cross-branch leak guard).
  4. `kds_sync_server_now_advances_monotonically`.

### 2.3 Frontend

- Nouveau service `resources/js/services/KdsSyncService.js` :
  - Singleton, expose `start(branchId)`, `stop()`, `forceSync()`.
  - Lit `wsService.state` pour ajuster la cadence.
  - Émet `kds:sync` event au document avec payload `{orders, deleted_ids, version}`.
- `KitchenDisplaySystemComponent.vue` :
  - Subscribe au `kds:sync` event au mount, unsubscribe au unmount.
  - Sur réception : merge avec store en respectant version-gate.
  - Affiche `lastSyncAt` (relatif: "8s ago") et badge état.
- Tests Vitest :
  1. `kdsSyncService.spec.js` — cadence change selon WS state (mock wsService).
  2. `kdsVersionGate.spec.js` — older version is rejected.
  3. `kdsDedupeByIdVersion.spec.js` — pas de double-render.
  4. `kdsBackoff5xx.spec.js` — interval double sur 503, revient à normal sur 200.

### 2.4 Routing exécution

| Étape | Owner | Output |
|-------|-------|--------|
| Mission `T-LOT-1C-KDS-ADAPTIVE-POLL` | Orchestrator | `missions/T-LOT-1C-KDS-ADAPTIVE-POLL/input.json` |
| Génération code | GPT-5.4-pro | `output_codex.json` (controller + service JS + tests) |
| Patch + cohérence projet | Orchestrator | edits in repo |
| Sentinelles + invariants | Orchestrator | tests verts |
| **Audit indépendant** | Claude Code CLI | `reports/audit/AUDIT_LOT_1C_<date>.md` |
| Résolution findings | Orchestrator | follow-up edits |
| Memory log | Orchestrator | `memory/episodes/12_decisions_log.jsonl` |

**Critères d'acceptation 1.C** :
- Polling pause complète quand WS = `CONNECTED`.
- Cadence augmente automatiquement quand WS dégradé.
- Aucun overwrite de card plus récente par polling plus ancien (version-gate prouvé par test).
- Backoff sur 5xx prouvé par test.
- Branch isolation : test cross-branch retourne 403 ou liste vide.

---

## 3. VAGUE 3 — Phase 1bis : robustesse infra (NEW-01 → NEW-04)

### 3.1 NEW-01 — Outbox replay/dedupe consumer-side

**Risque réel** : `DispatchDomainEventsJob` peut être réessayé par la queue (timeout, OOM). Les listeners `Persist*ToOutbox` sont déjà côté producteur. Mais côté consumer (Echo broadcast → Pusher), si le worker reprend après crash entre `mark_dispatched` et le réel push, on rebroadcast.

**Solution** :
- Ajouter `correlation_id` UUID v4 unique dans chaque envelope V1 (déjà présent en partie).
- Côté JS `eventContract.js` : déjà un LRU 512 (`isDuplicateCorrelation`) — étendre :
  - Persist en `sessionStorage` pour survivre au reload.
  - Capacité 2048 avec TTL 10min.
- Côté backend `DispatchDomainEventsJob` :
  - Avant `Broadcast::on(...)`, vérifier `dispatched_at IS NULL` dans une transaction `lockForUpdate`.
  - Si `dispatched_at` est déjà set → no-op + log.

**Tests** :
- `OutboxReplayDedupeTest::test_double_dispatch_is_idempotent`.
- `OutboxReplayDedupeTest::test_concurrent_workers_only_one_broadcasts`.
- JS : `correlationDedupePersistence.spec.js`.

### 3.2 NEW-02 — Reconnect storm throttle (Echo)

**Risque** : à la coupure WiFi de la borne, Pusher reconnect tente toutes les 1s → spam serveur + auth flood.

**Solution** :
- `WebSocketService.js` :
  - Implémenter `exponential backoff with full jitter` : 1s → 2s → 4s → 8s → 16s → 30s (cap).
  - Reset à 1s après 60s de connexion stable.
  - Émet event `ws:reconnect_throttled` si > 5 tentatives sur 60s.
- Bannière `ConnectionStatusBanner` : si throttled, message "Reconnexion en attente... essayez de redémarrer la borne si persiste".

**Tests Vitest** :
- `wsReconnectBackoff.spec.js` — vérifie séquence 1/2/4/8/16/30 et reset.
- `wsReconnectThrottle.spec.js` — émet event après 5 tentatives.

### 3.3 NEW-03 — Queue scalability

**Risque** : tout va dans la queue par défaut. En rush, `DispatchDomainEventsJob` est noyé sous des `SendOrderMail` lents.

**Solution** :
- `DispatchDomainEventsJob` : déjà `->onQueue('high')`. Vérifier que `php artisan queue:work --queue=high,default` est bien le mode Forge/Octane.
- Documenter dans `docs/operations/QUEUE_TOPOLOGY.md`.
- Ajouter `tries = 5`, `backoff = [1, 5, 15, 60, 300]` sur `DispatchDomainEventsJob` pour ne pas perdre un broadcast en cas d'incident Pusher.
- Failed job hook : log structuré + Sentry breadcrumb.

**Tests** :
- `DispatchDomainEventsJobRetryTest::test_retries_with_exponential_backoff_on_pusher_failure`.

### 3.4 NEW-04 — Observability (metrics + correlation_id end-to-end)

**Risque** : aucune métrique business sur le sync. Quand quelqu'un dit "le KDS rame", on n'a pas de chiffres.

**Solution** :
- Backend (Laravel) :
  - Middleware `EnsureCorrelationId` sur `/api/admin/*` et `/api/frontend/*` : injecte X-Correlation-ID si absent, le propage au logger contexte (déjà fait pour outbox, généraliser).
  - Service `App\Services\Observability\SyncMetricsRecorder` :
    - `recordEventDispatched($eventType, $branchId, $latencyMs)`.
    - `recordWebSocketAuthFailure($branchId)`.
    - `recordKdsSyncFallback($branchId, $intervalMs)`.
  - Backend storage : table `sync_metrics` (rolling 7d) ou Redis HSET si dispo.
  - Endpoint admin `GET /api/admin/observability/sync-overview` (graphique simple par branche).
- Frontend :
  - `WebSocketService` : émet `ws:metric` events avec timing data → relayé à backend par batch toutes les 60s via `POST /api/admin/observability/client-metrics`.

**Tests** :
- `SyncMetricsRecorderTest`.
- `SyncOverviewControllerTest`.

### 3.5 Routing exécution Phase 1bis

| Lot | GPT mission | Claude Code audit | Critère sortie |
|-----|-------------|-------------------|----------------|
| NEW-01 | `T-NEW01-OUTBOX-DEDUPE` | oui | 0 double broadcast en stress test |
| NEW-02 | `T-NEW02-RECONNECT-THROTTLE` | oui | suite WS verte, séquence backoff prouvée |
| NEW-03 | `T-NEW03-QUEUE-SCALABILITY` | non (config + tests) | retry test vert, doc updated |
| NEW-04 | `T-NEW04-OBSERVABILITY` | oui | endpoint admin retourne data, dashboard utilisable |

---

## 4. VAGUE 4 — Phase 2 (P1)

### 4.1 Lots P1 (du plan v2)

| Lot | Finding | Domaine | Owner |
|-----|---------|---------|-------|
| 2.A | F-05 nuance | POS payment retry idempotence | GPT |
| 2.B | F-06 | Kiosk hung modal recovery | GPT |
| 2.C | F-07 nuance | KDS sound throttling | orchestrator (UI mince) |
| 2.D | F-08 | POS receipt re-print after 409 | GPT |
| 2.E | F-09 | Kiosk QR scanner timeout UX | orchestrator |
| 2.F | F-10 | KDS station filter persistence per user | GPT |
| 2.G | F-11 | POS parked orders TTL | GPT |
| 2.H | F-13 | Kiosk loyalty redemption race | GPT |
| **2.I** | F-14 + F-15 | **Allergens UI (badge G-4 + split lignes G-5)** | GPT (gates appliquées) |
| 2.J | F-16 | Floorplan release on payment complete | orchestrator |

### 4.2 Focus 2.I (allergens — gates pré-décidés)

- **G-4 (badge non bloquant)** : carte KDS affiche pictogramme `⚠️ ALLERGENS` rouge en haut. Click sur badge → modal détail. Pas de blocage du flow normal.
- **G-5 (split lignes)** : lignes avec allergènes différents NE sont JAMAIS groupées dans la même card visuelle, même si `item_id` identique. Ajouter clé de regroupement `allergens_hash` côté KDS.

**Tests sentinelles** :
- `KdsAllergensBadgeRenderTest` (vitest).
- `KdsAllergensSplitGroupingTest` (vitest).
- `OrderItemAllergensSnapshotIntegrityTest` (phpunit) — vérifie que `allergens_snapshot` est bien copié au moment de la création (déjà existant : étendre).

### 4.3 Routing Phase 2

- 2.I en premier (gates pré-validées, valeur sécurité alimentaire haute).
- 2.A + 2.B + 2.D groupés en mission GPT unique `T-LOT-2-PAYMENT-RECOVERY` (cohérence flow paiement).
- 2.F + 2.G + 2.H en mission `T-LOT-2-KDS-POS-PERSISTENCE`.
- Audit Claude Code unique en fin de Phase 2 sur tout le batch.

---

## 5. VAGUE 5 — Phase 3 (dette technique)

| Item | Description | Owner |
|------|-------------|-------|
| D-01 | Renommer `item_branch_availability` → singular partout (déjà OK, vérifier docs) | orchestrator |
| D-02 | Supprimer 3 commentaires obsolètes "TODO Phase 8" dans POS | orchestrator |
| D-03 | Migration `add_index_on_orders_branch_status_updated_at` | GPT |
| D-04 | Convertir `OrderStatus` constants → vrai PHP enum | GPT (gros refacto) |
| D-05 | Extraire `OrderStateMachine::canTransition` en table de transitions | GPT |
| D-06 | Tests E2E Playwright KDS adaptive sync | GPT |
| D-07 | Doc `docs/architecture/REALTIME_SYNC.md` consolidée | orchestrator |
| D-08 | Cleanup `legacy/` folder dans resources/js | orchestrator |
| D-09 | Bump deps: Vue 3.4 → 3.5 (vérifier breaking changes Options API) | manuel |

D-04 et D-05 sont gros — à valider avant lancement (proposer en gate spécifique).

---

## 6. Critères de sortie globaux MEGA-PLAN

À l'issue des Vagues 2 → 5, l'orchestrator vérifie :

1. **Tests** :
   - `php artisan test` : 100% green (allowance pour 8 skipped MySQL-only).
   - `npx vitest run` : 100% green.
   - `bash scripts/check-invariants.sh` : 6/6 OK.
2. **Smoke E2E manuel ou Playwright** :
   - Créer commande POS → apparaît KDS en < 2s.
   - Créer commande Kiosk → apparaît KDS en < 2s.
   - Cancel POS → release stock + disparaît KDS en < 2s.
   - Transfer table → KDS flash + nouveau label en < 2s.
   - Couper WiFi 30s → polling fallback prend le relais sans message d'erreur intrusif.
   - Modifier 86 d'un item depuis admin → POS et Kiosk reflètent en < 1s.
3. **Documentation** :
   - `docs/architecture/REALTIME_SYNC.md` (D-07) à jour.
   - `tasks/gates/` listent toutes les frozen zones touchées avec justification.
4. **Memory & decisions** :
   - `memory/episodes/12_decisions_log.jsonl` contient une entrée par lot.
   - `reports/audit/AUDIT_FINAL_<date>.md` produit par Claude Code, archivé.
5. **Rapport final** :
   - `reports/MEGA_PLAN_v3_REPORT_<date>.md` — before/after par finding, métriques, screenshots si UI.

---

## 7. Calendrier indicatif (orchestrator-paced)

| Vague | Lots | ETA |
|-------|------|-----|
| Vague 2 | 1.C | maintenant |
| Vague 3 | NEW-01..04 | enchaîné |
| Vague 4 | 2.I → 2.A/B/D → 2.F/G/H → 2.C/E/J | enchaîné |
| Vague 5 | D-01..09 (sauf D-04/D-05 gates) | enchaîné |
| Audit final + rapport | tout green | clôture |

---

## 8. Risques & contre-mesures

| Risque | Contre-mesure |
|--------|---------------|
| GPT introduit tables inexistantes (cf. `item_branch_availabilities` plural) | Orchestrator post-process review obligatoire avant write |
| GPT crée des factories/helpers absents (cf. `OrderItemFactory`) | Vérifier `database/factories/*` avant exécution mission |
| Frozen zone touchée sans gate | invariant 4/6 + reviews orchestrator |
| Régression silencieuse sur tests skipped MySQL | `phpunit.yml` CI doit passer en MySQL avant merge |
| Conflit merge entre lots parallèles | exécution séquentielle stricte par vague (pas de parallélisme cross-vague) |
| Perte contexte mémoire entre sessions | `memory/episodes/12_decisions_log.jsonl` + ce mega-plan = single source of truth |

---

**Fin MEGA-PLAN v3.** Orchestrator démarre Vague 2 (Lot 1.C) immédiatement.
