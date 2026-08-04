# GOAL — Synchronisation temps-réel MAX (robuste · dynamique · smart)

**Mission slug** : `SYNC_MAX_ROBUSTESSE_TEMPS_REEL` · **Date** : 2026-08-04 · **Statut** : PLAN-ONLY (validation owner requise avant exécution).

> Fondé sur l'audit cross-surface 2026-08-04 (`reports/goal-sync-2026-08-04/` : RED-outbox-delivery, RED-cross-surface, CONVERGENCE). Ces 2 auditeurs adversariaux = la consultation de design de ce GOAL. **Verdict d'audit** : cœur statut/board/stock/prix SAIN (0 P0) ; la dette de fond = la dimension **push temps-réel** (des events diffusés SANS abonné client, rattrapés par le polling). Ce GOAL transforme « polling qui rattrape » en « push complet, dynamique et observé ».

---

## §0 — Préambule

### §0.1 Décision working-tree
6 commits sync LOCAUX sur `pos/category-first-caisse-2026-06-23` (`06e5f1c03`→`9a33dd353`, HEAD `9a33dd353`), **non déployés** (gate G1). Ce GOAL démarre APRÈS le déploiement de ces 6 commits (le marqueur `broadcast_at` + la garde extra/variation + le push auto-prepare sont le socle des waves ci-dessous). Aucun WIP à stasher : arbre propre côté code.

### §0.2 Périmètre (2 dépôts)
- **Backend testttt** (central V1) : outbox `DomainEvent`/`DispatchDomainEventsJob`/soketi/Pusher + surfaces `resources/js` (POS/KDS/OSS/borne) + observabilité + health.
- **Web standalone** `lecayenne-web-deploy` (déployé Vercel, SÉPARÉ — cf. [[piege_miroir_web_canonique_faux]]) : son propre `eventContract.js`/`api.js` de polling. NO API wireup interdit hors gate owner ; ici on n'ajoute QUE du polling de disponibilité (lecture) — jamais de couplage nouveau.

### §0.3 Règles dures (immuables)
- **NF525** (CLAUDE.md §8) : `domain_events` est un outbox OPÉRATIONNEL, JAMAIS un registre fiscal. `audit_logs`/`z_reports` (6 ans) NE SONT JAMAIS touchés par la synchro. Attestation chaîne à chaque clôture de wave si un event fiscal-adjacent est touché.
- **Frozen zones** (CLAUDE.md §7, `memory/reference_frozen_zones.md`) : aucun fichier frozen touché par ce GOAL (la synchro vit hors des 13 fichiers frozen). Si une tâche frôle un frozen → `lock-plan` + gate owner.
- **Broadcasts = advisоры** : un broadcast raté/dupliqué ne corrompt JAMAIS l'état (la DB est SSOT, le poll rattrape). Invariant : « 0 perte de commande » (matrice C-01..C-11, cf. `plans/GOAL_V1_CORE_BULLETPROOF_2026-06-04.md`).

### §0.4 Pipeline par tâche & convergence
Chaque tâche s'exécute via `ultra-audit-profond` (14 étapes, non re-décrites ici). Frozen-touch → `lock-plan`. **Convergence = 2 cycles adversariaux consécutifs P0+P1=0 avec set de findings identique** (règle `test-e2e`). Chaque wave se ferme sur le protocole 6-points (Axis 3).

### §0.5 Nord de la mission
Faire passer la synchro de **« correcte mais aveugle au push »** à **« complète, auto-adaptative, et prouvée »** :
1. **Complète** : tout event métier utile a un abonné client réel (fin des events fantômes).
2. **Dynamique** : le client s'adapte à la santé du transport (WS sain → poll détendu ; WS dégradé → poll resserré ; split-brain/worker-down → détecté + dégradé proprement).
3. **Smart** : chaque maillon est observé (heartbeat, métriques, delivery-marker `broadcast_at`) et le contrat d'enveloppe est enforced ; complétude catalogue (86 extra/variation propagé partout).

---

## §1 — Map principal : le SYSTÈME SYNCHRONISATION

### Contrat
Propager en TEMPS-RÉEL les changements d'état (commande, paiement, statut cuisine, disponibilité, réglages, branche) entre borne ↔ caisse ↔ KDS ↔ OSS ↔ web, via un **outbox transactionnel** (`domain_events`) drainé par un worker vers **soketi/Pusher** sur `private-branch.{id}`, avec **polling de repli** par surface (POS 30 s / KDS 5-60 s / Kiosk 15 s). La DB reste SSOT ; le bus couvre les ÉTATS, jamais les RÈGLES métier (cf. [[goal_s3_synchro_totale_2026-07-29]]).

### Maturité (post-audit 2026-08-04)
- ✅ Outbox transactionnel commit-before-dispatch + retry/backoff + DLQ contract-violation.
- ✅ **Delivery-marker `broadcast_at`** (SYNC-P2-1) : orphelin claim-sans-livraison récupéré, prune ne perd plus un non-livré.
- ✅ Parité disponibilité item + extras/variations (garde backend cross-surface P1).
- ✅ Push auto-prepare ACCEPT→PREPARING (cross-surface P2).
- ⚠️ **Dette** : 27 events persistés outbox vs ~6 dans `BROADCAST_MAP` client → events diffusés sans abonné (refund/settings/branche/extra-variation-availability). Polling adaptatif absent. Split-brain soketi non gardé. B8 (MenuSnapshot ne bump pas au 86 extra/variation).

### Anchors (vérifiés 2026-08-04)
```
# Outbox core (ls OK)
app/Models/DomainEvent.php · app/Jobs/DispatchDomainEventsJob.php
app/Console/Commands/{OutboxRescueCommand,MonitorOutboxStaleness,PruneOutboxCommand,OutboxRetryFailedCommand}.php
# Contrat + émetteurs→outbox (37 maps EventServiceProvider ; 11+ Persist*ToOutbox)
app/Domain/Events/EventContract.php · app/Providers/EventServiceProvider.php (155..340)
# Abonnés client
resources/js/services/eventContract.js (BROADCAST_MAP:18-24) + eventContract.schema.json
resources/js/composables/useCatalogChangeNotifier.js · pos-app.js
resources/js/components/admin/pos/PosOrdersTrackerComponent.vue (+ PosComponent, Encaissement, OSS PreparingAndReady, Kiosk*)
# Observabilité + santé
app/Http/Controllers/Admin/Observability/SyncOverviewController.php
app/Services/Observability/SyncMetricsRecorder.php
app/Http/Controllers/{HealthController,HealthzController}.php · Admin/PosSystemHealthController.php
# Config + snapshot + dispo
config/broadcasting.php · app/Listeners/BumpMenuSnapshotOnItemAvailabilityChanged.php (EventServiceProvider:239)
app/Services/Menu/AvailabilityService.php · SYNC_CONTRACT.md
```

### Décomposition en 4 sub-systèmes
- **Sub 1.1 — Complétude du PUSH** (fermer les events fantômes)
- **Sub 1.2 — Synchro DYNAMIQUE** (adaptatif santé transport + split-brain + worker-down)
- **Sub 1.3 — Observabilité & contrat SMART** (heartbeat SSOT + métriques + EventContract + delivery-marker)
- **Sub 1.4 — Robustesse & preuve cross-surface** (matrice de panne + E2E de bout en bout)

---

### Sub 1.1 — Complétude du PUSH (fin des events fantômes)

**Anchors** : `resources/js/services/eventContract.js:18` (BROADCAST_MAP — 6 events) · `app/Providers/EventServiceProvider.php:197/322/331/251/266` (OrderPaymentStatusChanged / SettingsUpdated / BranchStatusChanged / ItemExtra / ItemVariation persistés) · `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php` · `app/Services/Menu/AvailabilityService.php:597-620` (isExtra/VariationAvailable).

**Tasks**
- **T-1.1.1** Abonner le client à `OrderPaymentStatusChanged` (refund / flip paiement) — l'ajouter à `BROADCAST_MAP` + handler qui rafraîchit la carte de suivi/board sur refund. Aujourd'hui diffusé, 0 abonné → refund visible seulement au poll.
   • anchor: `resources/js/services/eventContract.js:18-24` + `PosOrdersTrackerComponent.vue`
   • test: `tests/Feature/Refund/RefundBroadcastsPaymentStatusChangedTest.php` (existe, prouve l'émission) + (test TO BE CREATED at `tests/js/eventContractPaymentStatusHandled.spec.js` — assert BROADCAST_MAP contient OrderPaymentStatusChanged + handler câblé)
- **T-1.1.2** Sentinelle de COMPLÉTUDE d'enveloppe : tout `broadcast_as` émis par un `Persist*ToOutbox` DOIT être soit dans `BROADCAST_MAP`, soit dans une allowlist EXEMPTED explicite (raison documentée). Empêche une future dérive « event diffusé jamais écouté ».
   • anchor: `app/Providers/EventServiceProvider.php:155-340` ↔ `resources/js/services/eventContract.js:18`
   • test: (test TO BE CREATED at `tests/js/eventContractCompletenessSentinel.spec.js` — cross-check la liste des broadcast_as vs BROADCAST_MAP+EXEMPTED, baseline-lock)
- **T-1.1.3** Push disponibilité EXTRA/VARIATION vers le WEB — le web ne poll que l'item-level ; la garde backend rejette déjà (P1 07-31→08-04) mais l'UX aveugle laisse le client aller au checkout. Étendre le poll de dispo web (repo standalone, lecture seule) aux extras/variations 86 (griser proactivement).
   • anchor: web `api.js`/`index.html` (repo `lecayenne-web-deploy`) + `AvailabilityService::isExtraAvailable`
   • test: `tests/Feature/Menu/AvailabilityServiceTest.php` (garde backend, déjà verte) + (spec web TO BE CREATED at `<lecayenne-web-deploy>/tests/extraAvailabilityGreyout.spec.js`)
- **T-1.1.4** `SettingsUpdated` + `BranchStatusChanged` : décider par event — abonner (TVA/devise/order-setup live) OU marquer EXEMPTED-mono-branche V1 (raison : 422 fail-safe + 1 branche). Honorer l'intention documentée `EventServiceProvider:220-222`.
   • anchor: `app/Providers/EventServiceProvider.php:322/331` + `tests/Feature/Settings/SettingsUpdatedBroadcastTest.php`
   • test: `tests/Feature/Settings/SettingsUpdatedBroadcastTest.php` (émission) + décision tracée dans EXEMPTED de T-1.1.2

**Acceptance Sub 1.1** : `RefundBroadcastsPaymentStatusChangedTest` PASS + `eventContractCompletenessSentinel.spec.js` GREEN (0 event fantôme non-exempté) + `AvailabilityServiceTest` 12/12 + décision Settings/Branche tracée. E2E : refund caisse → carte suivi se met à jour SANS attendre le poll (capture Read+analysée).

---

### Sub 1.2 — Synchro DYNAMIQUE (auto-adaptative)

**Anchors** : `config/broadcasting.php` (fenêtres poll par surface documentées) · `resources/js/services/eventContract.js` (client realtime) · `app/Http/Controllers/Admin/PosSystemHealthController.php` (pastille soketi-UP/worker-DOWN, cf. [[caisse_command_center_health_2026-07-31]]) · `app/Console/Commands/MonitorOutboxStaleness.php` (crash-claimed + dead-letter dims).

**Tasks**
- **T-1.2.1** Polling ADAPTATIF côté client : quand le canal WS est confirmé vivant (heartbeat frais < TTL), détendre l'intervalle de poll (×2-×4) ; à la 1ʳᵉ coupure WS ou heartbeat périmé, resserrer au plancher. Réduit la charge en régime sain, garde la réactivité en dégradé.
   • anchor: `resources/js/services/eventContract.js` + `config/broadcasting.php` (per-surface SoT)
   • test: `tests/js/realtimeBroadcastFallback.spec.js` (existe — repli poll) + (test TO BE CREATED at `tests/js/adaptivePollingByWsHealth.spec.js`)
- **T-1.2.2** Garde SPLIT-BRAIN soketi (SO_REUSEPORT = 2 instances :6001, broadcasts perdus, verts trompeurs — cf. [[goal_s3_synchro_totale_2026-07-29]]) : sentinelle ops qui détecte >1 process soketi lié au port et l'alarme (health rouge), + doc runbook single-instance.
   • anchor: `app/Http/Controllers/HealthController.php` (checkQueueWorker pattern) + `docs/REALTIME_SETUP.md`
   • test: (test TO BE CREATED at `tests/Feature/Sync/SoketiSingleInstanceSentinelTest.php` — mock 2 process → health rouge)
- **T-1.2.3** Détection WORKER-DOWN → dégradation ANNONCÉE : `MonitorOutboxStaleness` alarme déjà (staleCount + crash-claimed + dead-letter). Câbler l'alarme à la pastille santé client (bandeau « sync dégradée, rafraîchissement manuel ») au lieu d'un vert trompeur (racine sync réelle = queue-worker DOWN masqué par soketi UP, cf. [[goal_caisse_stock_sync_audit_2026-07-22]]).
   • anchor: `app/Console/Commands/MonitorOutboxStaleness.php` + `PosSystemHealthController.php` + `PosOrdersTrackerComponent.vue` (pastille)
   • test: `tests/Feature/Sync/OutboxMonitorDeadLetterTest.php` (existe) + (test TO BE CREATED at `tests/Feature/Sync/WorkerDownSurfacesDegradedPillTest.php`)
- **T-1.2.4** Backpressure recovery : après une panne worker, `outbox:rescue` re-enfile (cap 500/tick, lissage). Vérifier que le drain d'un backlog massif ne floode pas la file `high` ni les surfaces (re-broadcast borné + priorité aux events récents).
   • anchor: `app/Console/Commands/OutboxRescueCommand.php:67` (take 500) + `OutboxRetryFailedCommand`
   • test: `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php` (existe) + `tests/Feature/Sync/OutboxRetryFailedScheduleTest.php`

**Acceptance Sub 1.2** : `realtimeBroadcastFallback.spec.js` + `adaptivePollingByWsHealth.spec.js` GREEN · `SoketiSingleInstanceSentinelTest` + `WorkerDownSurfacesDegradedPillTest` PASS · simulation panne worker : backlog draine sans flood (OutboxProductionLikeSimulation 5/5). E2E : couper le worker → bandeau dégradé apparaît < 60 s sur POS ; relancer → bandeau disparaît, board resync.

---

### Sub 1.3 — Observabilité & contrat SMART

**Anchors** : `app/Services/Observability/SyncMetricsRecorder.php` · `app/Http/Controllers/Admin/Observability/SyncOverviewController.php` (lit `ws:heartbeat`) · `app/Jobs/DispatchDomainEventsJob.php:128-132` (écrit `ws:heartbeat` au broadcast réussi) + `:153-160` (pose `broadcast_at`) · `app/Domain/Events/EventContract.php` (assertEnvelopeValid).

**Tasks**
- **T-1.3.1** Exposer `broadcast_at` dans l'observabilité : le dashboard SyncOverview montre aujourd'hui dispatch-latency ; ajouter la **latence de LIVRAISON réelle** (claim→broadcast_at) et le compteur d'orphelins (broadcast_at NULL > cutoff) — le signal que le monitor lève désormais.
   • anchor: `SyncOverviewController.php` + `MonitorOutboxStaleness.php` (crashClaimedCount)
   • test: `tests/Feature/Observability/OutboxOverviewControllerTest.php` (existe) + (test TO BE CREATED at `tests/Feature/Observability/DeliveryLatencyExposedTest.php`)
- **T-1.3.2** `ws:heartbeat` en SSOT anti-vert-menteur : le heartbeat est écrit au broadcast réussi (best-effort). Garantir que SyncOverview affiche ROUGE si heartbeat périmé (pas de broadcast récent) même si soketi répond au ping — ferme le « emerald pendant que Pusher meurt » (heal historique R1 SRE-001).
   • anchor: `DispatchDomainEventsJob.php:128-132` (écriture) ↔ `SyncOverviewController.php` (lecture)
   • test: (test TO BE CREATED at `tests/Feature/Observability/HeartbeatStaleTurnsOverviewRedTest.php`)
- **T-1.3.3** Enforcement EventContract au RUNTIME : `assertEnvelopeValid` court déjà avant broadcast (contract-violation → DLQ fail-once). Ajouter une sentinelle qui verrouille le schéma `eventContract.schema.json` ↔ `EventContract::REQUIRED_PAYLOAD_KEYS` (dérive PHP↔JS = miroir à régénérer, piège récurrent).
   • anchor: `app/Domain/Events/EventContract.php` ↔ `resources/js/services/eventContract.schema.json`
   • test: `tests/Feature/Sentinels/PayloadMismatchFailOnceSentinelTest.php` (existe) + (test TO BE CREATED at `tests/js/eventContractSchemaParityCheck.spec.js`)
- **T-1.3.4** B8 — MenuSnapshot bump au 86 EXTRA/VARIATION : `BumpMenuSnapshotOnItemAvailabilityChanged` n'est câblé QUE sur `ItemAvailabilityChanged` (item-level, EventServiceProvider:238-239), absent des listeners extra/variation → `snapshot_version` ne bouge pas au toggle 86 d'un extra. Câbler le bump sur `ItemExtra/VariationAvailabilityChanged` (dette latente [[optimisation_pos_web_logique_2026-07-31]] B8).
   • anchor: `app/Providers/EventServiceProvider.php:239/251/266` + `app/Listeners/BumpMenuSnapshotOnItemAvailabilityChanged.php`
   • test: (test TO BE CREATED at `tests/Feature/Sentinels/MenuSnapshotBumpsOnExtraVariation86SentinelTest.php`)

**Acceptance Sub 1.3** : `OutboxOverviewControllerTest` + `DeliveryLatencyExposedTest` + `HeartbeatStaleTurnsOverviewRedTest` PASS · `PayloadMismatchFailOnceSentinelTest` + `eventContractSchemaParityCheck` GREEN · `MenuSnapshotBumpsOnExtraVariation86SentinelTest` PASS (snapshot_version incrémente au 86 extra). Chaîne NF525 intacte (aucun event fiscal touché).

---

### Sub 1.4 — Robustesse & preuve cross-surface

**Anchors** : `tests/Feature/KioskRealtimeBroadcastTest.php` · `tests/Playwright/pos-receives-kiosk-realtime.spec.js` · `tests/Feature/Sync/ListenerReplayGuardTest.php` · `tests/Feature/Sync/PusherChannelAuthWildcardSentinelTest.php` · `tests/Feature/Sync/CustomerOrderChannelAuthTest.php` · `SYNC_CONTRACT.md`.

**Tasks**
- **T-1.4.1** Rafraîchir `SYNC_CONTRACT.md` : l'audit a compté 13 events réels (le contrat périmé en disait 4/certains). Régénérer la matrice émetteur→broadcast_as→canal→abonné(s)→dégradation, avec le statut « abonné réel / EXEMPTED / poll-only » par event. Le contrat devient la SSOT de complétude (référencé par T-1.1.2).
   • anchor: `SYNC_CONTRACT.md` ↔ `EventServiceProvider.php:155-340` ↔ `eventContract.js:18`
   • test: (doc-lock TO BE CREATED at `tests/js/syncContractMatrixMatchesCodeSentinel.spec.js` — le contrat cité == la réalité du code)
- **T-1.4.2** Idempotence / anti-replay des listeners : un même event re-drivé (rescue/retry) ne doit pas double-agir (double-broadcast borné = re-render inoffensif, mais un listener à effet de bord doit être idempotent). Vérifier `ListenerReplayGuard`.
   • anchor: `tests/Feature/Sync/ListenerReplayGuardTest.php` + `app/Jobs/DispatchDomainEventsJob.php:65-94` (claim lockForUpdate)
   • test: `tests/Feature/Sync/ListenerReplayGuardTest.php` (existe) + `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`
- **T-1.4.3** Sécu canal : `private-branch.{id}` + customer-channel auth ne doivent pas fuiter cross-branche ni via Sanctum wildcard. Re-valider les sentinelles.
   • anchor: `tests/Feature/Sync/PusherChannelAuthWildcardSentinelTest.php` + `tests/Feature/Sync/CustomerOrderChannelAuthTest.php`
   • test: `tests/Feature/Sync/PusherChannelAuthWildcardSentinelTest.php` (existe) + `tests/Feature/Sync/CustomerOrderChannelAuthTest.php`
- **T-1.4.4** E2E cross-surface de bout en bout (matrice C-01..C-11) : borne → commande → outbox → soketi → caisse+KDS+OSS reçoivent le PUSH (pas le poll) ; couper soketi → poll rattrape ; couper worker → bandeau dégradé. Preuve captures Read+analysées.
   • anchor: `tests/Playwright/pos-receives-kiosk-realtime.spec.js` + `tests/Feature/KioskRealtimeBroadcastTest.php`
   • test: `tests/Playwright/pos-receives-kiosk-realtime.spec.js` (existe) + (scénario dégradation TO BE CREATED at `tests/Playwright/sync-degradation-matrix.spec.js`)

**Acceptance Sub 1.4** : `KioskRealtimeBroadcastTest` + `ListenerReplayGuardTest` + `PusherChannelAuthWildcardSentinelTest` + `CustomerOrderChannelAuthTest` PASS · `SYNC_CONTRACT.md` régénéré + `syncContractMatrixMatchesCodeSentinel` GREEN · E2E push+dégradation 2 scénarios verts (captures analysées).

---

## §2 — Map separated : web standalone (lecayenne-web-deploy)

**Statut** : dépôt SÉPARÉ déployé Vercel (`lecayenne-web-deploy/Site lecayenne`), NO API wireup V1 (owner mandate). **N'intervient que dans T-1.1.3** (poll de dispo extra/variation, LECTURE seule) — jamais de nouveau couplage. Vérifier QUEL dépôt avant tout finding « web » ([[piege_miroir_web_canonique_faux]]). Anchor à confirmer au lancement (`find "<repo>" -iname "api.js"`).

---

## §A — Agent army map (fan-out)

Type de tâche dominant = **Sync cascade** (Outbox/Pusher/Echo) → matrice Axis 4 : Architect · Security · DBA · **SRE/Sync** · Implementer · RED (QA/RED Visual seulement pour les tâches à surface : pastille, bandeau dégradé, greyout web).

| Rôle | Subagent | Tools | Quand |
|---|---|---|---|
| SRE/Sync | general-purpose | Read | TOUTES (outbox/soketi/poll/queue) |
| Architect | Plan | Read | design map + complétude contrat |
| Security | general-purpose | Read | T-1.4.3 canal auth |
| DBA | general-purpose | Read | snapshot/index/idempotence |
| Implementer | general-purpose | Edit+Write+Bash | heal TDD-first, jamais 2 en //  |
| RED-team | general-purpose | Read | dispute post-commit AVANT DONE |
| QA+RED Visual | general-purpose | Read+Playwright | pastille/bandeau/greyout (T-1.2.3, T-1.1.3) |

Dispatch : 5 spécialistes read-only en 1 message (parallèle) ; Implementer jamais parallèle ; RED après chaque commit. Rapports persistés `reports/test-e2e/sync-max-2026-08-04/<round>/wave-<W>-<role>.json` (schéma Axis 4).

---

## §X — Waves de convergence

Défaut : séquentiel par wave, fan-out audit parallèle DANS la wave.

| Wave | Scope | Parallélisme | Checkpoint (6-pts Axis 3) | Interrupt-resume |
|---|---|---|---|---|
| **W0 Pré-vol** | Deploy G1 (6 commits sync + migration backfill) ; baselines phpunit/vitest + chaîne NF525 + backup branch | Séquentiel | migration OK sur VPS (backfill vérifié) · outbox 19 verts · chaîne OK ×4 | manifest `INTERRUPT_W0` |
| **W1 Complétude push (Sub 1.1)** | T-1.1.1→4 | audit //, heal séquentiel | Sentinelle complétude verte · refund push prouvé E2E · frozen 0 | commit `wip(w1)` |
| **W2 Dynamique (Sub 1.2)** | T-1.2.1→4 | audit //, heal séquentiel | poll adaptatif + split-brain + worker-down pastille · panne worker simulée OK | commit `wip(w2)` |
| **W3 Smart (Sub 1.3)** | T-1.3.1→4 | audit //, heal séquentiel | heartbeat→rouge · schema parité PHP↔JS · B8 snapshot bump · NF525 intacte | commit `wip(w3)` |
| **W4 Robustesse (Sub 1.4)** | T-1.4.1→4 | audit //, heal séquentiel | SYNC_CONTRACT régénéré + sentinelle · canal auth · E2E push+dégradation | commit `wip(w4)` |
| **W5 Convergence finale** | Full smoke (phpunit périmètre + vitest + Playwright) · E2E cross-surface complet · 2 cycles RED P0+P1=0 identiques · BRAIN §2/§3 · tag | Séquentiel | tous verts · frozen 0 · chaîne OK · 2 cycles convergés | — |

**Convergence-failure** (3 heals même cluster) : STOP, spawn `Plan` « pourquoi », écrire `STUCK_<wave>.md`, surface owner A/B/C/D (Axis 3).

---

## §G — Owner gates (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| **G1** | Déployer les 6 commits sync (dont migration `broadcast_at` + backfill) sur VPS AVANT W1 | Physical owner | log deploy `migrate` OK + backfill vérifié | `BRAIN §2` + deploy report | **PENDING (bloque W1+)** |
| **G2** | Config soketi single-instance (anti split-brain SO_REUSEPORT) sur VPS | Physical owner | `ps` 1 seul soketi:6001 + runbook signé | `docs/REALTIME_SETUP.md` §soketi | PENDING (bloque validation T-1.2.2 en prod) |
| **G3** | Push web standalone (T-1.1.3 greyout dispo) → Vercel | Physical owner | commit web + bust `?v=` servi | repo `lecayenne-web-deploy` main | PENDING (bloque validation T-1.1.3 en prod) |
| **G4** | Décision Settings/Branche : abonner OU EXEMPTED-mono-branche V1 | Physical owner (sign-off) | choix tracé dans EXEMPTED T-1.1.2 | commit message + `SYNC_CONTRACT.md` | PENDING (bloque fermeture T-1.1.4) |

**Gate-waiting** : G1 bloque W1→W5 (le socle broadcast_at doit être en prod). W0 (baselines local) + la rédaction/spec de W1-W4 en LOCAL ne sont PAS bloquées — on peut tout implémenter+tester en local, seule la VALIDATION PROD attend G1/G2/G3.

---

## §R — Références

- Audit source : `reports/goal-sync-2026-08-04/{RED-outbox-delivery,RED-cross-surface,CONVERGENCE}.md`
- `SYNC_CONTRACT.md` (à régénérer T-1.4.1) · `docs/REALTIME_SETUP.md`
- `plans/GOAL_V1_CORE_BULLETPROOF_2026-06-04.md` (matrice panne C-01..C-11)
- CLAUDE.md §7 (frozen) §8 (NF525) §10 (décision) · `memory/reference_frozen_zones.md`
- Mémoires : [[goal_sync_hardening_broadcast_at_2026-08-04]] · [[goal_s3_synchro_totale_2026-07-29]] · [[goal_caisse_stock_sync_audit_2026-07-22]] · [[caisse_command_center_health_2026-07-31]] · [[optimisation_pos_web_logique_2026-07-31]] (B8) · [[audit_adversarial_sync_gestion_2026-07-23]]
- Pipeline par tâche : `~/.claude/skills/ultra-audit-profond/` · convergence : `~/.claude/skills/test-e2e/`

---

## §F — Règle finale (DONE)

Ce GOAL est DONE quand :
1. **Complet** : 0 event outbox « fantôme » (chaque broadcast_as a un abonné réel OU un EXEMPTED tracé) — sentinelle verte.
2. **Dynamique** : poll adaptatif prouvé · split-brain + worker-down détectés et ANNONCÉS (jamais de vert menteur).
3. **Smart** : delivery-latency `broadcast_at` + heartbeat exposés · schema PHP↔JS verrouillé · B8 fermé.
4. **Robuste** : `SYNC_CONTRACT.md` == code (sentinelle) · canal auth étanche · E2E cross-surface push+dégradation verts (captures analysées).
5. **Sûr** : frozen 0 · chaîne NF525 intacte ×4 · 2 cycles adversariaux consécutifs P0+P1=0 set identique.
6. **Prod** : G1-G4 levées, tag `v1.0.X-sync-hardened`, sign-off owner (CLAUDE.md §10) avant tout push protégé.

**Production-perfect, pas « presque ».** Un broadcast qui rattrape au poll n'est pas une synchro temps-réel — c'est une synchro qui se rattrape. Ce GOAL livre le temps-réel PROUVÉ, observé, et auto-adaptatif.
