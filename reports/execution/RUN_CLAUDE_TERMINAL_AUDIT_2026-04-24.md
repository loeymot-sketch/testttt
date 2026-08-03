# RUN — Audit procédural via **Claude (terminal)** — 2026-04-24

## Métadonnées d’exécution (preuve)

| Champ | Valeur |
|-------|--------|
| `AUDIT_CHANNEL` | `claude-terminal` |
| `TERMINAL_AUDIT_OK` | `1` |
| Commande | `bash scripts/foodking-claude-orchestrate.sh context` puis `bash scripts/foodking-claude-orchestrate.sh audit "<prompt personnalisé>"` |
| Binaire | `claude` 2.1.90 (Claude Code), `~/.local/bin/claude` |
| Durée (wall clock) | ~**79 s** (78813 ms) |
| Exit code | **0** |
| Contexte disque | Généré : `reports/audit/_TERMINAL_CONTEXT_BRIEF.md` (Step `context` avant l’appel) |

> Ce rapport documente un **vrai** appel `claude -p` (abonnement Anthropic / CLI), **pas** une relecture locale seule des fichiers par l’assistant en session Cursor.

## Prompt d’audit (injecté)

```
Auditeur procédural FoodKing (rôle plan/audit, pas d implémentation produit). Ouvre et lis: AGENTS.md, .cursor/routing.md, .cursor/commands/run-cycle.md Step 0 à 5, docs/orchestration/CODEX_API_DELEGATION.md, reports/audit/_TERMINAL_CONTEXT_BRIEF.md, reports/execution/RUN_VERIFY_TERMINAL_FIRST_BILLING_2026-04-24.md. Tâche en français, puces courtes: 1) Cohérence symétrie terminal-first (EXÉCUTE PRIMARY codex-terminal, AUDIT PRIMARY claude terminal + traces AUDIT_CHANNEL et TerMINAL_AUDIT_OK). 2) Trois risques procéduraux restants. 3) Verdict: OK procédurale, CONDITIONAL ou BLOCKING. 4) Deux suivi concrets. Pas de patch code, pas frozen zones.
```

---

## Verdict (sortie Claude, intégrale)

**Note :** orthographe « TerMINAL » corrigée en tête de section — le sens reste `TERMINAL_AUDIT_OK`.

```markdown
---

## Audit procédural — Symétrie terminal-first FoodKing (2026-04-24)

---

### 1. Cohérence de la symétrie terminal-first

**Doctrine déclarée** : même principe appliqué deux fois — implementation et audit.

| Axe | PRIMARY | FALLBACK | Trace obligatoire |
|-----|---------|----------|-------------------|
| EXECUTE complexe | `codex-terminal` — `npm run codex:complex` | `foodking-complex-implementer` après ≥ 3 échecs | `EXECUTE_DELEGATION: …` + `FALLBACK_REASON:` |
| AUDIT | `claude` en terminal — `context` → `audit/audit-brief` | session Cursor (même checklist) après **1 tentative** | `AUDIT_CHANNEL: claude-terminal` + `TERMINAL_AUDIT_OK: 1` **ou** `cursor-session` + `AUDIT_FALLBACK_REASON:` |

**Cohérence constatée à travers les 5 sources :**

- `AGENTS.md` : tableau *Model Roles* + principle symétrique déclaré + stop conditions AUDIT FALLBACK → cohérent.
- `.cursor/routing.md` : ligne AUDIT PRIMARY / FALLBACK explicites avec traces nommées → cohérent.
- `run-cycle.md` Step 5 : protocole 1-2-3 (`context` → `audit-brief|audit` → trace) → cohérent.
- `CODEX_API_DELEGATION.md` §0 + diagramme : tableau symétrique + cas fallback → cohérent.
- `RUN_VERIFY_TERMINAL_FIRST_BILLING_2026-04-24.md` : smoke `VERIFY_BILLING_FULL=1` → **ALL GREEN** (claude 2.1.90 + codex proxy gpt-5.4 prouvés le 2026-04-24) → cohérent.

**Seule micro-divergence détectée :** `TERMINAL_AUDIT_OK: 1` est traité en conditionnel dans deux sources (`"si besoin"` dans `routing.md`, `"si l'appel a réellement tourné"` dans `run-cycle.md`) mais en obligatoire implicite dans `CODEX_API_DELEGATION.md` §10. Aucune gate ne bloque formellement son absence.

---

### 2. Trois risques procéduraux restants

**R1 — `TERMINAL_AUDIT_OK: 1` sans gate de validation**
- Libellé conditionnel → un audit terminal qui tourne mais retourne silencieusement une erreur peut tracer `AUDIT_CHANNEL: claude-terminal` sans `TERMINAL_AUDIT_OK: 1`.
- Aucun équivalent du blocage VALIDATE de `EXECUTE_DELEGATION` n'est prévu.
- Risque : audit terminal raté non détecté, pas de fallback déclenché, CLOSE sur un cycle sans vrai audit.

**R2 — Seuil de bascule asymétrique et non-justifié (EXECUTE ≥ 3 vs AUDIT 1 tentative)**
- EXECUTE : 3 échecs requis avant fallback → résilience transiente.
- AUDIT : 1 tentative suffit → une erreur réseau momentanée suffit à pousser vers `cursor-session` (consomme abonnement Cursor) sans preuve d'indisponibilité réelle.
- Aucun document ne justifie explicitement ce delta de seuil.

**R3 — `verify-orchestration-boucle.sh` absent du Step 0 de `run-cycle.md`**
- Le script est mentionné dans `AGENTS.md` et `CODEX_API_DELEGATION.md` comme "preuve d'environnement", mais n'est pas listé dans les 7 items du Step 0 (pre-flight).
- Conséquence : l'indisponibilité du terminal peut être découverte seulement en Step 5 AUDIT — après avoir consommé EXECUTE + VALIDATE entiers sur un cycle où le fallback cursor-session va de toute façon s'activer.

---

### 3. Verdict

**`CONDITIONAL`**

La doctrine est cohérente, vérifiée techniquement le 2026-04-24 (ALL GREEN), et propagée de façon consistante sur les 5 documents. La symétrie est réelle.

Elle ne peut pas être `OK procédurale` pour deux raisons non-triviales : `TERMINAL_AUDIT_OK` n'a pas le même statut de gate que `EXECUTE_DELEGATION`, et la détection d'indisponibilité terminal arrive trop tard dans le cycle (Step 5 au lieu de Step 0).

---

### 4. Deux suivis concrets

**Suivi 1 — Promouvoir `TERMINAL_AUDIT_OK: 1` en trace obligatoire bloquante**
- Dans `routing.md` : retirer le `(si besoin)`, aligner sur le libellé `CODEX_API_DELEGATION.md` §10.
- Dans `run-cycle.md` Step 5 : ajouter une règle explicite : si `AUDIT_CHANNEL: claude-terminal` est tracé mais `TERMINAL_AUDIT_OK: 1` est absent → traiter comme fallback non documenté → halt avec `AUDIT_FALLBACK_REASON: TERMINAL_AUDIT_OK absent`.
- Objectif : même niveau de gate que `EXECUTE_DELEGATION` pour `VALIDATE → CLOSE`.

**Suivi 2 — Insérer `verify-orchestration-boucle.sh` en item 8 du Step 0 de `run-cycle.md`**
- Ajouter après item 7 (cross-agent sync) : `npm run verify:boucle` (binaire check, zéro quota) en pré-condition de PLAN.
- Si l'un des deux terminaux est indisponible dès Step 0 → le cycle le sait avant EXECUTE, peut adapter le routage ou documenter le fallback en avance.
- Option : `VERIFY_BILLING_FULL=1` déclenchable manuellement pour les cycles critiques (POS Phase 1-3 notamment).
```

---

## Rémédiation post-audit (appliquée dans le dépôt après le run)

Pour **réduire** le `CONDITIONAL` de l’exécution d’origine, les correctifs **Suivi 1** et **Suivi 2** (recommandations de Claude) ont été **appliqués** dans le même lot que ce rapport :

| # | Action | Fichier |
|---|--------|---------|
| 1 | `TERMINAL_AUDIT_OK: 1` exigé en **même lot** que `AUDIT_CHANNEL: claude-terminal` (plus le « si besoin ») ; règle retry + fallback explicite | `.cursor/routing.md`, `run-cycle.md` Step 5 |
| 2 | **Step 0 item 8** : `npm run verify:boucle` (+ note `verify:boucle:full` / cycles critiques) | `.cursor/commands/run-cycle.md` |

**Nouveau verdict** (synthèse, post-remédiation) : la doctrine passe en **mieux couverte** côté gates (alignement sémantique `EXECUTE_DELEGATION` ↔ `TERMINAL_AUDIT_OK`). Le **R2** (dé-synchro 3 reprises vs 1 tentative AUDIT) reste une **note de conception** : documentée implicitement (résilience proxy codex plus longue qu’un appel audit) — gate humaine / ADR optionnelle si on veut harmoniser chiffre exact.

---

## Ligne à ajouter au `REPORT_FILE` / `post_execute_latest` (reproductibilité)

```
AUDIT_CHANNEL: claude-terminal
TERMINAL_AUDIT_OK: 1
CLAUDE_AUDIT_CMD: foodking-claude-orchestrate.sh audit
CLAUDE_AUDIT_WALL_MS: 78813
REPORT: reports/execution/RUN_CLAUDE_TERMINAL_AUDIT_2026-04-24.md
```

---

*Rapport généré le 2026-04-24. Audit exécuté en environnement réel (abonnement Anthropic, CLI).*
