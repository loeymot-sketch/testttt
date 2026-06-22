# PLAN — Codex Terminal as PRIMARY Complex Implementer + Audit-Loop Integration

- TASK_ID : `CODEX-TERMINAL-PRIMARY-2026-04-23`
- Date : 2026-04-23
- Author : agent (Cursor session — Plan/Orchestrator role per AGENTS.md)
- Status : PLAN → EXECUTE in this same cycle

---

## 1. Goal

Faire du **proxy API Codex en terminal** (la chaîne `npm run codex:complex` validée à 4/4 sur des tâches complexes réelles — voir `reports/execution/CODEX_REAL_COMPLEX_TEST_2026-04-23.md`) le **chemin primaire** pour toute implémentation complexe FoodKing. Le sub-agent Cursor `foodking-complex-implementer` (qui consomme un slot du modèle premium de l'abonnement Cursor) devient **uniquement un repli** quand le proxy API n'est pas disponible.

La boucle complète doit s'exécuter sans intervention humaine entre les phases :

```
Cursor (Orchestrator/Planner)
   │ Graphiti facts + plan + execute brief
   ▼
codex-terminal (EXECUTE complex, GPT-5.4 / 5.4-pro via API)
   │ output_codex.json
   ▼
Cursor session : applique le diff, run vitest/phpunit (VALIDATE)
   │ reports/post_execute_latest.log
   ▼
claude terminal (AUDIT, abonnement Anthropic)  ←  scripts/foodking-claude-orchestrate.sh
   │ verdict + memory updates
   ▼
CLOSE | REMEDIATE (boucle EXECUTE→VALIDATE→AUDIT) | GATE
```

## 2. Naming (officiel, à propager)

| Couche | Nom officiel | Slug technique |
|---|---|---|
| Système | **FoodKing API Complex Implementer** | `codex-terminal` |
| Commande projet | `npm run codex:complex -- <TASK_ID>` | — |
| Trace dans rapports | `EXECUTE_DELEGATION: codex-terminal` | — |
| Modèle par défaut | gpt-5.4 (override : `CODEX_MODEL_COMPLEX=gpt-5.4-pro`) | — |
| Cursor sub-agent (FALLBACK seulement) | `foodking-complex-implementer` | sub-agent Task |

## 3. Scope

### IN
- Mise à jour gouvernance pour faire de `codex-terminal` le primaire :
  - `AGENTS.md` (Model Roles + EXECUTE delegation + Stop Conditions)
  - `.cursor/routing.md` (déjà fait — vérification)
  - `.cursor/commands/run-cycle.md` (déjà fait — vérification + boucle audit Claude terminal)
  - `.cursor/agents/app-complex-implementer.md` (déjà déclaré FALLBACK — vérification)
  - `.cursor/rules/{global,gpt,composer,project-invariants,project-continuity,auto-remediation}.mdc` — ajouter référence `codex-terminal` et règle "primary, sub-agent fallback only"
  - `.cursor/rules/global-operating-principles.md`
  - `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`
  - `docs/orchestration/CODEX_API_DELEGATION.md` (refresh — naming + fallback contract + audit handoff)
- ~~Kit portable `dist/codex-portable/`~~ — retiré du dépôt (2026) ; seul le **CLI** `codex` est supporté.
- Test boucle E2E sur une tâche réelle légère (création d'un helper testable + vitest)
- Rapport `reports/execution/RUN_CODEX_TERMINAL_PRIMARY_LOOP_2026-04-23.md`

### OUT
- Modification du runner / prompt (déjà validés à 4/4)
- Frozen zones, schéma BD, migrations
- Rotation de la clé API
- Changement du proxy

## 4. Subsystems touched

`docs/`, `.cursor/agents/`, `.cursor/rules/`, `.cursor/commands/`, `AGENTS.md`, `plans/`, `reports/execution/`, `missions/T-LOOP-*` (test).

## 5. Hard constraints

- Pas de modification de produit hors scope du test boucle.
- Aucun secret ne quitte `.env.codex` — le kit portable contient `.env.codex.example` seulement.
- Le sub-agent `foodking-complex-implementer` reste fonctionnel (texte non supprimé, juste recadré comme fallback).
- Le rapport boucle doit montrer chaque étape et le `EXECUTE_DELEGATION` correctement tracé.

## 6. Phases

### P1 — Naming + governance refresh
- AGENTS.md : ligne "Model Roles" → ajouter `codex-terminal (primary)` ; section "EXECUTE delegation" → renforcer "préférer toujours codex-terminal" ; "Stop Conditions" → ajouter "codex-terminal indisponible 3 reprises consécutives → bascule fallback documentée".
- routing.md : ligne EXECUTE — complex est déjà bonne, vérifier wording.
- run-cycle.md Step 2 (déjà bon), Step 5 — préciser que l'AUDIT peut être fait soit en session Cursor soit via `bash scripts/foodking-claude-orchestrate.sh audit-brief`.
- app-complex-implementer.md (déjà bon, vérifier).
- rules/*.mdc : insérer une règle "delegation policy" qui pointe vers `codex-terminal` primaire.

### P2 — Documentation
- `docs/orchestration/CODEX_API_DELEGATION.md` : ajouter les sections naming, fallback contract, audit handoff (`claude` terminal), token/Graphiti loop, schéma boucle.
- `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` : référencer le naming officiel et la boucle.

### P3 — Test boucle E2E (preuve)
1. Mission : `T-LOOP-HELPER-001` — créer `resources/js/helpers/posCentsArith.js` (helpers `addCents/subCents/sumCents`) + `tests/js/posCentsArith.spec.js` (vitest).
2. Préparer `missions/T-LOOP-HELPER-001/` avec `input.json` + `graphiti_context.md` + `plan_excerpt.md`.
3. Lancer `npm run codex:complex -- T-LOOP-HELPER-001` (gpt-5.4).
4. Appliquer manuellement `output_codex.json` (créer les 2 fichiers).
5. Lancer `npx vitest run tests/js/posCentsArith.spec.js`.
6. Logger `EXECUTE_DELEGATION: codex-terminal` dans `reports/post_execute_latest.log`.
7. Lancer audit terminal Claude : `bash scripts/foodking-claude-orchestrate.sh context && bash scripts/foodking-claude-orchestrate.sh audit-brief` (si abonnement actif) — sinon mode dégradé : audit textuel manuel + lecture du brief généré.
8. Conclure rapport.

### P4 — Acceptance & report
Voir critères d'acceptance ci-dessous, écrire `reports/execution/RUN_CODEX_TERMINAL_PRIMARY_LOOP_2026-04-23.md`.

## 7. Acceptance criteria (binaires)

- [ ] Tous les fichiers de gouvernance citent `codex-terminal` comme primaire (et `foodking-complex-implementer` comme fallback).
- [ ] La boucle E2E sur `T-LOOP-HELPER-001` se termine avec : sortie API non vide, fichiers créés, vitest vert, ligne `EXECUTE_DELEGATION: codex-terminal` dans le log, brief audit produit côté Claude terminal (ou note "claude indispo" si non installé localement, sans bloquer la démonstration).
- [ ] Aucune mention "GPT-5.4 = sub-agent Cursor" sans un "via codex-terminal d'abord, sub-agent fallback".
- [x] (Obsolète) Le kit `dist/codex-portable/` a été supprimé — vérification remplacée par `npm run codex:smoke` + `npm run codex:complex` sur une mission témoin.

## 8. Risks

- **R1 — Proxy 504 sur prompts longs** : déjà mitigé (streaming par défaut, retry sur 504).
- **R2 — `claude` non installé localement** : ne bloque pas, on documente la commande exacte et on bascule en audit Cursor session pour ce test.
- **R3 — Sub-agent Cursor encore appelé par habitude** : mitigé par règles + AGENTS.md + run-cycle Step 2 explicite.
- **R4 — Confusion entre `codex-terminal` et `codex CLI OpenAI`** (deux outils différents qui s'appellent codex) : nommer **explicitement** "FoodKing API Complex Implementer (codex-terminal, via proxy /v1)" partout, pour distinguer du `codex` ChatGPT-Plus déjà mentionné dans AGENTS.md §B.

## 9. Token / context discipline

- Le runner fusionne uniquement `graphiti_context.md`, `plan_excerpt.md`, `execute_brief.md`, `cycle_snapshot.md` (4 fichiers max) — pas de re-lecture de `AGENTS.md` ni des `.mdc` côté API.
- Côté orchestrateur Cursor : `search_memory_facts(group_ids=["foodking"])` une fois en Step 0, fold facts → `graphiti_context.md`, ne pas tout recopier.
- Côté Claude audit terminal : utilise `_TERMINAL_CONTEXT_BRIEF.md` (déjà géré par `foodking-claude-orchestrate.sh context`).

## 10. Rollback

Si un fichier de gouvernance casse une boucle existante :
```
git diff plans/PLAN_CODEX_TERMINAL_PRIMARY_INTEGRATION_2026-04-23.md
git checkout HEAD -- AGENTS.md .cursor/rules/ .cursor/agents/ .cursor/commands/run-cycle.md docs/orchestration/
```
(Le dépôt ne fournit plus de *kit portable* HTTP : uniquement `npm run codex:complex`.)

## 11. Files I will touch in EXECUTE

```
AGENTS.md                                                 (edit)
.cursor/rules/global.mdc                                  (edit)
.cursor/rules/gpt.mdc                                     (edit)
.cursor/rules/composer.mdc                                (edit)
.cursor/rules/project-invariants.mdc                      (edit)
.cursor/rules/project-continuity.mdc                      (edit)
.cursor/rules/auto-remediation.mdc                        (edit)
.cursor/rules/global-operating-principles.md              (edit)
.cursor/agents/app-complex-implementer.md                 (verify, light edit)
.cursor/commands/run-cycle.md                             (verify Step 5 audit terminal note)
docs/orchestration/CODEX_API_DELEGATION.md                (refresh: naming + fallback + audit + loop)
docs/orchestration/GLOBAL_SYSTEM_PRIMER.md                (edit)
missions/T-LOOP-HELPER-001/{input,graphiti_context,plan_excerpt}.md / .json   (create)
resources/js/helpers/posCentsArith.js                     (create — produced by codex-terminal)
tests/js/posCentsArith.spec.js                            (create — produced by codex-terminal)
reports/post_execute_latest.log                           (append)
reports/execution/RUN_CODEX_TERMINAL_PRIMARY_LOOP_2026-04-23.md   (create)
```

EXECUTE_DELEGATION: codex-terminal (planned for the loop test)
