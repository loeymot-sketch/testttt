# Workflow multi-agents — résumé de `AGENTS.md`

Le fichier **[`AGENTS.md`](../../AGENTS.md)** à la racine est **normatif**. Ce fichier n’est qu’un raccourci.

## Rôles

| Rôle | Usage typique |
|------|----------------|
| **Claude (architecte)** | Décisions, audit, plan avec type de test, review finale |
| **Kimi (implémentation)** | Patches localisés, PHPUnit/Vitest, rapports `execution` |
| **Anti-Gravity** | E2E / QA critique **uniquement** si le plan l’exige ou review `NEEDS_ANTIGRAVITY` |
| **Bugbot** | Scan passif → `reports/review/bugbot-latest.md` (pas d’autorité) |

## Fichiers de sortie

| Étape | Chemin |
|-------|--------|
| Plan | `reports/planning/latest.md` + plans datés dans `reports/planning/*.md` |
| Exécution | `reports/execution/latest.md` |
| Review | `reports/review/latest.md` |
| E2E | `reports/antigravity/latest.md` |

## Types de tests (décision dans le plan)

- **Kimi-test** : PHPUnit, Vitest, linters.
- **Anti-Gravity** : navigateur, scénarios critiques multi-écrans.
- **No-test** : docs / commentaires uniquement.

## Règles Cursor

- `.cursor/rules/project-continuity.mdc` — lire continuité + `AGENTS.md` avant changements lourds.
- `.cursor/BUGBOT.md` — si applicable.

## Ce qu’un nouvel agent ne doit pas faire

- Modifier hors périmètre demandé.
- Faire confiance aux prix/remises envoyés par le client.
- Contourner authz, idempotence, ou transitions de statut documentées.
- Ignorer les « zones gelées » sans plan explicite (`docs/ARCHITECTURE.md`).
