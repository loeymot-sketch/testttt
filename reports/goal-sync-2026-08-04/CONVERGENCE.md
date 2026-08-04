# GOAL Synchronisation — Convergence 2026-08-04

**Mandat owner** : « finis ce qui reste et ultra plan deeply raisonning max syncrnisation et dynamic et smart ».

**Branche** : `pos/category-first-caisse-2026-06-23` — 4 commits `06e5f1c03`→`470c7a425`, **4 d'avance sur VPS `827afae93`. NON DÉPLOYÉ = gate owner.**

**Méthode** : 2 auditeurs adversariaux read-only (outbox delivery + cross-surface cohérence), cartographie émetteurs↔listeners outbox↔abonnés client, verify-before-report (chaque finding cité file:line + repro). Heals TDD (red→green). Isolation git des rouges pré-existants.

## Verdict d'audit
**Cœur statut/board/stock/prix : SAIN, 0 P0.** Le défaut de fond = la dimension **push temps-réel** (events sans abonné client), rattrapée partout par le polling SAUF **une seule divergence PERMANENTE** (P1 web-vend-86-extras).

## Heals livrés (4 commits)

| # | Sévérité | Commit | Résumé |
|---|----------|--------|--------|
| SYNC-P2-1 | P2→durci | `06e5f1c03` | `broadcast_at` = marqueur de LIVRAISON réelle (Phase 3a), séparé de `dispatched_at` (CLAIM Phase 1). Rescue/monitor/prune rekeyés. Orphelin claim-sans-broadcast désormais récupéré. **Backfill migration** (anti alarme-masse + re-broadcast historique au deploy). |
| Cross-surface P1 | **P1** | `077883237` | La commande WEB rejette les extras/variations 86 (garde `assertExtrasAndVariationsOrderableForBranch`, même SSOT StockLevel que la borne). Ferme la seule divergence permanente. |
| Cross-surface P2 | P2 | `80ea49dee` | Auto-prepare online (4e site) diffuse `OrderStatusChanged(ACCEPT→PREPARING)` → push KDS/OSS temps-réel (avant : seul `OrderPaymentStatusChanged` sans abonné → carte cuisine visible au poll 5-60 s). |
| Straggler P1-6 | test | `470c7a425` | Test contract self-cancel-paid aligné sur le garde owner P1-6 (refus 422). |

**Bonus (finis ce qui reste)** : 2 tests rouges PRÉ-EXISTANTS (isolés via `git checkout 1bf7aad5e`, PAS des régressions de cette session) remis en phase avec les gardes owner SÉCU de TODAY :
- `WebNonCodOrderNotBoardReleasedTest` test B → **R1 SÉCU** (accept carte web UNPAID = 422 anti-zombie 3DS).
- `OrderServicesContractTest::self_cancel` → **P1-6 SÉCU** (self-cancel payé = 422, remboursement = geste comptoir).

## Gates
- Outbox : 19 fichiers verts (+lock tests broadcast_at, prune-preserve-orphan, monitor-first-attempt-orphan).
- AvailabilityService 12 · ChangePaymentStatusOutbox 3 · AutoPrepareOnPaid 13 · WebOrderInlineAccept 5 · WebNonCod 3 · OrderServicesContract 6.
- Frozen 0. Migration backfill validée sur DB réelle (542 livraisons marquées, 1 orphelin genuine restant).

## Reste — backlog documenté (NON healé, justifié)
| Finding | Sévérité | Pourquoi différé |
|---------|----------|------------------|
| `OrderPaymentStatusChanged` diffusé sans abonné client | P2 | Latence (polling POS 30 s nettoie). Câbler = BROADCAST_MAP multi-surfaces + repo web séparé. |
| `SettingsUpdated` / `BranchStatusChanged` sans abonné | P2/P3 | Fail-safe via 422 / mono-branche V1. |
| MenuSnapshot ne bump pas au 86 extra/variation (B8) | P3 | Aucun endpoint ne lit `snapshot_version` aujourd'hui = dette ~nulle. |
| `OrderTableChanged` → KDS seul | P3 | POS Floorplan poll 15 s, latence bénigne. |
| `ChangeStatusReturnedSelfAuditR2Test` ×3 | owner-gate | Cluster M8 `hasRecordedCashIn` PRÉ-EXISTANT, décision owner (variance fantôme). |

## Gate owner — deploy
```
ssh lecayenne "cd /var/www/lecayenne && git pull --ff-only && \
  php artisan migrate && \  # backfill broadcast_at s'exécute ici
  php artisan config:clear && php artisan cache:clear && php artisan queue:restart"
```
La migration `2026_08_04_120000_add_broadcast_at_to_domain_events` ajoute la colonne + index + **backfille les livraisons historiques** (sinon le monitor alarmerait en masse et rescue re-diffuserait l'historique). Lancer `migrate` AVANT de ré-armer le scheduler (fenêtre DDL sous-seconde, cf. docblock migration).

## Cycle 2 — revue adversariale du diff (`1bf7aad5e..HEAD`)
**Verdict : CONTINUE. Aucune régression P0/P1 substantiable.** Chaque risque tracé à la source :
- `dispatched_at` INCHANGÉ (toujours posé au claim Phase 1) ; `broadcast_at` purement additif.
- Rescue lane B (`broadcast_at IS NULL`) et RetryFailed (`whereNull(dispatched_at)`) **disjointes sur `dispatched_at`** → jamais la même ligne. Pas de re-broadcast d'une livraison (Phase 3a pose `broadcast_at`).
- Garde extra/variation : 0 faux rejet (même SSOT que la borne), POS non impacté (autre chemin), non contournable (mêmes arrays que le pricing/persistence).
- Auto-prepare `OrderStatusChanged` : émission unique, gate `status===ACCEPT`, FCM cuisine seul (pas client), `DispatchableAfterCommit` dans la transaction.
- Consommateurs de `dispatched_at` (HealthController, SyncOverview, PosSystemHealth) NON régressés (sens « claim » inchangé, fichiers non touchés).

**Findings résiduels (traités, commit `ebb66f9ce`)** : [P2] fenêtre de déploiement DDL MySQL (documentée, auto-guérie, négligeable mono-poste) ; [P3] orphelin crash-1er-essai backfillé « livré » (accepté, pré-existant-équivalent) ; [P3] commentaire prune lane A précisé.

**Convergence : cycle 1 (audit cross-surface) → 1 P1 healé ; cycle 2 (RED sur le diff healé) → 0 P0/P1.** Sync hardening CONVERGÉ (résiduels = P2/P3 documentés).
