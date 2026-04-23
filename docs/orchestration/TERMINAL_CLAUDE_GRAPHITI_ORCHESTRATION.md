# Terminal Claude Code + Graphiti : rôles, limites, économie de tokens

> **SSOT** : `AGENTS.md` (moteur Cursor, terminal allié, MCP Graphiti). Ce document fixe le **modèle mental** : qui orchestre, qui audite, où est la mémoire.

## Les trois « modes » (pas interchangeables)

| Mode | Rôle | Graphiti / mémoire | Coût tokens |
|------|------|--------------------|------------|
| **Claude (modèle) dans le chat Cursor** | Orchestrateur par défaut (plan, routage, audit) | MCP `search_memory_facts` / `add_memory` si le serveur est enregistré | Selon modèle + contexte |
| **Sub-agent Task** (`foodking-routine-implementer`, `foodking-complex-implementer`, `explore`, …) | Exécution cadrée, parallélisable | **N’hérite pas** automatiquement des MCP du parent : `PRIOR_CONTEXT` + plan obligatoires | Souvent bas si scope serré |
| **`claude` dans le terminal** (Claude Code) | Second passage : audit, gros recoupements, idées hors session IDE | **Pas** d’MCP Graphiti par défaut — lire le **fichier** généré (voir ci-dessous) + JSONL | Contrôlable : prompts courts + `context` |

**Impact « sub-agent vs pas sub-agent »** : ce n’est pas un risque de « mal configurer » si la règle est respectée — **délégation explicite** + **sentinelle** `EXECUTE_DELEGATION:` sur les `RUN_*.md`. Un sub-agent sans `PRIOR_CONTEXT` = risque réel de dérive. Un terminal `claude` **sans** alimentation (ACTIVE_CYCLE, décisions) = plus de tokens et moins de fidélité au système.

## Alimentation « intelligente » pour le terminal (sans gaspiller)

1. (Après toute **édition** `memory/episodes/*.jsonl`) exécuter d’abord le pipeline disque (manifeste SHA, même contrat que CI) :
   ```bash
   bash scripts/after-execute-memory.sh
   ```
   Puis, si le poste a la stack, les `bin/graphiti-ingest.sh` indiqués en fin de sortie (et en option `python3 memory/verify.py`).

2. Générer le **bref** pour le terminal (cycle + dernières décisions + `memory/INDEX.md` + rappel post-implémentation) :
   ```bash
   bash scripts/foodking-claude-orchestrate.sh context
   ```
   Produit : `reports/audit/_TERMINAL_CONTEXT_BRIEF.md` (écrasé à chaque run).

3. (Option, **abonnement Anthropic ciblé**) lancer un audit `claude -p` **qui lit ce bref d’abord** :
   ```bash
   bash scripts/foodking-claude-orchestrate.sh audit-brief
   ```

4. (Raccourci) **post-execute** = étape 1 + étape 2, **sans** lancer l’étape 3 (pour ne pas consommer deux passes `context` ni imposer l’audit) :
   ```bash
   bash scripts/foodking-claude-orchestrate.sh post-execute
   ```
   (Ne requiert **pas** le binaire `claude` : mémoire + fichier bref seuls ; utile sur une machine sans Claude Code installé.)

5. Vérifier l’abonnement / l’auth (1 appel minuscule) :
   ```bash
   bash scripts/foodking-claude-orchestrate.sh check
   bash scripts/foodking-claude-orchestrate.sh smoketest
   ```

**Graphiti (Neo4j)** : le terminal ne parle pas à Neo4j tout seul. Le workflow durable reste : **ligne JSONL** + manifeste + `bin/graphiti-ingest.sh <filtre>`. Le fichier `_TERMINAL_CONTEXT_BRIEF.md` sert de **pont** : contexte riche, faible bruit, pas besoin de recoller l’historique de chat.

## Quand utiliser quoi (règle pratique)

- **Tous les jours, cycle produit** : chat Cursor + `run-cycle` + sub-agents.
- **Audit lourd / relecture cross-fichier** (hors heures de session) : `claude` terminal + `audit` ou `audit-brief`.
- **Après `add_memory` / décision** : `after-execute-memory` (ou `post-execute`) puis, si utile, `audit-brief` (optionnel, consomme des crédits Anthropic).

## Vérification que « Claude est là » en terminal

```bash
bash scripts/foodking-claude-orchestrate.sh check
bash scripts/foodking-claude-orchestrate.sh smoketest
```

Sortie attendue : version Claude Code, chemin `claude`, puis `TERMINAL_OK` sur le smoketest.
