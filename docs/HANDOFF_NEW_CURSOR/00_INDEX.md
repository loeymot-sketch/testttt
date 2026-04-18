# Passation nouvelle session Cursor — index

**Objectif** : permettre à une nouvelle conversation (nouvel abonnement, autre machine, autre collaborateur) de reprendre le développement **sans mémoire** du chat précédent.

## Démarrage nouveau compte Cursor (ultra-rapide)

| Fichier | Usage |
|---------|--------|
| [`PROMPT_DEMARRAGE_NOUVEAU_COMPTE.md`](./PROMPT_DEMARRAGE_NOUVEAU_COMPTE.md) | **Texte à coller** dans le 1er message du chat |
| [`CACHE_MEMOIRE_TRANSFERT.md`](./CACHE_MEMOIRE_TRANSFERT.md) | **Mémoire projet** dense (à lire par l’agent) |
| [`ORCHESTRATION_FICHIERS_A_TRANSFERER.md`](./ORCHESTRATION_FICHIERS_A_TRANSFERER.md) | Checklist règles, skill, autre compte |
| [`.cursor/skills/project-handoff/SKILL.md`](../../.cursor/skills/project-handoff/SKILL.md) | Skill Cursor « project-handoff » (invocation : *Session handoff*) |

## Ordre de lecture recommandé (45–90 min la première fois)

| # | Fichier | Contenu |
|---|---------|---------|
| 0 | Ce fichier (`00_INDEX.md`) | Navigation |
| 1 | [`01_DEMARRAGE_5_MINUTES.md`](./01_DEMARRAGE_5_MINUTES.md) | Checklist immédiate + liens |
| 2 | [`../../PROJECT_CONTINUITY_AND_VISION.md`](../PROJECT_CONTINUITY_AND_VISION.md) | Vision produit, état, backlog (source de vérité) |
| 3 | [`02_ARCHITECTURE_MONOLITHE.md`](./02_ARCHITECTURE_MONOLITHE.md) | Couches, modules, connectivité |
| 4 | [`03_SYNCHRONISATION_TEMPS_REEL.md`](./03_SYNCHRONISATION_TEMPS_REEL.md) | Echo, événements, FCM, polling |
| 5 | [`04_FICHIERS_PIVOTS_PAR_FLUX.md`](./04_FICHIERS_PIVOTS_PAR_FLUX.md) | Où modifier quoi (kiosk, POS, KDS) |
| 6 | [`05_TESTS_ET_SCRIPTS.md`](./05_TESTS_ET_SCRIPTS.md) | PHPUnit par lots, Vitest, CI |
| 7 | [`06_MULTI_AGENT_AGENTS_MD.md`](./06_MULTI_AGENT_AGENTS_MD.md) | Workflow Claude / Kimi / Anti-Gravity |
| 8 | [`07_RAPPORTS_PLANS_AUDITS.md`](./07_RAPPORTS_PLANS_AUDITS.md) | Plans et audits récents sous `reports/` |
| 9 | [`08_BACKLOG_SYNTHESE.md`](./08_BACKLOG_SYNTHESE.md) | Backlog P0–P3 condensé |

## Règles Cursor du dépôt (obligatoires)

- **`.cursor/rules/project-continuity.mdc`** : lire la vision avant changements importants.
- **`AGENTS.md`** (racine) : boucle planning → exécution → review, types de tests.

## Fichiers racine à connaître

| Fichier | Rôle |
|---------|------|
| `README.md` | Hub documentation + installation |
| `AGENTS.md` | Workflow multi-agents |
| `docs/API_MAP.md` | Contrats API |
| `docs/AUTHZ_MATRIX.md` | Autorisations |
| `docs/BUSINESS_RULES.md` | Règles métier |
| `docs/ERROR_HANDLING.md` | Erreurs API |

---

*Compléter la lecture par `docs/ARCHITECTURE.md`, `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md` (en notant que certaines phrases peuvent être en retard sur le code — voir `03_SYNCHRONISATION_TEMPS_REEL.md`).*
