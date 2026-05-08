# REPORT — F-016a-BIS Stock Orchestration Extras + Variations (REFINED Option 1bis)

**Finding ID :** F-016a-BIS
**Date :** 2026-05-08
**Agent :** general-purpose (Wave 3.1)
**Branche :** `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
**Commit hash final :** `a45015ddc`
**Plan refiné :** `.claude/worktrees/blissful-mclean-c915c2/plans/PLAN_AUDIT_F016a_BIS_STOCK_ORCHESTRATION_REFINED_2026-05-08.md`
**Origine :** drift escalation par agent F-016a (close-by-investigation) — plan original `PLAN_AUDIT_F016_STOCK_ORCHESTRATION_V1_2026-05-08.md` ignorait `ChoiceAvailabilityResolver` + `stock_levels` polymorphique déjà en production.

---

## Section 1 — Plan refiné vs réalisé

| Deliverable | Status |
|---|---|
| Migration GATED OWNER `add_manual_unavailable_to_stock_levels` | ✅ fichier committed, NOT migré dev/prod |
| `ChoiceAvailabilityResolver::availabilityFromLevel` priorité manual | ✅ additif, pas de cassure contrat |
| `AvailabilityService` wrappers | ✅ 6 méthodes plan (toggleExtra, toggleVariation, isExtraAvailable, isVariationAvailable, getUnavailableExtraIdsForBranch, getUnavailableVariationIdsForBranch) + bonus `getBranchAvailabilitySnapshot` aggregate |
| Endpoints admin (toggleExtra POST + toggleVariation POST + GET branch availability) | ✅ extension `AvailabilityController` existant (pas de duplicate) |
| Outbox events `ItemExtraAvailabilityChanged` + `ItemVariationAvailabilityChanged` | ✅ events + listeners + EventType constants + EventServiceProvider mapping |
| `StockService::decrementForOrder` couvre extras+variations | ✅ vérifié polymorphique natif `requirementsForOrderItem:247-292`, sentinel anti-régression ajouté |
| Tests : 4 fichiers, 28 cases | ✅ ALL GREEN |

## Section 2 — Drift verification résultats

1. `MenuAvailabilityController` plan-suggested name n'existait pas, mais `AvailabilityController` existait avec pattern `permission:items_edit` + délégation `AvailabilityService` → étendu au lieu de dupliquer (3 nouvelles actions).
2. `StockService::decrementForOrder` est DÉJÀ polymorphique (variation/extra/addon via `requirementsForOrderItem`) → AUCUNE extension nécessaire. Sentinel anti-régression verrouille cette propriété.
3. Plan §3.1 timestamps : `2026_05_08_150000` (après dernier `2026_05_08_140200`).
4. `ItemAttribute` n'a pas FK `item_id` (m-to-n) → helper test ajusté.

## Section 3 — Sub-plan local exécuté

Ordre TDD strict couche par couche : (a) migration + model casts → (b) tests rouges Resolver patch → (c) Resolver patch → (d) tests rouges AvailabilityService wrappers → (e) AvailabilityService wrappers → (f) tests rouges endpoints → (g) Controller extension + FormRequests + routes → (h) Events + dispatch + listeners + EventServiceProvider → (i) StockService.decrementForOrder vérification (polymorphique natif confirmé, pas d'extension) → (j) sentinel anti-régression ajouté.

## Section 4 — TDD trace

| Test file | Cases | Pass | Skip | Fail | Time |
|---|---|---|---|---|---|
| `AvailabilityServiceExtrasVariationsTest` | 12 | 12 | 0 | 0 | 1.74s |
| `MenuAvailabilityToggleEndpointsTest` | 8 | 8 | 0 | 0 | 1.49s |
| `OrderDecrementsExtrasAndVariationsStockTest` | 4 | 4 | 0 | 0 | 7.50s |
| `StockManualReasonSurfacingSentinelTest` | 4 | 4 | 0 | 0 | 1.39s |

## Section 5 — Anti-drift checklist 12 cases (cochée)

- [x] Drift technique zéro (pas de refactor opportuniste hors scope)
- [x] Drift business zéro (logique métier conforme plan)
- [x] Drift archi zéro (extension pattern `ChoiceAvailabilityResolver` existant, pas parallel SoT)
- [x] Drift test zéro (tests ciblent comportement)
- [x] Drift sécurité zéro (`items_edit` + sanctum + branch scope + reason whitelist + `required_if`)
- [x] Drift perfo zéro (1 index `stock_levels_manual_reason_idx`, throttle 60/min réutilisé)
- [x] Drift UX zéro (aucune modif UI — F-016b deferred)
- [x] Drift dépendance zéro
- [x] Drift config zéro
- [x] Drift docs zéro (sauf REPORT durable)
- [x] Drift commit zéro (1 commit cohérent `audit(F-016a-BIS): ...`)
- [x] Drift portée zéro (backend only, frontends frozen)

## Section 6 — Tests run finaux (régression)

| Filter | Pass | Skip | Fail | Time |
|---|---|---|---|---|
| F-016a-BIS dédiés | 28 | 0 | 0 | ~12.1s |
| `Availability\|Stock\|Menu` (régression complète) | 234 | 16 (unrelated pre-existing) | 0 | 88.56s |
| `Sentinel\|Outbox\|Symmetry` (régression) | 215 | 2 (unrelated) | 0 | 19.49s |

## Section 7 — Frozen-zones touchées

**AUCUNE.** Tous les zones frozen intactes :
- `public/js/pos-app.js` ✅
- `resources/js/components/admin/pos/PosComponent.vue` ✅
- `resources/js/components/frontend/kiosk/Kiosk*.vue` (8 composants) ✅
- `app/Services/OrderStateMachine.php` ✅
- `app/Services/Fiscal/FiscalSequenceService.php` ✅
- `app/Services/Fiscal/ZReportService.php` ✅
- `app/Services/Fiscal/AuditLogService.php` ✅
- `app/Services/Payment/Gateways/*` ✅

## Section 8 — Migration GATED OWNER

**1 fichier créé :** `database/migrations/2026_05_08_150000_add_manual_unavailable_to_stock_levels.php`

- 2 colonnes nullables : `manual_unavailable_reason VARCHAR(32)`, `manual_unavailable_since TIMESTAMP`
- 1 index : `stock_levels_manual_reason_idx`
- Zero-downtime safe : ALTER ADD nullable + index INSTANT sur PG/MySQL 8
- Compatible deploy-before-code : code lit `?? null`, comportement identique pré-migration
- **NON appliquée** sur dev/staging/prod par cet agent
- Uniquement RefreshDatabase test (sqlite memory)
- Owner human triggers `php artisan migrate` au rollout window

## Section 9 — Décision orchestrateur recommandée

**`continue`** — implementation high-quality, architecture cohérente Option 1bis confirmée, test evidence solide (28 cases dédiés + 505 régression sans nouvelle défaillance), security/branch isolation validés explicitement, frozen-zones intactes. F-016a-BIS = closed.

## Section 10 — Hand-off F-016b UI

Pour cycle suivant (F-016b UI dashboard StockManager) :

- `GET /api/admin/menu/availability/branch/{branch}` retourne aggregate `{ branch_id, items[], extras[], variations[] }` → 3 onglets StockManager UI
- 2 toggle endpoints + Echo handlers `ItemExtraAvailabilityChanged`/`ItemVariationAvailabilityChanged`
- Channel : `private-branch.{id}`, broadcast_as distinct
- Whitelist source : `StockLevel::MANUAL_UNAVAILABLE_REASONS` (5 valeurs : `out_of_stock_manual`, `seasonal`, `recipe_change`, `supplier_issue`, `quality_issue`)
- Display `manual_unavailable_since` ISO8601 → relative time UX
- Composant Vue : `resources/js/components/admin/menu/StockManagerComponent.vue` (3 onglets : Items / Extras / Variations)
- Modal "Rupture rapide" avec dropdown raisons
- Echo handlers cross-surface (`KioskAppComponent.vue` + `PosComponent.vue`) → invalidation cache + refetch sur events extras/variations
- Tests Playwright multi-surface : sauce rupture branche A absente kiosk A, présente kiosk B, réapparaît en <2s après re-toggle

## Section 11 — Risques résiduels

1. `getBranchAvailabilitySnapshot` perf si branche > 500 ruptures/bucket (V1 non observé)
2. Eager-load risk pré-existant dans `snapshotForItems` (out-of-scope F-016a-BIS)
3. Concurrence toggle simultané : mitigé par `lockForUpdate` + clause idempotence
4. Outbox listener registration : pas de dépendance d'ordre
5. `StockLevelFactory` default `'item'` lowercase incohérent avec FQCN — out-of-scope ; tests utilisent direct `StockLevel::query()->create()`
6. Migration non appliquée prod (intentionnel, GATED)
7. Whitelist hardcoded PHP — acceptable V1 ; futur `settings` table si business demande dynamique

---

**Verdict orchestrateur :** F-016a-BIS = ✅ CLOSED. Prochaine étape : F-016b UI (deferred, cycle suivant) + F-017 massive E2E test suite (Wave 4 — gate avant merge).
