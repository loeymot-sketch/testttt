# RUN — Passage `codex-terminal` : défaut **GPT-5.5** + **GPT-5.5-pro** (override)

- Date : 2026-04-24
- Dépôt : runner `agents/codex.runner.mjs`, `agents/codex.env.example`, `agents/codex.{smoke,stress}.mjs`, `.env.example` (bloc Codex), `dist/codex-portable/`, gouvernance `.cursor/*`, `AGENTS.md`, `docs/orchestration/*`, etc.

## Objectif

Remplacer le couple historique **gpt-5.4** / **gpt-5.4-pro** par **gpt-5.5** / **gpt-5.5-pro** comme modèles documentés et **défaut** d’exécution, après **tests réels** sur le proxy OpenAI-compatible existant (tokenclub), sans changement d’`CODEX_API_BASE` ni de clé (hors fichier local non versionné).

## Validations (proxy réel)


| Test                          | `CODEX_MODEL_COMPLEX`            | Résultat                                                                                                                                      |
| ----------------------------- | -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `node agents/codex.smoke.mjs` | `gpt-5.5` (env explicite)        | **OK** — contenu « OK »                                                                                                                       |
| `node agents/codex.smoke.mjs` | `gpt-5.5-pro` (env explicite)    | **OK**                                                                                                                                        |
| `npm run codex:smoke`         | défaut `.env.codex` = `gpt-5.5`  | **OK** — `modèle: gpt-5.5`                                                                                                                    |
| Mission lourde (prose)        | `gpt-5.5` + `CODEX_RAW_PROMPT=1` | **OK** — document Markdown long (`missions/MODEL-VALIDATE-GPT55-001/`) — ~46 s, sortie en texte (Fediverse / ActivityPub / SaaS restauration) |


Détails mission « vraie » :

- Dossier : `missions/MODEL-VALIDATE-GPT55-001/`
- `input.json` : cahier des charges 800–1200 mots, 4 sections H2, mots-clés imposés (ActivityPub, Fediverse, acteur, inbox), bloc « Risques à anticiper »
- `CODEX_RAW_PROMPT=1` (pas de template JSON strict) pour valider le **même** pipeline transport que le code généré
- Sortie : `output_codex.json` contient le **Markdown** attendu (titres, sections, ton informatif)

## Fichiers d’exécution (source de vérité technique)

- Défaut modèle : `agents/codex.runner.mjs` — `const MODEL = process.env.CODEX_MODEL_COMPLEX || "gpt-5.5"`
- Exemples env : `agents/codex.env.example`, `dist/codex-portable/.env.codex.example`, `.env.example` (lignes Codex commentées)
- Fichier local (gitignored) : `**.env.codex`** — `CODEX_MODEL_COMPLEX=gpt-5.5` (chacun recopie depuis l’exemple + sa clé)

## Documentation et règles alignées

- `AGENTS.md` — rôles **GPT-5.5** / **GPT-5.5-pro**
- `.cursor/routing.md` — toutes les occurrences **GPT-5.5** (déclenchants inclus)
- `.cursor/rules/{gpt,global,auto-remediation,project-invariants,global-operating-principles,composer}.mdc`
- `.cursor/agents/app-complex-implementer.md` — `model: gpt-5.5` + texte proxy
- `docs/orchestration/CODEX_API_DELEGATION.md`, `GLOBAL_SYSTEM_PRIMER.md`, `EXPORT_CONFIG_*.md`
- `docs/ops/CURSOR_MODEL_ROUTING_POLICY.md`
- `tasks/TASK_TEMPLATE.md` — case à cocher GPT-5.5
- Rapport historique annoté : `CODEX_REAL_COMPLEX_TEST_2026-04-23.md` (note *upgrade* + lien ici)

## Rappel d’exploitation

```bash
# Santé
npm run codex:smoke

# Qualité (override)
CODEX_MODEL_COMPLEX=gpt-5.5-pro npm run codex:smoke
```

**Verdict** : **Défaut gpt-5.5** et **override gpt-5.5-pro** **validés** sur le proxy en usage (smoke + document long). Système cohérent : même commandes, uniquement noms de modèles et docs mis à jour.

EXECUTE_DELEGATION: n/a (run meta)
MODEL_DEFAULT: gpt-5.5
MODEL_PRO_VALIDATED: gpt-5.5-pro (smoke)