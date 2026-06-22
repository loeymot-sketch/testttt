# Multi-Agent Loop — Pivot 2026-05-02

**Statut** : SSOT procédurale du pivot multi-agents FoodKing.
**Annule et remplace** la doctrine "GPT-only / Composer disabled" (cycles de finition Caisse V1) sur le seul axe **EXECUTE**. Toutes les autres règles (gates, invariants, frozen zones, double audit) restent **inchangées**.

> Si ce fichier diverge de `.cursor/routing.md`, `AGENTS.md` ou `.cursor/rules/global.mdc`, **les fichiers d'autorité l'emportent** ; mettre à jour ce fichier en conséquence.

---

## 1. Trois agents, trois rôles, zéro ambiguïté

| Agent | Rôle exclusif | Canal d'invocation |
|---|---|---|
| **Claude** (chat session par défaut) | **PLAN** + **AUDIT post-impl** + **escalade critique** + déclaration du `EXECUTION_TIER` | Chat Cursor (modèle Claude). Pour AUDIT : terminal `bash scripts/foodking-claude-orchestrate.sh audit` (PRIMARY) ou Task `foodking-planner-orchestrator` (fallback). |
| **Composer** (Max mode + thinking) | **EXECUTE routine** (tier S) | Task Cursor `subagent_type: "foodking-routine-implementer"` |
| **GPT-5.5-pro xhigh** (Codex) | **EXECUTE complex** + **PLAN_REVIEW** + **GPT_FINAL_AUDIT** | PRIMARY : `npm run codex:plan-review`, `npm run codex:complex`, `npm run codex:final-audit` (CLI `codex`, ChatGPT Pro). FALLBACK EXECUTE : Task `subagent_type: "foodking-complex-implementer"` |

**Anti-dérive Claude** : Claude (chat) **ne fait pas** d'édition produit dans `app/`, `resources/`, `routes/`, `database/`, `tests/`, `bootstrap/`, `config/`, `composer.json`, `package.json`. Sa mission unique en EXECUTE = **déléguer**. Hot-fix doctrine/orchestration (`.cursor/`, `AGENTS.md`, `docs/orchestration/`, `plans/`, `reports/`, `memory/episodes/*.jsonl`) reste autorisé.

---

## 2. Tier-routing déterministe (routine vs complex)

Une tâche est **routine** si **toutes** les conditions sont vraies :

1. Effort **S** : ≤2h dev+tests, ≤5 fichiers touchés.
2. **Aucun** invariant FoodKing critique en scope :
   - Pricing logic (backend = SSOT)
   - `OrderStatus` enum
   - `branch_id` data isolation
   - Dispatch logic (event/job)
   - `OrderService` / `FrontendOrderService` symmetry
   - Frozen zones
   - Schema / DDL / migration
   - Auth / middleware / guards / tokens
3. **Pas** de nouveau service, **pas** de refactor cross-module (>2 modules).
4. Tests à écrire ≤ 2 nouveaux fichiers, pas de réécriture de suite existante.

Une seule condition fausse → **complex** → Codex.
Doute → **complex** par défaut (« partial > wrong »).

Le tier est inscrit en clair dans le plan : `EXECUTION_TIER: routine` ou `EXECUTION_TIER: complex`.

---

## 3. Procédure pas-à-pas (par phase)

### 3.1 PLAN — Claude (chat)

1. Lire `.cursor/ACTIVE_CYCLE.md` (continuation ou nouveau cycle).
2. Lire `tasks/<TASK_ID>.md`.
3. Graphiti : `search_memory_facts(group_ids=["foodking"])` sur le domaine.
4. Réserver le scope : `bash scripts/agent-activity-log.sh start cursor-claude <TASK_ID> plan "<note>"`.
5. Rédiger `plans/PLAN_<TASK_ID>_<DATE>.md` (depuis `plans/PLAN_TEMPLATE.md`) avec champs **obligatoires** :
   - `TASK_ID`, `PRIMARY_EXECUTION_MODEL`, `REASONING_EFFORT`
   - `EXECUTION_TIER: routine | complex` ← **nouveau, obligatoire**
   - `SUBSYSTEMS_TOUCHED`, `SUBSYSTEMS_OFF_LIMITS`
   - `INVARIANTS_AT_RISK`, `GATE_CONDITIONS`
   - `PLAN_REVIEW: pending`
6. Mettre à jour `ACTIVE_CYCLE.md` : `PHASE: PLAN`, `PLAN_FILE`, etc.

### 3.2 PLAN_REVIEW — GPT/Codex

```bash
npm run codex:plan-review -- <TASK_ID>
```

Verdict dans le plan : `PLAN_REVIEW_VERDICT: PASS | REWORK | ESCALATE`. PASS requis avant EXECUTE.

### 3.3 EXECUTE — selon `EXECUTION_TIER`

#### Si `EXECUTION_TIER: routine` → **Composer**

Claude (chat) délègue via `Task` :

```
Task(
  subagent_type: "foodking-routine-implementer",
  description: "EXECUTE routine <TASK_ID>",
  prompt: "<contexte plan + fichiers + critères acceptation + tests à écrire/débloquer>"
)
```

Le sub-agent applique strictement le plan, écrit les tests, lance Vitest/PHPUnit local.
Trace écrite dans `reports/post_execute_latest.log` :
```
EXECUTE_DELEGATION: foodking-routine-implementer
EXECUTION_TIER: routine
```

**Halt-condition Composer** : si pendant l'exécution un invariant critique surgit dans la diff → halt, log `ESCALATION` dans le plan, retour à Claude pour replan en tier complex.

#### Si `EXECUTION_TIER: complex` → **Codex** (PRIMARY)

```bash
# 1. Préparer la mission
npm run codex:prepare -- <TASK_ID>

# 2. Lancer
npm run codex:complex -- <TASK_ID>
# → missions/<TASK_ID>/output_codex.json
# → reports/audit/GPT_SELF_AUDIT_<TASK_ID>.md
```

Trace :
```
EXECUTE_DELEGATION: codex-extension
EXECUTION_TIER: complex
```

**FALLBACK** : si `codex` indispo après ≥2 reprises documentées → Task `foodking-complex-implementer` avec `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:`.

### 3.4 VALIDATE — automatisé

```bash
bash .cursor/hooks/post-execute-guard.sh
# ou tests ciblés selon plan
```

Sortie → `reports/post_execute_latest.log`. Aucune correction produit ici → retour à l'EXECUTE du tier d'origine si REWORK.

### 3.5 AUDIT — Claude terminal (PRIMARY)

```bash
bash scripts/foodking-claude-orchestrate.sh context
bash scripts/foodking-claude-orchestrate.sh audit-brief  # ou audit
```

Verdict :
```
AUDIT_VERDICT: PASS | REWORK
AUDIT_CHANNEL: claude-terminal
TERMINAL_AUDIT_OK: 1
```

**Fallback quota/HS** : Task `foodking-planner-orchestrator` ou session Claude in-chat → `AUDIT_CHANNEL: cursor-session` + `AUDIT_FALLBACK_REASON:` obligatoire.

### 3.6 GPT_FINAL_AUDIT — GPT/Codex

```bash
npm run codex:final-audit -- <TASK_ID>
```

Verdict : `GPT_FINAL_AUDIT_VERDICT: PASS | REWORK | ESCALATE`.

### 3.7 CLOSE — double PASS obligatoire

Close ssi `AUDIT_VERDICT: PASS` **ET** `GPT_FINAL_AUDIT_VERDICT: PASS`.
Sur REWORK : boucle `replan (Claude) → EXECUTE (tier d'origine ou re-tiered) → VALIDATE → AUDIT → FINAL_AUDIT` avec `REMEDIATION_AUDIT_CYCLE: 1..5`.
Au 5ᵉ REWORK sans double PASS → **HUMAN_GATE**.

À la close : `bash scripts/agent-activity-log.sh done cursor-claude <TASK_ID> done "<résumé>"`.

---

## 4. Règles dures pour Claude orchestrateur (chat)

| Action | Autorisée pour Claude (chat) ? |
|---|---|
| Rédiger / amender un plan dans `plans/` | ✅ |
| Rédiger un audit / report dans `reports/` | ✅ |
| Mettre à jour `.cursor/ACTIVE_CYCLE.md` | ✅ |
| Mettre à jour `AGENTS.md`, `.cursor/rules/*.mdc`, `.cursor/routing.md`, `docs/orchestration/*.md` (doctrine) | ✅ (hot-fix orchestration) |
| Ajouter / amender un episode `memory/episodes/*.jsonl` | ✅ |
| Éditer `app/`, `resources/`, `routes/`, `database/`, `tests/`, `bootstrap/`, `config/`, `composer.json`, `package.json` (édition produit) | ❌ → **déléguer** Composer ou Codex |
| Auto-approuver un gate humain | ❌ |
| Sauter une phase | ❌ |
| Modifier `.cursor/routing.md` pendant un cycle actif | ❌ (sauf hot-fix doctrine entre cycles) |

Si Claude (chat) édite un fichier produit "par convenance" → **violation** doctrinale ; à consigner dans `reports/AGENT_ACTIVITY_LOG.md`.

---

## 5. Exemple concret — délégation routine via Task

Claude (chat), pour une tâche `M1-1.4` (warning channels=NULL, S effort, hors invariants) :

```
Task(
  subagent_type: "foodking-routine-implementer",
  description: "EXECUTE M1-1.4 channels-null warning",
  prompt: """
  Tâche : M1-1.4 — Service CatalogWarningService — émettre warning si Item.channels=NULL.

  Plan : plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md (Wave 1, §1.4)
  Fichiers à toucher (≤3) :
    - app/Services/Catalog/CatalogWarningService.php (existe, à compléter)
    - resources/js/components/admin/items/ComposerProfileWarningBadge.vue (déjà créé)
    - tests/Feature/Catalog/ChannelsNullWarningTest.php (existe, markTestSkipped à retirer)

  Invariants : aucun en scope (warning purement informatif, ne bloque rien).

  Critères d'acceptation :
    1. Méthode CatalogWarningService::warnIfChannelsNull(Item $item): ?string
    2. Retourne string i18n key "warning.catalog.channels_null" si null, sinon null
    3. Test PHPUnit unskippé et passant
    4. Lint propre (phpcs + eslint)

  Halt si tu rencontres : édit pricing logic, dispatch, OrderStatus, branch_id, schema.
  Trace : EXECUTE_DELEGATION: foodking-routine-implementer dans reports/post_execute_latest.log
  """
)
```

---

## 6. Vérification d'environnement

```bash
npm run verify:boucle              # binaire claude + validate-active-cycle + sections doc
VERIFY_BILLING_FULL=1 npm run verify:boucle  # + 1 requête API Claude + 1 smoke Codex
```

L'absence de `claude` ou de `codex` n'empêche pas le pivot multi-agents (le sub-agent Cursor `foodking-complex-implementer` reste un fallback opérationnel), mais doit être notée dans le plan.

---

## 7. Carte rapide pour Claude orchestrateur

```
Tâche reçue → Lire plan / handoff
            → Classifier tier (matrice §2)
            → Si tier indécidable → tier = complex (prudence)
            → Réserver activity-log
            → routine ? → Task(foodking-routine-implementer)
                       → tracer EXECUTE_DELEGATION
            → complex ? → npm run codex:complex (PRIMARY)
                       → fallback Task(foodking-complex-implementer)
                       → tracer EXECUTE_DELEGATION + FALLBACK_REASON si fallback
            → VALIDATE
            → AUDIT (Claude terminal PRIMARY)
            → GPT_FINAL_AUDIT
            → double PASS → CLOSE + activity-log done
```

Aucune autre voie n'est autorisée.
