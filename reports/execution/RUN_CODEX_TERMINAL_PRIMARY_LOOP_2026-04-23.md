# RUN — Codex Terminal as PRIMARY + End-to-End Loop validation

- TASK_ID : `CODEX-TERMINAL-PRIMARY-2026-04-23`
- Plan : `plans/PLAN_CODEX_TERMINAL_PRIMARY_INTEGRATION_2026-04-23.md`
- Date : 2026-04-23
- Verdict : **CLOSED ✅** — Codex API Complex Implementer (`codex-terminal`) est intégré comme **PRIMARY** dans toute la gouvernance ; boucle complète Cursor → codex-terminal → vitest → Claude terminal audit testée et validée.

---

## 1. Ce qui change concrètement

| Avant | Après |
|---|---|
| EXECUTE complexe → sub-agent Cursor `foodking-complex-implementer` (slot premium Cursor consommé) | EXECUTE complexe → **`codex-terminal`** (PRIMARY) via `npm run codex:complex` ; sub-agent garde le rôle de **fallback** documenté |
| Pas de nom officiel pour le canal API | Nom officiel : **FoodKing API Complex Implementer** ; slug technique partout : `codex-terminal` |
| Trace dans rapports : `EXECUTE_DELEGATION: foodking-complex-implementer` | Trace : `EXECUTE_DELEGATION: codex-terminal` (ou `foodking-complex-implementer (codex-terminal-fallback)` + `FALLBACK_REASON:`) |
| Audit toujours en session Cursor | Audit possible aussi via terminal `claude` (Anthropic CLI) — `AUDIT_CHANNEL: cursor-session \| claude-terminal` |

---

## 2. Fichiers de gouvernance modifiés

| Fichier | Changement |
|---|---|
| `AGENTS.md` | Model Roles ré-écrit (codex-terminal PRIMARY, sub-agent FALLBACK) ; EXECUTE delegation détaillé en 4 étapes ; Stop Conditions enrichies du cas "codex-terminal indisponible" |
| `.cursor/routing.md` | Table EXECUTE — séparation PRIMARY/FALLBACK explicite |
| `.cursor/commands/run-cycle.md` | Step 5 AUDIT — note sur double canal `cursor-session` vs `claude-terminal` |
| `.cursor/agents/app-complex-implementer.md` | (déjà à jour — déclaré comme fallback) |
| `.cursor/rules/global.mdc` | Section "Complex EXECUTE Delegation (PRIMARY = codex-terminal)" ajoutée |
| `.cursor/rules/gpt.mdc` | Section "Delivery Channel (PRIMARY vs FALLBACK)" ajoutée |
| `.cursor/rules/auto-remediation.mdc` | Routage remediation : codex-terminal en premier, sub-agent en fallback |
| `.cursor/rules/project-invariants.mdc` | Mention codex-terminal comme canal d'exécution principal |
| `.cursor/rules/project-continuity.mdc` | Pointeur EXECUTE complexe → codex-terminal |
| `.cursor/rules/global-operating-principles.md` | Model roles + bounded-cycle delegation ré-écrits |
| `docs/orchestration/CODEX_API_DELEGATION.md` | **Refonte complète** : naming, schéma boucle, fallback contract, choix modèle, token discipline, trace dans rapports |
| `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` | Tableau sub-agents/canaux remis à jour |

## 3. Kit portable livré

`dist/codex-portable/` :

```
dist/codex-portable/
├── README.md                  (utilisation, fiabilité, install dans un autre projet)
├── codex.runner.mjs           (le runner Node — 0 dep, retry, stream, normalisation m, fusion contexte)
├── codex.prompt.txt           (le system prompt = identité du complex implementer + invariants + format JSON strict)
├── codex.smoke.mjs            (health check du proxy)
└── .env.codex.example         (CODEX_API_BASE / CODEX_API_KEY / CODEX_MODEL_COMPLEX / RETRY_MAX, etc.)
```

À copier dans `agents/` du projet cible + ajouter scripts `codex:complex` / `codex:smoke` dans `package.json`.

---

## 4. Test boucle E2E — `T-LOOP-HELPER-001`

### Setup orchestrateur (Cursor session)
- Mission : `missions/T-LOOP-HELPER-001/` (`input.json` + `graphiti_context.md` + `plan_excerpt.md` + `execute_brief.md`)
- Modèle : `gpt-5.4` (défaut — stable sur le proxy)
- Cible : créer `resources/js/helpers/posCentsArith.js` + `tests/js/posCentsArith.spec.js` (12 tests vitest)

### Déroulé chronologique

| Étape | Canal | Résultat |
|---|---|---|
| Round 0 — `npm run codex:complex -- T-LOOP-HELPER-001` | codex-terminal (gpt-5.4) | sortie JSON stricte conforme, `delegation: "codex-terminal"` ✅ |
| Apply 2 fichiers + vitest | local | **11/12** — 1 fail : `toCents(0.30000004)` (EPSILON trop strict) |
| AUDIT KO normal → REMEDIATION R1 | codex-terminal (gpt-5.4) | code livré mais **scope drift** : addCents/subCents/sumCents réécrits avec mauvaise signature ❌ |
| AUDIT KO normal → REMEDIATION R2 | codex-terminal (gpt-5.4) | drift partiellement corrigé mais **2 messages d'erreur** divergent du contrat ❌ (10/12) |
| AUDIT KO normal → REMEDIATION R3 (brief beaucoup plus serré, messages exacts spécifiés) | codex-terminal (gpt-5.4) | sortie clean, fichier replace appliqué |
| vitest | local | **12/12 PASSED** en 391 ms ✅ |
| Trace dans `reports/post_execute_latest.log` | local | `EXECUTE_DELEGATION: codex-terminal` + `EXECUTE_MODEL: gpt-5.4` + `ROUNDS: 3` ajoutés |
| AUDIT phase | claude-terminal (Anthropic) | `bash scripts/foodking-claude-orchestrate.sh audit "..."` — verdict **AUDIT_VERDICT: CLOSED** |

### Verdict de l'auditeur Claude (terminal)

> 1. Invariants FoodKing — CONFORME (pricing SSOT, pas d'OrderStatus string, pas de branch_id, pas de dispatch).
> 2. Scope drift mineur : `toCents` ajouté en plus des 3 helpers initialement listés — acceptable, signalé.
> 3. Tests vitest 12/12 PASSED — couverture suffisante pour le périmètre.
> 4. Trace `EXECUTE_DELEGATION: codex-terminal` conforme au contrat `CODEX_API_DELEGATION.md` §10.
> 5. **AUDIT_VERDICT: CLOSED.**

---

## 5. Preuve d'évidence

```
$ ls dist/codex-portable
README.md  .env.codex.example  codex.prompt.txt  codex.runner.mjs  codex.smoke.mjs

$ tail -20 reports/post_execute_latest.log
TASK_ID: T-LOOP-HELPER-001
EXECUTE_DELEGATION: codex-terminal
EXECUTE_MODEL: gpt-5.4
ROUNDS: 3 (initial + R1 contract-drift + R2 message-drift + R3 PASSED)
TESTS: vitest tests/js/posCentsArith.spec.js → 12/12 PASSED (391ms)
INVARIANTS_CONSIDERED: pricing_ssot
AUDIT_CHANNEL: claude-terminal (verified, exit 0)
AUDIT_VERDICT: CLOSED

$ ls missions/T-LOOP-HELPER-001*/
missions/T-LOOP-HELPER-001/    {input,graphiti_context,plan_excerpt,execute_brief}.* + output_codex.json
missions/T-LOOP-HELPER-001-R1/ {input.json, graphiti_context.md, output_codex.json}
missions/T-LOOP-HELPER-001-R2/ {input.json, output_codex.json}
missions/T-LOOP-HELPER-001-R3/ {input.json, output_codex.json}
```

---

## 6. Boucle validée — schéma final

```
Cursor (Plan + Orchestrate)
  │ Graphiti search_memory_facts(group=foodking) → graphiti_context.md
  │ plan_excerpt.md + execute_brief.md
  ▼
codex-terminal (PRIMARY) ── gpt-5.4 ──► output_codex.json   (3 rounds visibles dans missions/)
  │
  ▼
Apply (orchestrator session) → vitest 12/12 ✅
  │
  ▼
reports/post_execute_latest.log : EXECUTE_DELEGATION: codex-terminal
  │
  ▼
claude-terminal AUDIT (`scripts/foodking-claude-orchestrate.sh audit "..."`)
  │
  ▼
AUDIT_VERDICT: CLOSED  →  CLOSE
```

---

## 7. Ce que cela prouve (réponses aux exigences utilisateur)

| Exigence (user query) | Réponse |
|---|---|
| "Le système de nouveau Sub-agent fonctionne via gpt-5.4 et gpt-5.4-pro" | OUI — déjà prouvé sur 2 missions complexes (`reports/execution/CODEX_REAL_COMPLEX_TEST_2026-04-23.md`), reconfirmé ici (3 rounds gpt-5.4 sur la boucle) |
| "Donne-moi les 2 fichiers pour utiliser ailleurs" | `dist/codex-portable/codex.runner.mjs` + `dist/codex-portable/codex.prompt.txt` (+ README + smoke + env example pour reuse zéro friction) |
| "Inclure le système comme primaire dans la liste des sub-agents" | Fait : `AGENTS.md`, `.cursor/routing.md`, `.cursor/rules/global.mdc`, `.cursor/rules/gpt.mdc`, `.cursor/rules/auto-remediation.mdc`, `global-operating-principles.md`, `GLOBAL_SYSTEM_PRIMER.md`, `CODEX_API_DELEGATION.md` |
| "Bien nommer le système" | **FoodKing API Complex Implementer** (slug technique : `codex-terminal`) — déclaré dans plan §2 et propagé partout |
| "Remplacer le sub-agent .cursor/agents/app-complex-implementer.md, ce dernier en alternatif" | Le sub-agent reste vivant comme **fallback documenté** (`.cursor/agents/app-complex-implementer.md` line 9 + AGENTS.md + routing + rules) — basculement automatique tracé `EXECUTE_DELEGATION: foodking-complex-implementer (codex-terminal-fallback)` + `FALLBACK_REASON:` |
| "Audit Claude terminal après alimentation, en boucle" | OUI — `bash scripts/foodking-claude-orchestrate.sh audit "..."` testé et confirmé `TERMINAL_OK` puis `AUDIT_VERDICT: CLOSED` sur la mission réelle |
| "Boucle utilise contexte / cache / Graphiti" | Contexte : 4 fichiers fusionnés par le runner (`graphiti_context.md`, `plan_excerpt.md`, `execute_brief.md`, `cycle_snapshot.md`). Token discipline documentée dans `CODEX_API_DELEGATION.md` §6. Cache : aucun re-read de `.mdc`/AGENTS.md côté API ; côté Cursor, règle `context-hygiene.mdc` toujours en vigueur. Graphiti : `search_memory_facts` au Step 0, fold dans `graphiti_context.md` |
| "Master plan + tâches + implémentation + test intelligent" | `plans/PLAN_CODEX_TERMINAL_PRIMARY_INTEGRATION_2026-04-23.md` (master plan), 9 todos exécutés (kit, naming, agents_md, primer, delegation_doc, rules, loop_test, report), test E2E réel avec 3 rounds de remediation |

---

## 8. Limites identifiées (non-bloquantes)

- **Drift de scope possible côté API** : observé sur R1 (signatures changées). Mitigation : briefs très précis quand le contrat doit être préservé byte-for-byte. À envisager : un invariant `max_silent_scope_extension: 0` dans `agents/codex.prompt.txt`.
- **`gpt-5.4-pro` plus stable mais plus lent** : ~22-28 s vs ~17-20 s pour `gpt-5.4`. Override ponctuel via `CODEX_MODEL_COMPLEX=gpt-5.4-pro npm run codex:complex -- ...`.
- **Proxy 504 sur prompts longs en non-stream** : déjà mitigé (streaming par défaut + retry sur 504/HTML).

## 9. Prochaines actions recommandées (hors scope de ce cycle)

1. Ajouter dans `agents/codex.prompt.txt` une règle "do not silently extend scope; if you would touch a function not listed in `objective`, return empty `code_blocks` + `risks: ["SCOPE_EXTENSION_DETECTED"]`".
2. Ajouter Graphiti `add_memory` après chaque CLOSED via `codex-terminal` pour garder la mémoire alignée.
3. (Optionnel) Renommer `agents/codex.runner.mjs` → `agents/codex-terminal.runner.mjs` pour éviter toute confusion avec le CLI ChatGPT-Plus `codex` mentionné dans `AGENTS.md` §B.

---

EXECUTE_DELEGATION: codex-terminal (loop test)
EXECUTE_MODEL: gpt-5.4
AUDIT_CHANNEL: claude-terminal
AUDIT_VERDICT: CLOSED
