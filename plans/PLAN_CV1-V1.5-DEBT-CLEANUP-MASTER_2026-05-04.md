# MASTER PLAN — V1.5 Debt Cleanup — CV1-V1.5-DEBT-CLEANUP-*

| Champ | Valeur |
|---|---|
| MASTER_TASK_ID | `CV1-V1.5-DEBT-CLEANUP-MASTER` |
| Date | 2026-05-04 |
| Source | `docs/orchestration/cycles/CYCLE_CV1-V1-FINISH-MASTER_2026-05-04.md` § Risques résiduels + dette V1.5 listée. |
| PARENT_CYCLE | `CV1-V1-FINISH-MASTER` CLOSED PASS (2026-05-04 ~15:35) |
| Owner | Cursor Claude (orchestrator + sub-agents) |
| Approbation humaine | User message 2026-05-04 15:45 UTC+2 : « continue avec le plan » → autorisation de continuer sur le backlog dettes |

---

## 1. Contexte

V1-FINISH a délivré 5 healings post ultra-review et fermé toutes les actions BLOCKING + a11y recommandée. Reste le **backlog V1.5** — dettes techniques héritées documentées en notes de clôture. Ce master traite les 3 dettes les plus prioritaires en risque produit :

| Priorité | Dette | Risque V1 |
|---|---|---|
| 🔴 CRITIQUE | `ComposerProfileProjection` runtime ne lit pas `ItemAttribute::is_available` | Toggle viande/sauce en rupture (admin UI Ingrédients) **NE SE PROPAGE PAS** au POS/kiosk wizard si step `stockable_choices=false`. UX cassée silencieusement. |
| 🟠 MOYEN | `FiscalArchiveTest` flaky | Bruit CI, masque vraies régressions fiscal NF525. |
| 🟢 OPS | Monitoring SQL XOR `item_wizard_profiles` | Détection violation contrainte XOR (item_id ⊕ item_category_id) post-deploy prod si MySQL < 8.0. |

Le reste du backlog (drill-down ingrédients UX, lock optimiste toggle, cache invalidation branch-scoped, spec Playwright cross-surface live) reste différé V1.5b ou V2.

---

## 2. 3 cycles + audit

### Cycle D1 — ComposerProfileProjection runtime is_available propagation 🔴 CRITIQUE

- **TASK_ID** : `CV1-V1.5-DEBT-COMPOSER-RUNTIME-AVAILABILITY-001`
- **EXECUTION_TIER** : `complex` (touche service runtime + projection wizard + chemin pricing-adjacent)
- **EXECUTE_DELEGATION** : `foodking-complex-implementer`
- **SUBSYSTEMS_TOUCHED** :
  - `app/Services/Stock/ChoiceAvailabilityResolver.php` (write — nouvelle méthode `availabilityForVariation` lisant `ItemAttribute::is_available`)
  - `app/Services/Composer/ComposerProfileProjection.php` (write — appliquer `is_available` même si `stockable_choices=false` pour `item_attribute` source_type)
  - `tests/Feature/Stock/` (write — nouveaux tests propagation rupture variation)
  - `tests/Feature/Composer/` (write — nouveaux tests projection avec ingrédient en rupture)
- **SUBSYSTEMS_OFF_LIMITS** : tout frontend (la projection JSON est consommée tel quel par PosMenuProjection, KioskMenuService — aucune modif Vue requise), pricing logic (I1 read-only), `OrderService` (I5 N/A)
- **GATE_CONDITIONS** : None anticipated
- **INVARIANTS_AT_RISK** :
  - I1 pricing SSOT : read-only (on lit `is_available` qui est business data, pas pricing)
  - I3 branch_id : préservé (logique runtime non touchée)
  - I4 dispatch : non touché

### Cycle D2 — FiscalArchiveTest flaky stabilization

- **TASK_ID** : `CV1-V1.5-DEBT-FISCAL-ARCHIVE-FLAKY-001`
- **EXECUTION_TIER** : `routine` (test isolation/timing fix, pas de logique métier)
- **EXECUTE_DELEGATION** : `foodking-routine-implementer`
- **SUBSYSTEMS_TOUCHED** : `tests/Feature/Fiscal/` (write — fix flakiness via mocks/freeze time/refresh DB)
- **SUBSYSTEMS_OFF_LIMITS** : code production fiscal (`app/Services/Fiscal/*`, `app/Models/ZReport*`) — frozen zone fiscal NF525 ; halt + escalade si test fixé requiert modif code prod
- **GATE_CONDITIONS** : None anticipated (si modif code prod requise → escalade vers complex avec gate fiscal)
- **INVARIANTS_AT_RISK** : aucun (fix tests uniquement)

### Cycle D3 — Monitoring SQL XOR + cron template

- **TASK_ID** : `CV1-V1.5-DEBT-XOR-MONITORING-001`
- **EXECUTION_TIER** : `routine` (script bash + SQL + doc)
- **EXECUTE_DELEGATION** : `foodking-routine-implementer`
- **SUBSYSTEMS_TOUCHED** : `scripts/` (write — `xor-violation-check.sh`), `docs/orchestration/` (write — procédure cron)
- **GATE_CONDITIONS** : None
- **INVARIANTS_AT_RISK** : None

### Cycle D4 — Audit consolidé + CLOSE

- **TASK_ID** : `CV1-V1.5-DEBT-AUDIT-CLOSE-001`
- **AUDIT_CHANNEL** : tentative `terminal-claude` (reset 18h10) — sinon `cursor-session` fallback `foodking-planner-orchestrator`
- **GATE_CONDITIONS** : sur REWORK → boucle healing max 3 rounds, sinon HUMAN_GATE
- **INVARIANTS_AT_RISK** : audit sur tous les changements

---

## 3. Stratégie d'exécution

- **Parallélisme** : D1 (complex backend services) + D2 (tests fiscal) + D3 (script ops) lancés en // — fichiers disjoints (D1 = `app/Services/Stock` + `app/Services/Composer` + tests Stock/Composer ; D2 = `tests/Feature/Fiscal` ; D3 = `scripts/` + `docs/orchestration/`).
- **Audit final** : D4 = audit consolidé (PRIMARY terminal Claude après reset 18h10, FALLBACK planner-orchestrator si quota encore down).
- **Boucle healing** : sur REWORK, healing automatique max 3 rounds avant HUMAN_GATE.

---

## 4. Definition of Done globale

- ✅ D1-D3 livrés avec RUN reports.
- ✅ Vitest baseline ≥ 1157 (préservée — D1 backend pur, D2 tests PHP, D3 ops).
- ✅ PHPUnit baseline ≥ 1413 + nouveaux tests D1 (probable 1417+).
- ✅ Build `npm run dev` PASS.
- ✅ Toggle ingrédient (viande/sauce) en rupture → propagé < 5s vers POS/kiosk wizard (vérifié par tests Composer + Stock D1).
- ✅ FiscalArchiveTest stable (10 runs successifs PASS sans flaky) — vérifié par D2.
- ✅ Script `scripts/xor-violation-check.sh` testable localement et exit code propre.
- ✅ Audit D4 `AUDIT_VERDICT: PASS`.
- ✅ Episode mémoire `12_decisions_log.jsonl` append.
- ✅ Archive cycle `docs/orchestration/cycles/CYCLE_CV1-V1.5-DEBT-CLEANUP-MASTER_2026-05-04.md`.

---

## 5. Statut

| Cycle | Statut | RUN |
|---|---|---|
| D1 ComposerProfile runtime | EXECUTE in progress | (à venir) |
| D2 FiscalArchive flaky | EXECUTE in progress | (à venir) |
| D3 XOR monitoring | EXECUTE in progress | (à venir) |
| D4 Audit close | PENDING | (à venir) |
