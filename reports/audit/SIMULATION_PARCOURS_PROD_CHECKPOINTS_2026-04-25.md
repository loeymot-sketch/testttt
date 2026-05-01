# Simulation — parcours production FoodKing (checkpoints & fichiers lus)

**Date** : 2026-04-25  
**But** : répondre aux deux vérifications demandées :

1. **Démarrage** : est-ce que « n’importe quel agent » commence **obligatoirement** par l’orchestration **Claude** ?
2. **Parcours** : est-ce que **toutes** les étapes sont **obligatoirement** portées par des fichiers que chaque agent doit lire ?

---

## Synthèse honnête (à lire en premier)

| Question | Réponse courte | Pourquoi |
|---|---|---|
| (1) Toujours Claude pour orchestrer au début ? | **Non pas automatiquement par l’IDE** | Cursor ne force pas le modèle du chat (Auto / Composer / Claude = choix utilisateur). **En revanche** la politique du dépôt (`.cursor/routing.md`) dit : **PLAN = Claude**, auteur formel du plan ; Composer **n’a pas** le rôle d’auteur de plan ni de gate brief. Donc **en production disciplinée** : tu ouvres une conv **Claude** (ou tu fais le PLAN 100 % terminal), puis `run-cycle`. |
| (2) Toutes les étapes dans des fichiers obligatoires ? | **Oui pour la procédure** ; **partiellement pour la charge cognitive** | Les étapes vivent dans `AGENTS.md`, `run-cycle.md`, `MEMORY_MATRIX.md`, `routing.md`, règles `.cursor/rules/*.mdc` (alwaysApply). Les agents **doivent** les suivre si le modèle respecte les instructions. **Aucun hook Cursor** n’empêche un humain de taper « implémente X » sans `run-cycle` — la discipline est **repo + humain + scripts** (`verify:boucle`, `session:open`, `preflight`, `post-execute-guard`, `agent-activity-log`). |

---

## Simulation pas à pas — « Je donne une tâche sans dire Hedi »

**Entrée utilisateur** (exemple) : « Corrige le bug du panier sur la branche X ».

### Étape S0 — Ouverture (avant tout code)

| Checkpoint | Fichier / commande SSOT | Qui lit | Mécanique |
|---|---|---|---|
| P0 parcours | `AGENTS.md` §1 | Tout modèle si règles chargées | alwaysApply + discipline |
| Cycle actif | `.cursor/ACTIVE_CYCLE.md` | Orchestrateur | évite double cycle |
| Règles globales | `.cursor/rules/global.mdc` | Injecté Cursor | alwaysApply |
| Context hygiene | `.cursor/rules/context-hygiene.mdc` | Limite les fichiers par phase | alwaysApply |
| Session discipline | `docs/orchestration/SESSION_OPENING_ENFORCEMENT.md` | Humain + agent | `npm run session:open` |
| Multi-agent | `bash scripts/agent-activity-log.sh tail 50` | Obligatoire session | `cross-agent-sync.mdc` |
| Environnement boucle | `npm run verify:boucle` | 0 API | script shell |
| Graphiti (si MCP) | `search_memory_facts` | Step 0 `run-cycle.md` | MCP ou note 1 ligne |

**Point critique (1)** : à cette étape, **l’orchestrateur qui écrit le plan** doit être **Claude** selon `routing.md` (session Cursor avec modèle **Claude** sélectionné, ou option terminal `foodking-claude-orchestrate.sh context` comme amorce — **ne remplace pas** le fichier `plans/PLAN_*.md`). Si l’utilisateur laisse **Composer** ou **Auto** sur une conv qui écrit le plan, ce n’est **pas** aligné politique **auteur PLAN = Claude** ; ce n’est **pas** détecté par un script aujourd’hui.

### Étape S1 — PLAN

| Checkpoint | Fichier SSOT | Qui lit |
|---|---|---|
| Instructions plan | `.cursor/context/plan-context.md` | Orchestrateur (Claude) |
| Routage | `.cursor/routing.md` | Orchestrateur |
| Sortie | `plans/PLAN_[TASK]_[DATE].md` | Tous les agents du cycle |
| État | `.cursor/ACTIVE_CYCLE.md` (PHASE, PLAN_FILE) | Tous |

### Étape S2 — EXECUTE

| Checkpoint | Fichier / commande | Qui lit / exécute |
|---|---|---|
| Réservation | `agent-activity-log.sh start` | Exécuteur avant edit produit |
| Garde-fou | `preflight-execute.sh` | Exécuteur |
| Plan actif | `plans/[PLAN_FILE]` | Codex / sub-agent / Composer routine |
| Complex PRIMARY | `npm run codex:complex`, `agents/codex-extension-instructions.md` | Codex CLI |
| Trace | `EXECUTE_DELEGATION` dans log / rapport | Validateur |

### Étape S3 — VALIDATE

| Checkpoint | Fichier SSOT | Qui lit |
|---|---|---|
| Procédure | `run-cycle.md` Step 4 | Agent VALIDATE |
| Contexte exéc | `.cursor/context/execute-context.md` (si présent) | Validateur |
| Garde post-exec | `post-execute-guard.sh` | Script |
| Preuve | `reports/post_execute_latest.log` | Validateur |

### Étape S4 — AUDIT

| Checkpoint | Fichier SSOT | Qui lit |
|---|---|---|
| Checklist | `.cursor/context/audit-context.md` | Claude audit |
| Canal | `run-cycle.md` Step 5 — **terminal Claude PRIMARY** | Obligatoire politique |
| Verdict | `AUDIT_VERDICT: PASS \| REWORK` dans REPORT_FILE | Bloque CLOSE |

### Étape S5 — CLOSE

| Checkpoint | Fichier / action | Qui lit |
|---|---|---|
| Pas de close sans PASS | `run-cycle.md`, `AGENTS.md` | Tous |
| Libération | `agent-activity-log.sh done` | Exécuteur |
| Mémoire durable | `MEMORY_MATRIX.md`, Graphiti / JSONL post-audit | Post-close |

---

## Table « qui orchestre quoi » (clarifie Composer vs Claude)

| Rôle | Modèle politique dépôt | Où c’est écrit |
|---|---|---|
| Auteur du **plan** (PLAN) | **Claude** | `routing.md` ligne PLAN |
| **VALIDATE** (brouillon rapport, tests) | **Composer** autorisé | `routing.md` ligne VALIDATE |
| **AUDIT** (verdict PASS/REWORK) | **Claude terminal PRIMARY** | `routing.md` + `run-cycle.md` Step 5 |
| **EXECUTE** routine | Composer (sub-agent) | `routing.md` |
| **EXECUTE** complexe | Codex GPT-5.5 (CLI) | `routing.md` |

Donc : **« orchestrer tout le cycle »** n’est pas 100 % « une seule conv Claude » — la politique dit **Claude pour PLAN + audit terminal**, **Composer pour VALIDATE** (rapport), **Codex pour impl complexe**. Ce que tu veux dire par **« toujours commencer par orchestrer via Claude »** = **PLAN (et pilotage des phases) dans une conv où le modèle est Claude** + tu lances `run-cycle` / suis les steps — **pas** « le sélecteur Auto de Cursor choisira toujours Claude ».

---

## Verdict simulation (les deux points)

1. **Démarrage orchestration Claude** : **Garanti par discipline + routing**, **non garanti par le moteur Cursor** si tu laisses Composer/Auto comme auteur du premier plan. **Action humaine** : choisir **Claude** pour la conv d’orchestration, ou faire le PLAN en terminal Claude documenté.
2. **Checkpoints dans les fichiers** : **Oui** — la liste ci-dessus mappe chaque phase aux SSOT ; les règles alwaysApply couvrent tout agent dans Cursor ; les scripts couvrent collisions et garde-fous **quand** on les exécute.

---

*Document de simulation / preuve — pas un store mémoire opérationnel ; les décisions durables vont dans Graphiti / JSONL selon `MEMORY_MATRIX.md`.*

---

## Audits croisés (2026-04-25)

| Source | Fichier | Verdict |
|--------|---------|---------|
| GPT-5.5 Pro (CLI `codex`, mission `PROD-CHK-PARCOURS-2026-04-25`) | `reports/audit/GPT_SELF_AUDIT_PROD-CHK-PARCOURS_2026-04-25.md` | `GO_WITH_CAVEATS` |
| Claude terminal + complément ciblé simulation | `reports/audit/CLAUDE_AUDIT_PROD_PARCOURS_SIMULATION_2026-04-25.md` | `AUDIT_VERDICT: PASS` (sous mêmes caveats) |
| Brut Codex (JSON + logs) | `missions/PROD-CHK-PARCOURS-2026-04-25/output_codex.raw.log` | — |
