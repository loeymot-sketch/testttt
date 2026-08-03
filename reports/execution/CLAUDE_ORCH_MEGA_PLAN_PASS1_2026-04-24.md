---

## Pass 1 — Structure du méga-plan : état et découpe en phases

---

## 1. État actuel

### Livré (✅ closed)

| Vague | Lot | Finding | Description |
|-------|-----|---------|-------------|
| V1 | 1.G | F-21 (P0) | `finalizePaidKioskOrder` assertion paiement |
| V1 | 1.E | F-12 | Echo token expiration + bannière utilisateur |
| V1 | 1.A | F-04bis | `ItemAvailabilityChanged` broadcast + handlers POS/Kiosk |
| V1 | 1.B.1+2 | F-01 | Stock release on cancel/refund + wiring OrderService |
| V1 | 1.D | F-02 | `OrderTableChanged` event + flash CSS KDS |
| V2 | 1.C | F-03 | KDS adaptive polling fallback (WS-driven cadence, version-gate, backoff) |
| V3 | NEW-01 | — | Outbox dedupe consumer-side (claim-then-broadcast, LRU 2048 + sessionStorage) |
| V3 | NEW-02 | — | Reconnect storm detector + circuit breaker AWS-jitter |
| V3 | NEW-03 | — | Queue topology 3-lanes, retry SLO, $tries invariant test |
| V3 | NEW-04 | — | Observability surface : `sync_metrics`, `SyncOverviewController`, `MetricsBatcher.js` |

**Métriques** : 798/798 vitest, 6/6 invariants, tous NEW-0x PASS WITH WARNINGS résolus.

### En cours / Reste à faire

| Priorité | Lot / Item | Domaine |
|----------|-----------|---------|
| P1 **EN COURS** | 2.I | Allergens KDS : badge non-bloquant + split grouping par `allergens_hash` |
| P1 | 2.A/2.B/2.D | Payment retry idempotence, kiosk hung modal, receipt re-print 409 |
| P1 | 2.C/2.E/2.J | KDS sound throttle, QR scanner timeout UX, race `OrderCreated ⇆ Availability` |
| P1 | 2.F/2.G/2.H | KDS station filter persistence, parked orders TTL, loyalty redemption race |
| P1 | 2.G-LOCK | `DiningTableService::transfer` lockForUpdate + floorplan Echo |
| P2 (dette) | D-01..D-09 | Index migration, PHP enum `OrderStatus`, StateMachine table, E2E Playwright KDS, doc REALTIME_SYNC |
| Final | Audit clos | `AUDIT_FINAL_<date>.md` + `reports/MEGA_PLAN_v3_REPORT_<date>.md` + smoke E2E 6 scénarios |

---

## 2. Découpe en 10 phases P1..P10

### P1 — Allergens KDS (2.I, gates pré-validées)
- **Objectif** : badge `⚠️ ALLERGENS` non-bloquant + regroupement KDS par `allergens_hash`.
- **Sous-systèmes** : `KitchenDisplaySystemComponent`, `OrderItemResource`, migration `allergens_snapshot_built_at` si absente.
- **Acceptation** : `KdsAllergensBadgeRenderTest` + `KdsAllergensSplitGroupingTest` verts, `OrderItemAllergensSnapshotIntegrityTest` étendu, invariants 6/6.
- **Budget token** : 1 mission GPT = backend migration + controller uniquement ; 2e mission = composant Vue + tests Vitest. Cibler ≤ 8k tokens/sortie chacune.

### P2 — Payment recovery batch (2.A + 2.B + 2.D)
- **Objectif** : idempotence paiement POS, recovery modal kiosk bloqué, re-print reçu après 409.
- **Sous-systèmes** : `PosComponent`, `KioskPaymentFlow`, `receiptBuilder` (frozen — lire uniquement).
- **Acceptation** : `posPaymentRetryTest`, `kioskHungModalRecoveryTest`, `posReceiptReprintTest` verts.
- **Budget token** : 1 mission GPT par finding (3 missions max, chacune 1 fichier ou 1 paire fichier+test).

### P3 — KDS/POS persistence batch (2.F + 2.G + 2.H)
- **Objectif** : persistance filtre station KDS per user (localStorage), TTL parked orders POS, loyalty race guard kiosk.
- **Sous-systèmes** : `KitchenDisplaySystemComponent`, `PosParkedOrderService`, `KioskLoyaltyService`.
- **Acceptation** : suites Vitest + PHPUnit dédiées, 0 régression vitest full suite.
- **Budget token** : 1 mission GPT par lot (3 missions isolées).

### P4 — UX P1 restants (2.C + 2.E + 2.J)
- **Objectif** : throttle son KDS, UX timeout QR scanner, traitement race `OrderCreated` + items devenus indisponibles en 1 passe.
- **Sous-systèmes** : `KdsAudioService`, `KioskQrScannerComponent`, `OrderCreatedListener`.
- **Acceptation** : tests Vitest/PHPUnit par item, i18n FR/EN/AR si labels exposés.
- **Budget token** : orchestrateur peut traiter 2.C directement (UI mince) ; 2.E + 2.J = 1 mission GPT each.

### P5 — Backend concurrence critique (lockForUpdate floorplan + floorplan Echo)
- **Objectif** : `DiningTableService::transfer` lockForUpdate + event `FloorplanStateChanged` after-commit.
- **Sous-systèmes** : `DiningTableService`, `EventServiceProvider`, `eventContract.js`.
- **Acceptation** : `DiningTableTransferConcurrencyTest` + `FloorplanEchoDispatchTest` PHPUnit verts, invariant `commit_before_dispatch` respecté.
- **Budget token** : 1 mission GPT = service backend + test transactionnel (fichier sensible, cibler 1 paire uniquement).

### P6 — Audit Claude Code intermédiaire (clôture Phase 2)
- **Objectif** : audit indépendant terminal sur le batch entier P1..P5 avant de passer à la dette.
- **Sous-systèmes** : lecture filesystem complète sur les fichiers touchés P1..P5.
- **Acceptation** : `AUDIT_PHASE2_BATCH_<date>.md` produit, 0 finding P0 ouvert.
- **Budget token** : audit Claude Code CLI uniquement — pas de mission GPT.

### P7 — Dette infra légère (D-01..D-03 + D-07 + D-08)
- **Objectif** : renommage docs, purge commentaires obsolètes, migration index `orders(branch_id, status, updated_at)`, `REALTIME_SYNC.md` consolidé, cleanup `resources/js/legacy/`.
- **Sous-systèmes** : `database/migrations`, `docs/architecture`, `resources/js/legacy`.
- **Acceptation** : migration réversible, `php artisan migrate:fresh --seed` propre, aucun test cassé.
- **Budget token** : orchestrateur direct pour docs/cleanup ; 1 mission GPT pour la migration (1 fichier).

### P8 — Refacto lourd gate-protégé (D-04 + D-05)
- **Objectif** : `OrderStatus` constants → PHP enum + `OrderStateMachine::canTransition` en table de transitions.
- **Gate obligatoire** : décision humaine + entrée dans `tasks/gates/` avant lancement.
- **Sous-systèmes** : `app/Enums/OrderStatus`, `app/Services/OrderStateMachine`, tous les call sites.
- **Acceptation** : 0 appel à l'ancienne constante string, suite PHPUnit 100% verte, invariant `order_status` prouvé.
- **Budget token** : segmenter impérativement en sous-missions par domaine (Enum seul → 1 mission, StateMachine seul → 1 mission, call sites → batch by module).

### P9 — E2E Playwright KDS sync (D-06)
- **Objectif** : 6 scénarios smoke automatisés (POS→KDS, Kiosk→KDS, cancel→release, transfer table, WiFi coupé → polling fallback, 86 item → POS/Kiosk reflète).
- **Sous-systèmes** : `tests/e2e/`, fixtures Playwright, `playwright.config.js`.
- **Acceptation** : tous verts en CI, rapport archivé dans `reports/antigravity/`.
- **Budget token** : 1 mission GPT par scénario si implémentation déléguée ; préférer `foodking-complex-implementer` (Cursor) pour navigation cross-fichiers.

### P10 — Audit final + rapport MEGA_PLAN v3
- **Objectif** : `AUDIT_FINAL_<date>.md` complet produit par Claude Code terminal ; `reports/MEGA_PLAN_v3_REPORT_<date>.md` (before/after par finding, métriques, screenshots UI si KDS).
- **Acceptation** : 0 finding P0 ouvert, 6/6 invariants, `memory/episodes/12_decisions_log.jsonl` contient 1 entrée par lot livré.
- **Budget token** : Claude Code terminal uniquement — pas de proxy GPT.

---

## 3. Procédure d'audit par phase

**Ordre fixe pour chaque phase P1..P9 :**

1. `bash scripts/check-invariants.sh` → 6/6 avant toute clôture de lot.
2. `npx vitest run` + `php artisan test` → 100% vert (allowance 8 skipped MySQL).
3. **Claude Code terminal** (`foodking-claude-orchestrate.sh audit`) → génère `reports/audit/AUDIT_<LOT>_<date>.md`. C'est l'auditeur primaire indépendant.
4. Si findings résiduels P0/P1 : orchestrateur résout, relance audit.
5. **Second avis GPT** : optionnel, déclenché uniquement si le lot touche outbox / broadcast / frozen zone (patterns qui ont produit des surprises sur NEW-01..04).
6. Entrée `memory/episodes/12_decisions_log.jsonl` obligatoire avant passage au lot suivant.

**P6 et P10** = audit-only, pas d'implémentation.

---

## 4. Fallback token / troncation

Si `output_codex.json` est tronqué ou dépasse ~8k tokens sortie :

1. **Segmenter** : diviser la mission en sous-missions (1 fichier ou 1 paire backend+test par `input.json`).
2. **Trace FALLBACK** : ajouter `"fallback": true, "reason": "token_limit"` dans `12_decisions_log.jsonl` + fichier `missions/<TASK>/FALLBACK_NOTE.md`.
3. **Escalade Cursor** : basculer sur `foodking-complex-implementer` (Cursor) pour les missions cross-fichiers lourdes (navigation filesystem complète nécessaire) — documenter la raison du switch.
4. Ne jamais appliquer un `output_codex.json` partiel sans vérification manuelle orchestrateur.

---

## 5. Trois risques P0 à surveiller sur la suite

| # | Risque | Manifestation | Garde |
|---|--------|--------------|-------|
| R1 | **`commit_before_dispatch` violé** | Un nouveau lot ajoute un `Broadcast::on(...)` **à l'intérieur** d'une transaction DB — résulte en broadcast fantôme ou rollback invisible. | Invariant 4/6 + revue orchestrateur systématique sur toute modification de `DispatchDomainEventsJob` ou listener `Persist*ToOutbox`. |
| R2 | **`branch_id` fuite cross-tenant** | Un endpoint observability/reporting oublie `resolveBranchScope()` → opérateur branch lit données d'une autre branche (déjà vu sur NEW-04 G2). | Test sentinel cross-branch 403 obligatoire sur tout nouveau endpoint admin ; reviewer checklist dans `tasks/gates/`. |
| R3 | **Sync drift silencieux après refacto D-04/D-05** | La conversion `OrderStatus` string → PHP enum casse des comparaisons string implicites dans les listeners ou le KDS frontend, sans lever d'exception (PHP cast silencieux ou JS `===` raté). | Gate humaine P8 obligatoire, + test `OrderStateMachineInvariantTest` sur toutes les transitions avant/après refacto, + recherche exhaustive call sites avant lancement. |
