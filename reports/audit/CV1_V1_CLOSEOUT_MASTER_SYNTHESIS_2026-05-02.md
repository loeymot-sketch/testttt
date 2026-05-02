# Master Synthesis V1 Close-out — CV1-V1-CLOSEOUT-001 — 2026-05-02

**État :** REMPLI — 5 audits Phase 1 reçus, Lot C dashboard livré, 2 sentinels P0 livrés, audits in-session uniquement (Claude terminal optionnel pour user).

---

## 1. Verdict V1-ready

**🟡 GO_WITH_CONSTRAINT** — V1 fonctionnelle livrable, **mais** avec dette majeure documentée. Décisions humaines requises pour fermer 5 gates avant clôture totale.

**Score consolidé sur 100 par axe :**

| Axe | Score | Justification |
|---|---|---|
| 1 — Centralisation catalogue | **62/100** | Backend prix SSOT OK ; mais `PosMenuProjection` non câblé en runtime (flag mort) ; `KioskMenuService` duplique `MenuProjectionService` ; `AvailabilityService::dispatchEvent` viole dispatch-after-commit |
| 2 — Synchronisation données | **70/100** | Serveur ROBUSTE (outbox + atomic CAS M2 1.9 + Echo) ; Client LOSSY_PROBABLE si flag POS polling OFF + pas de fallback KDS/OSS catalogue |
| 3 — Gérance admin actions | **65/100** | 3/9 COUVERT, 5/9 PARTIEL, 1/9 NON COUVERT (`max_daily_qty` sans endpoint HTTP) ; +T-DEEP-PROD-01 et T-DEEP-CAT-01 fermés cette session |
| 4 — Wizard POS vs Kiosk | **40/100** | DETTE MAJEURE : POS `public/js/pos-wizard.js` vanilla = 0 ref `composer` ; profil composer admin **non consommé** par caisse ; refactor XL nécessaire post-V1 |
| 5 — Cleanup dashboard | **80/100** | Inventaire précis fourni : 8 modules G1 retirer / 8 G2 cacher / 6 G3 gates DROP TABLE + DB partagé `orders/users` à analyser |

**Verdict global :** V1 livrable opérationnellement. Risques résiduels documentés. Clôture totale dépend de signature des 5 gates + décision wizard runtime refactor (Famille D plan ultra).

---

## 2. Top 5 blockers V1 (P0)

| # | Blocker | Source | Impact | Action |
|---|---|---|---|---|
| 1 | `setMaxDailyQty` (M2 2.5) sans endpoint admin HTTP | Axe 2+3 | Admin ne peut PAS utiliser la fonctionnalité réellement | T-DEEP-AVAIL-API-01 (routine M, lancé) |
| 2 | `AvailabilityService::dispatchEvent` viole dispatch-after-commit | Axe 1 | Risque event perdu si rollback transaction | T-CENT-AVAIL-DISPATCH-01 (routine S) |
| 3 | `PosMenuProjection` non câblé en runtime | Axe 1 | Flag `unified_projection.enabled` mort ⇒ pas de SSOT projection POS effective | T-CENT-POS-PROJ-01 (complex L) — Codex Pro 22:21 |
| 4 | POS pas de fallback polling activé par défaut prod | Axe 2 | Echo down 5 min = catalogue stale | T-OPS-POS-POLLING-01 (config flag + runbook) |
| 5 | POS wizard ne lit pas composer profile | Axe 4 | Profil admin publié = invisible caisse | RT-02 (complex M) — partie Famille D ULTRA PLAN |

---

## 3. Plan d'attaque ordonné (priorité décroissante)

### Lots déjà livrés cette session

| Lot | Statut | Commit |
|---|---|---|
| Master plan + ACTIVE_CYCLE pivot | ✅ | `7b0236b84` |
| ULTRA PLAN catalog/wizard/stock/sync | ✅ | `0e8cd7121` |
| 5 gate briefs (2 LIFECYCLE V2 + 3 Lot B) | ✅ PENDING_HUMAN | `baba40c1c` + `d0c25cef9` (à confirmer) |
| Lot C — Refonte dashboard 4 widgets V1 | ✅ | `edd2c4b0d` |
| T-DEEP-PROD-01 — Sentinel pricing snapshot 3/3 | ✅ | `247a7d923` |
| T-DEEP-CAT-01 — Sentinel category rename sync 3/3 | ✅ | `924eeedfe` |

### Lot D — Tâches issues des 5 audits (priorisées)

| ID | Titre | Tier | Statut | Source |
|---|---|---|---|---|
| `T-DEEP-AVAIL-API-01` | Endpoint admin POST `/api/admin/menu/availability/max-daily-qty` + Feature test | routine M | À LANCER | Axe 2 P0 |
| `T-CENT-AVAIL-DISPATCH-01` | Fix `AvailabilityService::dispatchEvent` → `DB::afterCommit` | routine S | À LANCER | Axe 1 P0 |
| `T-CENT-DEDUP-AVAIL-01` | Délégation `AvailabilityController::toggle` → `AvailabilityService::toggle` | routine M | À LANCER | Axe 3 P1 |
| `T-CENT-POS-PROJ-01` | Brancher `PosCategoryController::index` sur `PosMenuProjection::forBranch` + adapter legacy | complex L | Codex Pro 22:21 | Axe 1 P0 |
| `T-CENT-CONVERGE-KIOSK-01` | Faire converger `KioskMenuService` ↔ `MenuProjectionService` (un seul builder) | complex XL | Codex Pro 22:21 | Axe 1 P1 |
| `T-LOT-A-CLEANUP-01` | Retirer 8 modules G1 frontend + cacher 8 G2 menu admin | routine L | À LANCER (inventaire Axe 5 prêt) | Axe 5 |
| `T-OPS-POS-POLLING-01` | Activer flag `pos_fallback_polling.enabled` + runbook prod | config | À LANCER | Axe 2 P0 |
| `T-DEEP-CAT-02` | Sentinel `CategoryDeletionWithItemsTest` (refus cascade) | routine M | DEFERRED post-Lot A | ULTRA PLAN Famille A |
| `T-DEEP-STK-01` | Sentinel `StockMovementsLedgerCompletenessTest` | routine M | DEFERRED post-Lot A | ULTRA PLAN Famille C |
| `T-DEEP-PROD-02..08` | Suite famille B produit | routine S/M | DEFERRED | ULTRA PLAN |
| `RT-01..07` (wizard refactor) | Famille D wizard runtime | complex L+XL | Codex Pro post-22:21 | Axe 4 + ULTRA PLAN |

---

## 4. Risques résiduels V1

| Risque | Source | Mitigation |
|---|---|---|
| Catalogue stale client si Echo down + flag POS polling OFF | Axe 2 P0 | Activer flag prod + runbook |
| POS wizard ne lit pas composer profile publié | Axe 4 P0 | Documenter limitation V1 + RT-02 post-V1 |
| `ComposerProfilePublished` event sans listener | Axe 3 P2 | Câbler ou supprimer |
| KDS/OSS sans fallback catalogue Echo | Axe 2 P2 | Polling KDS/OSS catalogue (V2) |
| Imports Excel `ItemImport`/`ItemCategoryImport` contournent services + events | Axe 1 | Documenter ; bouton "rebuild caches" admin |
| `MenuSeeder` mute directement DB sans events | Axe 1 | Acceptable (init data) ; documenter |

---

## 5. Décisions humaines en attente (résumé)

| # | Décision | Document | Recommandation Claude |
|---|---|---|---|
| 1 | `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-05-02` | `docs/gates/...` | Option B server-only |
| 2 | `GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE_2026-05-02` | `docs/gates/...` | Option A 4-phase rollout |
| 3 | `GATE_DROP_TABLE_DELIVERY_BOYS_V1_2026-05-02` | `docs/gates/...` | Option B RENAME archive |
| 4 | `GATE_DROP_TABLE_TABLE_SERVICE_V1_2026-05-02` | `docs/gates/...` | Option B RENAME 4 tables + retire POS floorplan |
| 5 | `GATE_DROP_TABLE_ONLINE_ORDERS_V1_2026-05-02` | `docs/gates/...` | Option B post-vérif Kiosk-FrontendOrderService |
| 6 | M2 2.9 Wizard admin guidé XL | plan §2.9 | DIFFÉRER post-V1 (effort XL non bloquant) |
| 7 | Audit Claude terminal indépendant | `reports/audit/CLAUDE_TERMINAL_*` | Lancer quand quota dispo (validation croisée recommandée mais facultative) |

---

## 6. Roadmap calendrier

| Étape | Quoi | Quand | Délégation |
|---|---|---|---|
| Phase 3.A | Lot A cleanup + 3 tâches P0 routine (dispatch fix + API setMaxDailyQty + dedup AvailController) | EN COURS / IMMÉDIAT | Composer routine ×3 |
| Phase 3.B | T-CENT-POS-PROJ-01 + T-CENT-CONVERGE-KIOSK-01 + RT-01..03 | Codex Pro 22:21 → après | Codex / fallback Cursor |
| Phase 4 | Audit Claude terminal cross-vérification (optionnel) | Quand user dispose | Anthropic Pro CLI |
| Phase 5 | Gates signature + DROP TABLE Lot B | Quand user prêt | user + Composer post-sig |
| Close V1 | Tests verts + verdict GO | Après tous Lots A/B/D minimum routine | Claude orchestrator |

---

**Statut :** SYNTHÈSE COMPLÈTE. Phase 3.A lancée immédiatement après ce document.
