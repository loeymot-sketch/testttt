# RUN — Vérification technique `terminal-first` (abonnement / clés propres) — 2026-04-24

## Objectif

Prouver que le **même principe** s’applique aux **deux** chemins d’orchestration « payants côté intention » :

- **EXÉCUTE complexe (codage intelligence)** : **PRIMARY = terminal** — `npm run codex:smoke` / `npm run codex:complex` (clé `CODEX_*` + proxy). **FALLBACK =** sub-agent `foodking-complex-implementer` (compte côté Cursor) avec `FALLBACK_REASON:`.

- **AUDIT (après chaque alimentation / implémentation)** : **PRIMARY = terminal** — `bash scripts/foodking-claude-orchestrate.sh` (`context` → `smoketest` en test, en prod `audit` / `audit-brief` — abonnement **Anthropic** via `claude` CLI). **FALLBACK =** audit **dans la session Cursor** avec `AUDIT_CHANNEL: cursor-session` + **`AUDIT_FALLBACK_REASON:`** obligatoire.

## Commandes de preuve (technique)

| Commande | Rôle | Consomme API / quota ? |
|----------|------|-------------------------|
| `npm run verify:boucle` | Vérif. **binaire `claude` sur PATH** + cohérence des docs (grep) | **Non** |
| `npm run verify:boucle:full` (=`VERIFY_BILLING_FULL=1`) | 1× `claude` smoketest + 1× `npm run codex:smoke` | **Oui** (1 appel chacun, minimal) |

## Résultat 2026-04-24 (cette session)

**Exécuté :** `VERIFY_BILLING_FULL=1 bash scripts/verify-orchestration-boucle.sh`

| Vérification | Résultat |
|--------------|----------|
| Binaire `claude` (Claude Code) | **OK** — 2.1.90, `~/.local/bin/claude` |
| Smoketest `claude -p` `TERMINAL_OK` | **OK** (abonnement / auth API) |
| `npm run codex:smoke` (proxy) | **OK** — `gpt-5.4`, extrait `"OK"` |
| Gouvernance (grep) | **OK** — `run-cycle.md` contient `AUDIT_CHANNEL: claude-terminal` ; `CODEX_API_DELEGATION.md` contient `terminal` / section 0 |
| **Verdict global** | **ALL GREEN** (prêt **procédural** côté canaux API — les deux extremités prouvées) |

**Trace reproductible (extrait) :**
```
[foodking-claude-orchestrate] OK: abonnement / auth API — réponse contient TERMINAL_OK
TERMINAL_OK
[codex:smoke] OK | modèle: gpt-5.4 | extrait: "OK"
ALL GREEN: prêt production procédurale (extremities OK).
```

## Décision durable (Graphiti miroir)

Ligne JSONL : `memory/episodes/12_decisions_log.jsonl` — *« symétrie terminal-first — EXÉCUTE + AUDIT + fallback explicite »* (ingest ciblé si Neo4j branché ; manifest régénéré).

## SSOT législatif (lecture 5 min)

- `AGENTS.md` — tableau **Model Roles** + principe symétrique + `AUDIT_FALLBACK_REASON`
- `.cursor/routing.md` — table AUDIT PRIMARY / FALLBACK
- `.cursor/commands/run-cycle.md` — **Step 5** (ordre obligatoire d’exécution d’intention)
- `docs/orchestration/CODEX_API_DELEGATION.md` — **§0** + diagramme mis à jour
- `.cursor/rules/global.mdc` — **Channel economics**
- `scripts/verify-orchestration-boucle.sh` — script de vérification

**Statut** : gouvernance **alignée** sur ta demande — **Claude = audit en terminal d’abord (abonnement) ; GPT = impl en terminal d’abord (API) ; sub-agents Cursor = repli documenté** si l’un ou l’autre des terminaux ne répond pas.
