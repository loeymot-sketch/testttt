# FoodKing – Model Routing Policy

Auto/Premium routing: DISABLED
One PRIMARY_MODEL per cycle. Assignment is explicit in every plan file.

---

## Routing Table

| Phase | Model | Permitted scope |
|---|---|---|
| PLAN | Claude | **Typiquement en session Cursor** (orchestrateur) : lire la tâche, écrire le plan, signaler invariants / gates. Peut s’orchestrer 100 % terminal, mais n’est **pas** soumise à la règle « abonnement d’abord » (c’est l’intelligence de dialogue, pas l’API proxy codex / pas l’audit terminal). |
| EXECUTE — complex (PRIMARY) | **`codex-extension`** — GPT-5.5 / GPT-5.5-pro via **CLI `codex`** (compte **ChatGPT Pro** — *Sign in with ChatGPT*, pas de clé API dans le dépôt) | Préparer `missions/{TASK_ID}/input.json` (+ contextes), `npm run codex:complex -- {TASK_ID}` (`bash scripts/codex-extension-execute.sh`), appliquer `output_codex.json`, `EXECUTE_DELEGATION: codex-extension`, auto-audit → `reports/audit/GPT_SELF_AUDIT_*.md`. Voir `docs/orchestration/CODEX_API_DELEGATION.md`. **Legacy (proxy+clé)** : `npm run codex:complex:proxy-legacy` — urgence seulement. |
| EXECUTE — complex (FALLBACK) | Sub-agent Cursor `foodking-complex-implementer` | **Uniquement** si le binaire `codex` / Pro échoue (≥2 tentatives) ou tâches complexes impossibles en `codex exec` documentées. **Facturé côté Cursor (usage de l’abonnement Cursor)**. Trace : `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:`. |
| EXECUTE — routine | Composer (sub-agent `foodking-routine-implementer`) | CRUD, config, UI copy, boilerplate — facturation Cursor. |
| VALIDATE | Composer | Diff summary, test results, anomaly flags, report draft |
| **AUDIT (PRIMARY)** | **Claude** via **terminal** | **`bash scripts/foodking-claude-orchestrate.sh context` puis `audit` ou `audit-brief`**. S’appuie sur l’**abonnement Anthropic** (claude CLI) — ne consomme pas l’orchestrateur de modèles de Cursor. Trace **obligatoire en lot** dès qu’un audit terminal a **réussi** : `AUDIT_CHANNEL: claude-terminal` **et** `TERMINAL_AUDIT_OK: 1`. Si l’appel échoue, **ne pas** tracer seul le canal `claude-terminal` sans `TERMINAL_AUDIT_OK: 1` : retenter 1× ou `AUDIT_CHANNEL: cursor-session` + `AUDIT_FALLBACK_REASON:` (voir `run-cycle.md` Step 5). |
| **AUDIT (FALLBACK)** | **Claude** en **session Cursor** | Uniquement si le terminal ne peut **pas** auditer (binaire `claude` absent, auth/quota, réseau) après une tentative. **Consomme le compte côté Cursor (modèle de la session).** Trace : `AUDIT_CHANNEL: cursor-session` + **`AUDIT_FALLBACK_REASON: <1 ligne>`** obligatoire. |
| GATE BRIEF | Claude → Human | Même règle d’orchestrateur, mais brouillon de gate côté procédure humaine. |
| REPORT | Composer | Cycle summary aligned to `reports/` discipline |

---

## Hard Boundaries

**Claude**
- No product/application implementation code (`app/`, `resources/`, `routes/`, etc.)
- No edits to product/application source files
- **May** write governance artifacts: plan files under `plans/`, gate briefs under `docs/gates/`, and cycle metadata per workflow (e.g. `ACTIVE_CYCLE.md` where the procedure requires it)
- Sole author of plan files, audit records, and gate briefs

**GPT-5.5**
- No planning, no self-routing, no auditing
- Executes within plan scope only — does not redefine it
- **Schema, migrations, and DDL** are **non-routine**: only here, only when explicitly listed in `SUBSYSTEMS_TOUCHED` with gates satisfied as required
- No auth changes or external service wiring unless explicitly scoped
- No frozen zone edits without gate clearance

**Composer**
- **No** `database/migrations`, migration stubs, schema, or DDL — not even “scaffold-only”; route schema work to GPT-5.5 (complex) with explicit plan scope
- No auth, sync, pricing, dispatch, or `branch_id` filtering logic
- No frozen zone edits
- No architectural decisions
- No gate briefs

---

## FoodKing Routing Triggers

| Condition | Routing consequence |
|---|---|
| `OrderService` or `FrontendOrderService` in scope | GPT-5.5 + symmetry check required in plan |
| Pricing logic in scope | Claude confirms backend-first in plan before routing to GPT-5.5 |
| `OrderStatus` reference in scope | GPT-5.5 must reference enum from code — no strings |
| Dispatch logic in scope | GPT-5.5 + post-commit constraint explicit in plan |
| `branch_id` filtering or scoping in scope | GPT-5.5 + isolation logic declared in plan |
| Frozen zone file in scope | Gate brief required before any implementation begins |
| Schema / migrations / DDL in scope | **Complex (GPT-5.5)** only, explicitly declared; **never** Composer (routine) |

---

## Escalation Protocol
If Composer or GPT-5.5 discovers a scope gap or invariant conflict mid-cycle:
1. Stop execution
2. Log under `ESCALATION` in the active plan file
3. Do not self-resolve — Claude reviews and decides: re-plan or gate

Mid-cycle model switch requires Claude confirmation logged in the plan file.

---

## Routing Integrity
This file is version-controlled and may not be modified during an active cycle.
Routing changes require a plan-phase Claude decision recorded in `docs/gates/GATE_LOG.md`.
