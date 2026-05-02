# FoodKing – Model Routing Policy

Auto/Premium routing: DISABLED  
One PRIMARY_EXECUTION_MODEL per cycle.

**SSOT en cas de contradiction** : si ce fichier entre en conflit avec `CLAUDE.md`, `.cursor/rules/global.mdc`, ou `.cursor/commands/run-cycle.md`, **les documents constitutionnels ci-dessus l’emportent** ; mettre à jour `routing.md` en conséquence (pas l’inverse). Changement de routing : décision de plan / gate tracée (`docs/gates/GATE_LOG.md` si requis).

**Doctrine synchronisée (2026-05-02 — pivot multi-agents)** :
- **Claude (chat session par défaut, ou sub-agent `foodking-planner-orchestrator`)** = **PLAN**, **AUDIT post-impl**, escalade critique. **Ne fait pas** d'implémentation produit.
- **Composer (sub-agent `foodking-routine-implementer`, Max mode + thinking)** = **EXECUTE routine** (S effort, hors invariants critiques).
- **GPT-5.5-pro xhigh (`codex-extension` — CLI `codex` Pro, fallback sub-agent `foodking-complex-implementer`)** = **EXECUTE complex** (M/L/XL effort OU invariants critiques), **PLAN_REVIEW**, **GPT_FINAL_AUDIT**.

Tier-routing **déterministe** : voir matrice §Tier-Routing ci-dessous **et** `docs/orchestration/MULTI_AGENT_LOOP_2026-05-02.md` (SSOT procédurale du pivot).

---

## Routing Table — Multi-Agent Loop (2026-05-02)

| Phase | Canal | Permitted scope |
|---|---|---|
| **PLAN** | **Claude** (session Cursor par défaut ; sinon Task `foodking-planner-orchestrator`) | Rédige / amende `plans/PLAN_<TASK_ID>_<DATE>.md`, déclare `SUBSYSTEMS_TOUCHED`, invariants, gates, **`EXECUTION_TIER: routine \| complex`**. **Pas** d'implémentation produit. |
| **PLAN_REVIEW** (mandatory) | **GPT-5.5-pro xhigh** via **`codex-extension`** | `npm run codex:plan-review -- <TASK_ID>`. Second avis avant EXECUTE. Trace : `PLAN_REVIEW_VERDICT: PASS \| REWORK \| ESCALATE`. |
| **EXECUTE — routine** | **Composer** via Task `foodking-routine-implementer` | Tâches **S effort** (≤2h, ≤5 fichiers, hors `app/Services/Order*`, pricing, `branch_id`, dispatch, schema, auth, frozen). Trace : `EXECUTE_DELEGATION: foodking-routine-implementer`. |
| **EXECUTE — complex (PRIMARY)** | **`codex-extension`** — GPT-5.5-pro xhigh CLI `codex` (compte ChatGPT Pro) | M/L/XL effort OU invariants critiques. `npm run codex:complex -- <TASK_ID>` → `output_codex.json` + `GPT_SELF_AUDIT_*.md`. Trace : `EXECUTE_DELEGATION: codex-extension`. |
| **EXECUTE — complex (FALLBACK)** | Sub-agent Cursor **`foodking-complex-implementer`** | Si `codex` / Pro indispo après ≥2 reprises documentées. Trace : `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:`. |
| **VALIDATE** | Session Cursor / hooks / tests | `post-execute-guard.sh`, PHPUnit, Vitest, lint. Aucune correction produit ici → retour à l'EXECUTE du tier d'origine. |
| **AUDIT (PRIMARY)** | **Claude — terminal** (`foodking-claude-orchestrate.sh` **context** puis **audit-brief** / **audit**) | `AUDIT_VERDICT: PASS \| REWORK`, `AUDIT_CHANNEL: claude-terminal`, `TERMINAL_AUDIT_OK: 1`. **Fallback** quota/HS : Task `foodking-planner-orchestrator` ou session Claude + `AUDIT_FALLBACK_REASON:`. |
| **GPT_FINAL_AUDIT** (mandatory) | **GPT-5.5-pro xhigh** via **`codex-extension`** | `npm run codex:final-audit -- <TASK_ID>`. Verdict final après PASS Claude. Pas de close sans double PASS. |
| **Escalade critique** | **Claude** (chat ou terminal) | Arbitrage gate / invariant / conflit d'audits — pas un canal AUDIT de routine. |
| **GATE BRIEF** | Rédaction Claude → décision **Humain** | `docs/gates/GATE_*.md` |
| **REPORT / VALIDATE summary** | Composer (sans écriture produit) | Synthèses, exécution de tests, rapports — jamais d'édition hors plan. |

---

## Tier-Routing — classification déterministe routine vs complex

Une tâche est **routine** si **toutes** les conditions sont vraies :
1. Effort **S** (≤2h dev + tests, ≤5 fichiers touchés).
2. **Aucun** invariant critique en scope : pricing logic, `OrderStatus` enum, `branch_id` data isolation, dispatch logic, `OrderService`/`FrontendOrderService` symmetry, frozen zones, schema/DDL/migration, auth/middleware/guards.
3. Pas de nouveau service ni de refactor cross-module.
4. Tests à écrire ≤ 2 nouveaux fichiers de tests, pas de réécriture de suite existante.

Si **une seule** condition tombe → tâche **complex** → routage Codex.

En cas de doute → **complex par défaut** (principe de prudence FoodKing : « partial > wrong »).

---

## Hard Boundaries

**Claude**
- **Peut** : orchestrer le plan (`plans/*.md`), auditer le cycle (terminal), produire briefs / gates, escalader.
- **Ne peut pas** : implémenter du code applicatif (`app/`, `resources/js` produit, `routes/` métier, etc.) ; contourner les gates humains ; éditer frozen zones sans gate.

**GPT-5.5 / Codex**
- PLAN_REVIEW, EXECUTE produit, GPT_FINAL_AUDIT dans le périmètre du plan ; invariants FoodKing ; pas d’auto-approbation des gates humains.

**Composer (`foodking-routine-implementer`)**
- **Peut** : EXECUTE routine (tier S, hors invariants critiques) ; tests unit/integration locaux ; UI cosmétique scoped ; documentation in-code ; rapports de validation.
- **Ne peut pas** : migrations / DDL / auth / sync produit / pricing logic / `branch_id` / `OrderStatus` enum / dispatch logic / frozen zones / décision d'architecture / refactor cross-module. Sur contact avec un de ces périmètres → halt + `ESCALATION` dans le plan → repassage en EXECUTE complex (Codex).

---

## FoodKing Routing Triggers

| Condition | Routing consequence |
|---|---|
| `OrderService` or `FrontendOrderService` in scope | Symmetry review dans le plan + EXECUTE |
| Pricing logic in scope | Backend SSOT explicit dans le plan |
| `OrderStatus` reference in scope | Enum depuis le code — pas de chaînes libres |
| Dispatch logic in scope | Post-commit explicit dans le plan |
| `branch_id` in scope | Isolement déclaré dans le plan |
| Frozen zone in scope | Gate brief avant impl |
| Schema / DDL in scope | GPT complexe + gate ; jamais Composer routine |

---

## Escalation Protocol

Si scope gap ou invariant conflict mid-cycle :
1. Stop execution  
2. Log `ESCALATION` dans le plan actif  
3. **Claude** ou humain tranche : replan ou gate  

Mid-cycle model switch : confirmation tracée dans le plan (`ESCALATION`).

---

## Routing Integrity

Ce fichier est versionné. **Ne pas** modifier **pendant** un cycle actif sans procédure ; après correction doctrine, enregistrer si besoin dans `docs/gates/GATE_LOG.md`.
