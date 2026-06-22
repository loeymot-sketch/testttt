# RUN — Défaut `gpt-5.5-high` + `CODEX_REASONING_EFFORT` (2026-04-23)

## Objectif

Aligner le runner `**agents/codex.runner.mjs**` sur la **qualité maximale** côté proxy (identifiant validé : `**gpt-5.5-high`**) et exposer en option le champ API `**reasoning: { effort }**` via `**CODEX_REASONING_EFFORT**`.

## Changements clés

- **Défaut** : `CODEX_MODEL_COMPLEX` non défini → `**gpt-5.5-high`** (au lieu de `gpt-5.5`).
- **Option** : `CODEX_REASONING_EFFORT=low|medium|high|xhigh|minimal|none` : fusion dans le body `/chat/completions` (`xhigh` → `high` pour l’API).
- Fichiers synchronisés : `**dist/codex-portable/codex.runner.mjs`**, `**codex.smoke.mjs**`, exemples `**.env**` / `**.env.codex**`.

## Tests réels (proxy en usage)


| Test                                                                                                        | Résultat | Détail                                                                                                                                                                             |
| ----------------------------------------------------------------------------------------------------------- | -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CODEX_MODEL_COMPLEX=gpt-5.5-high node agents/codex.smoke.mjs`                                              | **OK**   | Extrait « OK »                                                                                                                                                                     |
| `CODEX_MODEL_COMPLEX=gpt-5.5-high CODEX_RAW_PROMPT=1 node agents/codex.runner.mjs MODEL-VALIDATE-GPT55-001` | **OK**   | ~~40 s, **~~10 Ko** de prose Markdown (Fediverse / ActivityPub) dans `missions/MODEL-VALIDATE-GPT55-001/output_codex.json`                                                         |
| `CODEX_MODEL_COMPLEX=gpt-5.5-high CODEX_REASONING_EFFORT=high node agents/codex.runner.mjs PING`            | **OK**   | Sortie `CELUI` (PING)                                                                                                                                                              |
| `npm run codex:smoke` (avec `.env.codex` local fixant `CODEX_MODEL_COMPLEX=gpt-5.5`)                        | **OK**   | **Note** : si le fichier local impose encore `**gpt-5.5`**, le smoke n’utilise pas le nouveau défaut — mettre à jour la ligne en `**gpt-5.5-high**` pour alignement avec le dépôt. |


## Trace rapports

- `EXECUTE_MODEL` : `**gpt-5.5-high`  `gpt-5.5`  `gpt-5.5-pro**`

**Verdict** : `**gpt-5.5-high`** par défaut + **tests réels** (smoke, document long, PING + `reasoning`) **validés** sur l’environnement courant.