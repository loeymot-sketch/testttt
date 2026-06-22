# Méga-audit v2 — système opérationnel + agentique + mémoire Graphiti

**Date** : 2026-04-23  
**Version** : 2 (preuves chiffrées, chemins absolus relatifs dépôt)  
**Périmètre** : cycles bornés Cursor, rapports `RUN_*`, plans `plans/*.md`, tâches `tasks/**/*.md`, règles `.cursor/rules/*.mdc`, skills, hooks, workflows GitHub Actions, scripts `scripts/*.sh`, mémoire `memory/**`, préflight `app:preflight-production`, sync/outbox, CI. **Aucun code applicatif modifié** dans cet audit (lecture seule).  
**Compagnon actionnable** : [`docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md`](../../docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md) (**180** tâches, sections **A–M**).

---

## 1. Synthèse exécutive

Le dépôt possède une **gouvernance documentaire forte** (cycles, gates, primer, JSONL, scripts de vérification) mais une **instrumentation machine faible** : la plupart des règles ne sont pas **bloquantes** en CI, les hooks locaux sont **cosmétiques ou warn-only**, et la **conformité des rapports d’exécution** (`EXECUTE_DELEGATION`) est minoritaire (**26,5 %**). La mémoire Graphiti repose sur des **fichiers JSONL** de qualité, mais l’**ingest** n’est **pas idempotent** sans `clear_graph`, **sans automation** post-merge, et `memory/verify.py` utilise une **heuristique** (sous-chaîne `"uuid"`) plutôt qu’une preuve graphe ↔ fichiers. Les workflows CI couvrent **PHPUnit**, **Vitest**, **Playwright** (opt-in), mais **Vitest** ne se déclenche pas sur **`develop`** dans la configuration observée, et **`scripts/check-invariants.sh`** n’apparaît **dans aucun** workflow — les **6/6** invariants ne **bloquent** donc **pas** le merge. Le fichier **`foodking-invariants.mdc` est référencé mais absent** ; le vrai invariant est **`project-invariants.mdc`** → **liens morts** dans `global.mdc`, `project-continuity.mdc`, `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`, `.cursor/agents/app-planner-orchestrator.md`, `.cursor/agents/app-routine-implementer.md`. **Verdict discipline machine** : **3,5/10** (preuve par scripts/CI). **Verdict richesse documentaire** : **8/10**. **Verdict global opérationnel** : **6/10** — praticable sur **lots fermés** avec humain qui force le cycle ; **fragile** si sessions « chat libre » ou merges sans garde-fous.

---

## 2. Méthodologie

Quatre sous-agents **`explore`** ont été lancés **en parallèle** (lecture seule), périmètres disjoints puis consolidation :

| # | Focus | Artefacts typiques lus |
|---|--------|-------------------------|
| 1 | Discipline cycle / `RUN_*` / `ACTIVE_CYCLE` / plans / tasks | `reports/**/RUN_*.md`, `.cursor/ACTIVE_CYCLE.md`, `plans/*.md`, `tasks/**/*.md` |
| 2 | Mémoire Graphiti / JSONL / ingest / verify | `memory/episodes/*.jsonl`, `memory/ingest.py`, `memory/verify.py`, `memory/INDEX.md`, `.github/workflows/` |
| 3 | Rules / skills / context / SSOT docs | `.cursor/rules/*.mdc`, `~/Downloads/.cursor/rules/*.mdc`, `.cursor/context/plan-context.md`, `context-hygiene.mdc`, `docs/AUTHZ_MATRIX.md`, `docs/TEST_PLAN.md`, `workflows/report-format.md`, `.cursor/skills/**`, `.agents/skills/**`, `hooks.json` |
| 4 | CI / scripts / preflight / dispatch / migrations | `.github/workflows/phpunit.yml`, `vitest.yml`, `playwright.yml`, `scripts/check-invariants.sh`, `scripts/safety-check.sh`, `.cursor/hooks/safety-check.sh`, `app/Console/Commands/PreflightProductionCommand.php`, events domaine, `DispatchDomainEventsJob`, `database/migrations/` |

**Contrainte** : pas d’exécution destructive ; agrégation des **chiffres** et **chemins** tels que relevés dans l’arborescence et fichiers ouverts.

---

## 3. Findings P0 (bloquants)

*Définition P0 ici : casse la **preuve** de conformité, le **SSOT**, ou crée un **risque prod silencieux** sans garde-fou machine.*

| # | Finding | Preuve (chemin / chiffre) | Action checklist |
|---|---------|---------------------------|------------------|
| P0-1 | Sentinel `EXECUTE_DELEGATION:` absente ou non conforme sur la majorité des `RUN_*.md` | **113** fichiers `reports/**/RUN_*.md` ; **30/113 = 26,5 %** avec sentinel stricte `EXECUTE_DELEGATION:` | B04, B05, B07, B11, B17, C11, L07 |
| P0-2 | Variantes typographiques **non matching** (audit humain vs machine) | Exemples : `**EXECUTE_DELEGATION**`, `## EXECUTE_DELEGATION` (titre sans ligne sentinel) — cas observés sur exécutions décrites | B05 |
| P0-3 | `ACTIVE_CYCLE.md` autorise **plusieurs** cycles **IN_PROGRESS** | **2** sections simultanées : `CYCLE_W10_EXECUTION_CLOSEOUT (IN_PROGRESS)` et `HOTFIX_W8.5_PHPUNIT_MYSQL_ISOLATION (IN PROGRESS)` dans [`.cursor/ACTIVE_CYCLE.md`](../../.cursor/ACTIVE_CYCLE.md) | B03, B06 |
| P0-4 | `check-invariants.sh` **jamais** invoqué en CI | **3** workflows (`phpunit.yml`, `vitest.yml`, `playwright.yml`) — **0** référence à `scripts/check-invariants.sh` ; script existe avec **6/6** checks | E04, L01 |
| P0-5 | `hooks.json` → `afterFileEdit` → script **no-op** | Comportement décrit : echo + **exit 0** — aucune validation réelle post-édition | L02 |
| P0-6 | **`foodking-invariants.mdc` inexistant** mais référencé | Fichier attendu par nom **absent** ; réel : **`project-invariants.mdc`** — références dans `global.mdc`, `project-continuity.mdc`, `GLOBAL_SYSTEM_PRIMER.md`, `.cursor/agents/app-planner-orchestrator.md`, `.cursor/agents/app-routine-implementer.md` | J11, L09 |
| P0-7 | **Idempotence ingest Graphiti cassée** sans `clear_graph` | `memory/ingest.py` : re-run duplique côté graphe (finding audit mémoire) | A14, M04, M08 |
| P0-8 | `memory/verify.py` : **heuristique** `"uuid"` | Compte sous-chaîne dans sérialisation JSON — **pas** comparaison Neo4j ↔ JSONL | A19 |
| P0-9 | **Aucun** déclencheur auto d’ingest | **0** dans `.github/workflows/`, **0** hook git dédié, **0** cron documenté | A21, M01 |
| P0-10 | `safety-check.sh` **documenté** vs **impl** divergents | `scripts/safety-check.sh` **n’existe pas** ; réel : **`.cursor/hooks/safety-check.sh`** protège **2** fichiers seulement (`OrderService`, `FrontendOrderService`) — doc liste davantage de frozen zones | H12, D06 |
| P0-11 | **Vitest** CI sans push **`develop`** | Workflow `vitest.yml` : déclencheurs observés **only `main`** (finding audit #4) | E06 |
| P0-12 | **Mémoire** : faux nom de test dans épisode | `memory/episodes/10_tests_coverage.jsonl` cite **`IdempotencyTest`** — le test réel est **`IdempotencyBranchScopedTest`** | E15 |
| P0-13 | **`LOG_LEVEL`** via `env()` dans preflight | `PreflightProductionCommand` **l.144** (finding) : piège classique post-`config:cache` | F09 |
| P0-14 | `DispatchDomainEventsJob::failed()` **warning** sans alerte | Log **warning** ; **pas** d’alerte deploy / SIEM raccordée → silence prod possible | G13, I11 |
| P0-15 | Events catalogue **sans** `DispatchableAfterCommit` natif | `ItemCreated`, `ItemDeleted`, `CategoryCreated` / `Updated` / `Deleted` — wrap manuel `DB::afterCommit` ; risque **oubli** nouveau call-site | G11 |
| P0-16 | `tasks/**` **aucun** lien vers primer | **~176** fichiers `tasks/**/*.md` ; **0/176 = 0 %** avec `GLOBAL_SYSTEM_PRIMER.md` | B11, J01 |

---

## 4. Findings P1 (importants)

| # | Finding | Preuve (chemin / chiffre) | Action checklist |
|---|---------|---------------------------|------------------|
| P1-1 | Plans : **`## PRIOR_CONTEXT`** partiel | **38** fichiers `plans/*.md` ; **17/38 = 44,7 %** contiennent `## PRIOR_CONTEXT` | B09 |
| P1-2 | Plans : mention explicite **implementer** rare | **8/38 = 21 %** mentionnent `foodking-routine-implementer` ou `foodking-complex-implementer` | B10 |
| P1-3 | `check-run-delegation-warn.sh` **warn-only** | **Toujours exit 0** — ne bloque jamais (design) | L07 |
| P1-4 | Doublon massif règles **repo** vs **global user** | **12** fichiers `.mdc` présents à la fois dans `.cursor/rules/` et `~/Downloads/.cursor/rules/` | J12 |
| P1-5 | `plan-context.md` **n’oblige pas** les **22** fichiers AGENTS extended | Écart vs liste « Source of truth (extended) » | L05 |
| P1-6 | `context-hygiene.mdc` **PLAN** : ne pas charger code/rapports | Contradiction potentielle avec lecture doc produit en phase PLAN | L04 |
| P1-7 | Skill **`project-handoff`** : **`alwaysApply`** mensonger | Déclare `project-continuity.mdc` alwaysApply ; fichier a **`alwaysApply: false`** | J13 |
| P1-8 | Skills `.agents/` : **`disable-model-invocation: true`** | Ne se déclenchent pas automatiquement | L06 |
| P1-9 | `docs/AUTHZ_MATRIX.md` **désynchronisé** | Admin `branch_id=0` non clarifié (finding doc) | D11, H01 |
| P1-10 | `docs/TEST_PLAN.md` **incomplet** | Nombreuses cases `[ ]` / `[~]` | J15 |
| P1-11 | `workflows/report-format.md` : en-tête **YAML** hors `.cursor/rules/` | Mimetisme règle Cursor — confusion SSOT | J14 |
| P1-12 | **Playwright** opt-in label PR | Label **`e2e-required`** — charge review humaine | E07 |
| P1-13 | Pas de **secret scan** en CI | **0** job `gitleaks` / équivalent observé | E08 |
| P1-14 | Pas de **`composer audit` / `npm audit` / Pint / PHPStan`** en CI | Baseline **0** (finding) | E09, E10, E11, E12 |
| P1-15 | Pas de **husky/lefthook** ; `.git/hooks/` non versionnés | Hooks locaux non normalisés | E13, L03 |
| P1-16 | `app:preflight-production` : **14** dimensions OK mais **trous** prod | Manque **Pusher**, **Redis**, **APP_URL**, disques, **Horizon**, **S3**, **TLS**, mail, cohérence **`config:cache` vs `.env`** | F08 |
| P1-17 | **118** migrations | `database/migrations/` — discipline review + drift CI (rappel) | F13 |
| P1-18 | Mémoire : densité **inégale** | **14** JSONL, **183** lignes totales ; `09_tasks_history.jsonl` **24** lignes ; `13_agents_roles.jsonl` **8** | A22, M09 |
| P1-19 | `memory/INDEX.md` **drift** comptage `12_decisions_log.jsonl` | Ligne tableau indiquait **17** alors que le fichier comptait **18** avant correctif mission (cible **19** après ajout décision) | A16, Tâche INDEX |

---

## 5. Findings P2 (nice-to-have)

| # | Finding | Preuve | Action checklist |
|---|---------|--------|------------------|
| P2-1 | Exemples RUN à corriger (sentinel) | `reports/execution/RUN_V14_GLOBAL_AUDIT_REMEDIATION_2026-04-20.md`, `RUN_P13_LOG_HYGIENE_2026-04-20.md`, `RUN_P_MEGA_W6_A_A11Y_EXECUTE_2026-04-20.md` | B17 |
| P2-2 | Métrique cycles/semaine informelle | Pas de script unique agrégé | B15 (checklist) |
| P2-3 | `preCompact` hook « cosmétique » | Utile mais ne garantit pas SSOT | L02 |
| P2-4 | Couverture domaines **CI/invariants** dans épisodes quasi **absente** | Audit mémoire #2 | M07 |
| P2-5 | Domaine **`auth` / `sessions`** sous-représenté dans JSONL | Audit mémoire #2 | A18, H11 |
| P2-6 | `max_episodes=500` plafond ingest | `memory/ingest.py` | A15 |
| P2-7 | Pas de retry sur `add_memory` failed | ingest | A20 |
| P2-8 | Dashboard backlog **outbox** « réel » manquant | SQL ad hoc / métriques à formaliser | G12 |
| P2-9 | Corrélation `correlation_id` incomplète transversalement | Mention JSONL + logs | G09, I12 |
| P2-10 | Index rapports **manuel** | Pas de générateur | J09 |
| P2-11 | Loom / vidéo onboarding absente | Backlog humain | J03 |
| P2-12 | Traduction EN orchestration partielle | Backlog | J10 |
| P2-13 | Archivage rapports >90j non uniforme | Policy dispersée | J08 |
| P2-14 | Glossaire FoodKing à compléter | `docs/GLOSSARY.md` | J06 |

---

## 6. Tableau de bord conformité

| Métrique | Valeur observée | Fichiers / périmètre |
|----------|-----------------|----------------------|
| `RUN_*.md` total | **113** | `reports/**/RUN_*.md` |
| `EXECUTE_DELEGATION:` strict | **30/113 = 26,5 %** | idem |
| `plans/*.md` total | **38** | `plans/*.md` |
| Avec `## PRIOR_CONTEXT` | **17/38 = 44,7 %** | idem |
| Mention `foodking-*-implementer` | **8/38 = 21 %** | idem |
| `tasks/**/*.md` (~) | **~176** | `tasks/**/*.md` |
| Référence `GLOBAL_SYSTEM_PRIMER.md` | **0/176 = 0 %** | idem |
| Workflows CI observés | **3** | `phpunit.yml`, `vitest.yml`, `playwright.yml` |
| `check-invariants.sh` en CI | **0** job | vs `scripts/check-invariants.sh` existant |
| Fichiers JSONL mémoire | **14** | `memory/episodes/*.jsonl` |
| Lignes JSONL totales | **183** | somme `wc -l` avant ajout décision 2026-04-23 ; **184** après `12_decisions` +1 |

### 6.1 Méthode de mesure `EXECUTE_DELEGATION` (transparence)

Les audits concurrents n’utilisent pas tous la même grammaire. Pour éviter le **trompe-l’œil** entre « mentionné » et « conforme run-cycle », trois comptages sont distingués :

| Méthode | Définition | Résultat re-scan workspace 2026-04-23 |
|---------|------------|----------------------------------------|
| **A — Orchestrateur (audit #1)** | Fichiers avec sentinel **stricte** attendue par `run-cycle.md` | **30/113 = 26,5 %** |
| **B — Ligne outil** | Fichiers contenant une ligne commençant par `EXECUTE_DELEGATION:` | **18/113 ≈ 15,9 %** |
| **C — Mention libre** | Fichiers contenant la sous-chaîne `EXECUTE_DELEGATION` (titre, gras, texte) | **33/113 ≈ 29,2 %** |

**Action** : figer en CI ou pre-commit la méthode **B** (reproductible) et traiter **C** comme **échec** si la ligne exacte manque — voir checklist **B05**, **L02**, **L07**.

### 6.2 Workflows GitHub Actions — déclencheurs (extraits factuels)

**Vitest** — fichier [`.github/workflows/vitest.yml`](../../.github/workflows/vitest.yml) :

```yaml
on:
  pull_request:
    branches: [main, develop]
  push:
    branches: [main]
```

Constat : **`push` sur `develop` absent** → un contributeur poussant uniquement sur `develop` sans PR ouvre **aucun** run Vitest sur ce push.

**PHPUnit (MySQL)** — fichier [`.github/workflows/phpunit.yml`](../../.github/workflows/phpunit.yml) :

```yaml
on:
  pull_request:
    branches: [main, develop]
  push:
    branches: [main, develop]
```

Constat : **aligné** push/PR `develop` pour la suite PHP.

**Playwright** — fichier [`.github/workflows/playwright.yml`](../../.github/workflows/playwright.yml) : commentaire de stratégie explicite (opt-in PR via label `e2e-required`, `workflow_dispatch`, `push` **`main`**). Condition `if:` sur le job — les PR sans label **ne** lancent **pas** E2E.

### 6.3 `check-invariants.sh` absent des workflows

Recherche textuelle dans [`.github/workflows/`](../../.github/workflows/) : **`check-invariants`** → **0** occurrence. Le script [`scripts/check-invariants.sh`](../../scripts/check-invariants.sh) reste **manuel / local** du point de vue CI observée.

### 6.4 Références mortes `foodking-invariants.mdc` (grep dépôt)

Fichier **`.cursor/rules/foodking-invariants.mdc`** : **introuvable**. Le dépôt utilise **`.cursor/rules/project-invariants.mdc`**.

Occurrences relevées (hors ce rapport / méga-checklist réécrite) :

| # | Chemin |
|---|--------|
| 1 | `.cursor/rules/global.mdc` |
| 2 | `.cursor/rules/project-continuity.mdc` |
| 3 | `.cursor/agents/app-planner-orchestrator.md` |
| 4 | `.cursor/agents/app-routine-implementer.md` |
| 5 | `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` (plusieurs mentions) |
| 6 | `tasks/execute-2026-04-20/01_EXECUTE_P11_RETURNED_IDEMPOTENCY.md` |

### 6.5 Inventaire densité `memory/episodes/*.jsonl` ( `wc -l` 2026-04-23 )

| Fichier | Lignes |
|---------|--------|
| `13_agents_roles.jsonl` | 8 |
| `04_pricing_ssot.jsonl` | 10 |
| `08_kds_features.jsonl` | 10 |
| `14_conventions.jsonl` | 10 |
| `01_project_overview.jsonl` | 11 |
| `02_architecture_invariants.jsonl` | 12 |
| `05_fiscal_nf525.jsonl` | 12 |
| `10_tests_coverage.jsonl` | 12 |
| `11_production_plan.jsonl` | 12 |
| `03_domain_events_sync.jsonl` | 14 |
| `06_kiosk_features.jsonl` | 14 |
| `07_pos_features.jsonl` | 16 |
| `12_decisions_log.jsonl` | 18 → **19** après mission |
| `09_tasks_history.jsonl` | 24 |
| **Total** | **183** → **184** |

---

## 7. Couverture mémoire par domaine

| Domaine (indicatif) | État | Commentaire |
|---------------------|------|-------------|
| Sync / outbox / events (`03_`) | **Covered** | JSONL dense, corrigé récemment (finding G02 checklist) |
| Tâches / historique (`09_`) | **Covered** | **24** lignes — le plus dense |
| Décisions (`12_`) | **Covered** | Drift INDEX corrigé en cours (mission 2026-04-23) |
| Rôles agents (`13_`) | **Under** | **8** lignes seulement vs importance orchestration |
| Auth / sessions (épisodes) | **Under** | Peu de faits atomiques dédiés |
| CI / invariants (épisodes) | **Missing / quasi** | Peu ou pas de lignes « preuve CI » |
| Fiscal (`05_`) | **Covered** | Aligné cycles W9 |
| Pricing SSOT (`04_`) | **Covered** | Référence invariants |
| Tests coverage (`10_`) | **Under** | Erreur nom test (`IdempotencyTest`) |

---

## 8. Risques silencieux (minimum 3)

1. **Vitest sur `develop`** : écarts front non détectés sur la branche de travail si seul **`main`** déclenche le workflow — dette accumulée jusqu’au merge.
2. **Outbox / `DispatchDomainEventsJob`** : `failed()` loggue un **warning** sans **alerte** opérationnelle — file d’événements peut **gonfler** ou **perdre** des broadcasts sans page duty.
3. **`LOG_LEVEL` via `env()`** dans `app:preflight-production` : après `config:cache`, valeur **affichée/vérifiée** peut **diverger** de l’effet runtime — **faux sentiment** de conformité au check pre-deploy.

---

## 8bis. Commentaire P0 — lecture ligne par ligne (rappel sans blabla)

- **P0-1 / P0-2** : sans ligne normalisée, **Composer validate** et l’humain ne peuvent pas reconstruire **qui** a exécuté. Les **variantes Markdown** font échouer les regex naïves — d’où triple métrique §6.1.
- **P0-3** : deux **`IN_PROGRESS`** dans [`ACTIVE_CYCLE.md`](../../.cursor/ACTIVE_CYCLE.md) créent ambiguïté sur **TASK_ID** courant, **REPORT_FILE**, et **PHASE** — risque de commits attribués au mauvais cycle.
- **P0-4** : les **6** invariants [`scripts/check-invariants.sh`](../../scripts/check-invariants.sh) sont un **contrat** FoodKing ; s’ils ne tournent pas en CI, le contrat est **prescriptif** seulement.
- **P0-5** : `afterFileEdit` **no-op** donne une **fausse** sensation de garde-fou après édition agent.
- **P0-6** : lien mort vers `foodking-invariants` **casse** la chaîne de confiance « règle → fichier » pour nouveaux modèles.
- **P0-7 / P0-8 / P0-9** : mémoire **non idempotente** + vérif **heuristique** + **pas** d’ingest auto ⇒ dérive **JSONL ≠ graphe** sans alarme.
- **P0-10** : écart **doc frozen** vs **2** fichiers dans [`.cursor/hooks/safety-check.sh`](../../.cursor/hooks/safety-check.sh) — les autres zones sensibles ne sont pas couvertes par ce hook.
- **P0-11** : trou Vitest **push develop** — §6.2.
- **P0-12** : épisode faux **nom de test** pollue `search_memory_facts` et les agents qui s’en remettent à la mémoire.
- **P0-13** : `env()` post-cache — classique Laravel ; impact **direct** sur lecture `LOG_LEVEL` en préflight.
- **P0-14** : `failed()` job sans **page** ⇒ file d’événements **pourrit** silencieusement.
- **P0-15** : pattern **manuel** `DB::afterCommit` pour **5** events catalogue ⇒ dette **humaine** (oubli de call-site).
- **P0-16** : **0 %** tâches → **Primer** ⇒ chaque exécutant re-découvre le **cadre** à coût tokens.

---

## 9. Plan de remédiation prioritaire (5 lots)

| Lot | Contenu | Sections checklist |
|-----|---------|-------------------|
| **L1 — SSOT & liens morts** | Corriger `foodking-invariants` → `project-invariants`, INDEX drift, `10_tests_coverage` Idempotency | **J11**, **L09**, **E15**, **A16** |
| **L2 — Preuve délégation** | Sentinel `EXECUTE_DELEGATION`, lint typographie, template RUN, rétrofill prioritaire | **B04–B07**, **B17**, **C11** |
| **L3 — CI bloquante minimale** | `check-invariants.sh` job required, Vitest `develop`, secret scan phase 1 | **E04**, **E06**, **E08** |
| **L4 — Mémoire Graphiti** | Idempotence ingest, verify.py réel, hook post-merge, schéma JSONL | **A14**, **A19–A21**, **M01–M03** |
| **L5 — Prod / sync silencieux** | Preflight dimensions manquantes, `LOG_LEVEL` config(), alertes `DispatchDomainEventsJob`, events Item/Category | **F08–F09**, **G11–G13**, **I11** |

---

## 10. Verdict final

- **Autonomie agentique** : **praticable** sur **lots fermés** avec orchestrateur qui **impose** plan fichier, sous-agents, et mise à jour `ACTIVE_CYCLE.md`.  
- **Infrastructure de gouvernance** : **solide en documentation** (**8/10**), **faiblement instrumentée** par la machine (**3,5/10** sur preuves CI/hooks).  
- **Note globale** : **6/10** — le système **fonctionne** quand l’humain **force** la discipline ; il **dérive** dès que les sentinels sont contournés (ce qui est **facile** aujourd’hui).  
- **Alignement utilisateur** : la **réduction arbitraire** de la méga-checklist à **100** items est **rejetée** ; la **v2** repose sur **~180** tâches mesurables + **sections L/M** (lecture machine, mémoire continue).

---

## 11. Annexe — Zones investiguées (pas de fichiers explore stockés ici)

Les quatre volets **explore** ont systématiquement parcouru :

- **Cycle** : `.cursor/ACTIVE_CYCLE.md`, `reports/**/RUN_*.md` (**113**), `plans/*.md` (**38**), `tasks/**/*.md` (**~176**), scripts `check-run-delegation-warn.sh`.
- **Mémoire** : `memory/episodes/*.jsonl` (**14** fichiers, **183** lignes), `memory/ingest.py`, `memory/verify.py`, `memory/INDEX.md`, `.github/workflows/` (0 hook ingest).
- **Rules/skills** : `.cursor/rules/*.mdc`, miroir `~/Downloads/.cursor/rules/`, `AGENTS.md` (références extended), `.cursor/context/plan-context.md`, `context-hygiene.mdc`, `docs/AUTHZ_MATRIX.md`, `docs/TEST_PLAN.md`, `workflows/report-format.md`, `.cursor/skills/project-handoff/SKILL.md`, `.agents/skills/**`, `hooks.json`, `.cursor/hooks/post-edit-check.sh`, `.cursor/hooks/safety-check.sh`.
- **CI/hardening** : `.github/workflows/phpunit.yml`, `vitest.yml`, `playwright.yml`, `scripts/check-invariants.sh`, `scripts/safety-check.sh` (absent), `app/Console/Commands/PreflightProductionCommand.php`, événements `ItemCreated` / `ItemDeleted` / `Category*`, `DispatchDomainEventsJob`, `database/migrations/` (**118** fichiers).

**Références utiles (hors explore)** :

- [`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`](../../docs/orchestration/GLOBAL_SYSTEM_PRIMER.md)
- [`memory/INDEX.md`](../../memory/INDEX.md)
- [`reports/audit/VERIFICATION_MASSIVE_REPORT_2026-04-22.md`](VERIFICATION_MASSIVE_REPORT_2026-04-22.md) *(si présent dans le dépôt)*
- [`reports/audit/AUDIT_GRAPHITI_SYSTEM_DEEP_2026-04-22.md`](AUDIT_GRAPHITI_SYSTEM_DEEP_2026-04-22.md) *(si présent)*

---

## 12. Historique de version du présent rapport

| Version | Changement |
|---------|------------|
| v1 | Narratif initial ; checklist associée à **100** items (version rejetée utilisateur). |
| v2 | Intégration **quatre audits explore** ; tableaux P0/P1/P2 ; **dashboard** % ; **risques silencieux** ; **plan 5 lots** ; lien **180** tâches **A–M**. |
| v2.1 | Ajout §6.1–6.5 (transparence métriques, YAML CI, inventaire JSONL, références mortes), §8bis P0 narratif dense. |

---

## 13. Cartographie rapide méga-checklist ↔ lots (180 tâches)

| Section | Thème | # items (approx.) |
|---------|--------|-------------------|
| A | Graphiti & mémoire | 23 |
| B | Cycle borné & état | 17 |
| C | Sub-agents & intelligence | 14 |
| D | Gates & humain | 12 |
| E | Tests & CI | 20 |
| F | Fiscal & prod | 13 |
| G | Sync & broadcast | 13 |
| H | Sécurité & multi-tenant | 13 |
| I | Observabilité & ops | 12 |
| J | Documentation & onboarding | 15 |
| K | Produit & dette | 10 |
| L | Discipline lecture & règles machine | 9 |
| M | Mémoire continue | 9 |
| | **Total** | **180** |

---

## 14. Exemples `RUN_*.md` cités comme non conformes (preuve qualitative)

Fichiers explicitement mentionnés dans le brief orchestrateur comme **décrivant du code / exécution** sans sentinel conforme (à traiter en **B17** + rétrofill **B04**) :

1. [`reports/execution/RUN_V14_GLOBAL_AUDIT_REMEDIATION_2026-04-20.md`](../../reports/execution/RUN_V14_GLOBAL_AUDIT_REMEDIATION_2026-04-20.md)
2. [`reports/execution/RUN_P13_LOG_HYGIENE_2026-04-20.md`](../../reports/execution/RUN_P13_LOG_HYGIENE_2026-04-20.md)
3. [`reports/execution/RUN_P_MEGA_W6_A_A11Y_EXECUTE_2026-04-20.md`](../../reports/execution/RUN_P_MEGA_W6_A_A11Y_EXECUTE_2026-04-20.md) — cas **variante typographique** signalée

---

## 15. Limites de cet audit (hors scope)

- **Pas** d’exécution `phpunit` / `vitest` / ingest Neo4j dans ce document — uniquement **lecture** fichiers et **comptages** statiques.
- Les pourcentages **plans/tasks** supposent l’état **2026-04-23** ; une évolution du nombre de fichiers `plans/*.md` ou `tasks/**` invalide les dénominateurs — recalculer avec `find` / `wc`.
- Fichier [`RUN_P13_LOG_HYGIENE_2026-04-20.md`](../../reports/execution/RUN_P13_LOG_HYGIENE_2026-04-20.md) : **existe** ; voisin [`RUN_P13_AUDIT_REPORT_HYGIENE_2026-04-20.md`](../../reports/execution/RUN_P13_AUDIT_REPORT_HYGIENE_2026-04-20.md).

---

## 16. Hooks Cursor observés (discipline machine)

| Artefact | Rôle déclaré | Observation |
|----------|--------------|-------------|
| [`hooks.json`](../../hooks.json) | `afterFileEdit` → script post-édition | **No-op** opérationnel (echo, exit 0) — finding P0-5 |
| [`.cursor/hooks/safety-check.sh`](../../.cursor/hooks/safety-check.sh) | Frozen zones | **2** chemins surveillés — finding P0-10 |
| [`.cursor/hooks/post-edit-check.sh`](../../.cursor/hooks/post-edit-check.sh) | Contrôle post-edit | À aligner sur checklist **L02** (non bloquant aujourd’hui) |
| `scripts/check-run-delegation-warn.sh` | Délégation | **Warn-only**, exit 0 — **P1-3** |

**Husky / lefthook** : **non** présents dans le dépôt au sens **fichiers de config** habituels — hooks Git **non versionnés** (finding P1-15).

---

## 17. `app:preflight-production` — 14 dimensions documentées vs trous (rappel W9)

Les **14** dimensions livrées dans le cycle **W9** (voir [`ACTIVE_CYCLE.md`](../../.cursor/ACTIVE_CYCLE.md) section PROD-4) incluent notamment : `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `TIMEZONE`, `CACHE_DRIVER`, `QUEUE_CONNECTION`, `BROADCAST_DRIVER`, `SESSION_DRIVER`, `LOG_LEVEL`, `LOG_CHANNEL`, secrets fiscal min 32, `FISCAL_VERIFY_CHAIN_BEFORE_ARCHIVE`, reachability **DB**, **cache round-trip**.

**Trous P1** (audit hardening #4) à traiter en **F08** : validation explicite **Pusher**, **Redis** (au-delà du simple driver name), **`APP_URL`**, **disques** writable (`storage`, `bootstrap/cache`), **Horizon** si queues Redis actives, **S3** si driver `s3`, **TLS** termination, **mail** transport, cohérence **`php artisan config:cache`** vs valeurs **`.env`** attendues en prod.

---

## 18. Événements domaine `Item*` / `Category*` & commit (finding #4)

**Constat** : `ItemCreated`, `ItemDeleted`, `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted` **n’implémentent** pas toutes le trait **`DispatchableAfterCommit`** de la même façon que les événements « critiques » déjà traités sous **gate C9** ; le code peut s’appuyer sur **`DB::afterCommit`** manuel dans les services.

**Risque** : tout **nouveau** call-site qui dispatch ces events **sans** le wrapper devient une **régression** silencieuse du type observé sur `OrderCreated` (V4 sentinelle). **Mitigation** checklist : **G11** (uniformiser ou auditer exhaustivement).

---

## 19. Migrations & dette schéma

- Dénombrement statique : **`database/migrations/*.php`** → **118** fichiers (finding audit #4).
- Le workflow **PHPUnit** inclut déjà un step **« Migration drift check »** (voir commentaires en tête de `phpunit.yml`) — **distinct** de `check-invariants.sh`.

---

## 20. Skills `.agents/` vs `.cursor/skills/`

- Les skills sous **`.agents/skills/`** portent souvent **`disable-model-invocation: true`** — elles **ne se déclenchent pas** automatiquement quand l’utilisateur ne les cite pas (finding P1-8).
- **`.cursor/skills/project-handoff/SKILL.md`** : incohérence **`alwaysApply`** sur `project-continuity.mdc` (finding P1-7).

---

## 21. Plan `tasks/**` vs `GLOBAL_SYSTEM_PRIMER.md`

- Dénominateur brief : **~176** fichiers Markdown sous `tasks/`.
- Couverture : **0** référence au **Primer** — les exécutants ne sont **pas** forcés de charger le **cadre global** avant une tâche locale.

---

## 22. Synthèse chiffrée « une page » (copier-coller stand-up)

```
RUN_*.md total ................. 113
EXECUTE_DELEGATION (orch strict) 30/113 (26,5 %)
EXECUTE_DELEGATION (ligne ^...) 18/113 (15,9 %)
plans total .................... 38
PRIOR_CONTEXT .................. 17/38 (44,7 %)
implementer explicite .......... 8/38 (21 %)
tasks → Primer ................. 0/176 (0 %)
workflows CI ................... 3 (phpunit, vitest, playwright)
check-invariants en CI ......... 0
JSONL fichiers ................. 14
JSONL lignes (avant décision) .. 183
JSONL lignes (après +1 ADR) .... 184
migrations PHP ................. 118
foodking-invariants.mdc ........ ABSENT (→ project-invariants.mdc)
```

---

*Fin du méga-audit v2. Tâches mesurables : [`MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md`](../../docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md).*
