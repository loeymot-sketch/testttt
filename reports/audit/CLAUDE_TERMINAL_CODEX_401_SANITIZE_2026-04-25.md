# Claude (terminal) — audit ciblé Codex 401 / api.responses.write

Généré: 2026-04-24T20:54:01+02:00

---

## Audit procédural — `api.responses.write` 401 / cohérence docs

---

### 1. L'erreur 401 `api.responses.write` est-elle 100% hors dépôt ?

**OUI — confirmé.**

- Le dépôt ne stocke, n'injecte, ni ne gère aucune clé `sk-`* pour le flux `codex login`.
- `codex-sanitize-env-for-codex-cli.sh` fait explicitement `unset` de toutes les vars à risque (`OPENAI_API_KEY`, `CODEX_API_KEY`, `OPENAI_BASE_URL`, etc.) avant tout `codex exec`.
- `codex-audit-env-bleed.mjs` audite le bleed sans exposer de valeur.
- Les deux documents (`CODEX_API_RESPONSES_401.md`, `CODEX_API_DELEGATION.md`) sont cohérents : cause = clé Platform restreinte **ou** rôle org/projet insuffisant côté `platform.openai.com`, **ou** override Cursor Settings qui injecte une clé incompatible.
- Aucun proxy maison ni runner HTTP résiduel trouvé dans les fichiers lus.

**Périmètre de la 401 : compte OpenAI / rôle org-projet / clé / settings Cursor — entièrement hors git.**

---

### 2. Incohérences doc restantes


| Point                                                                                                                                                       | Verdict                                                                                                                                |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| `docs/orchestration/CODEX_API_DELEGATION.md` mentionne explicitement la suppression du proxy (`codex.runner.mjs`, `CODEX_API_BASE`, `dist/codex-portable/`) | **PASS** — mention **historique explicative**, pas instruction active.                                                                 |
| `agents/codex-extension-instructions.md` L9 : *"Aucun connecteur HTTP proxy+clé n'est maintenu dans le dépôt"*                                              | **PASS** — affirmatif et correct.                                                                                                      |
| `AGENTS.md` L321 + L466 : `"legacy directory name"` pour `reports/antigravity/`                                                                             | **PASS** — le mot *legacy* qualifie ici le **nom du répertoire** (antérieur), non une implémentation trompeuse. Aucune action requise. |
| `docs/operations/CODEX_API_RESPONSES_401.md` — aucune mention proxy, aucun legacy trompeur                                                                  | **PASS**                                                                                                                               |


**Aucune correction doc nécessaire.**

---

### 3. Verdict final

```
NEEDS_FOLLOWUP_HUMAN_OPENAI
```

Le dépôt est propre. Le blocage est côté compte/plateforme OpenAI.

---

### 4. Action unique pour l'humain

**Sur [platform.openai.com](https://platform.openai.com) → `API keys` :**

> Vérifier que la clé utilisée par Cursor / le shell est soit **non restreinte** (*Full access*), soit qu'elle a explicitement le scope `**api.responses.write`** activé — **et** que le compte est associé à un projet/organisation avec rôle **Owner** ou **Writer**. Si une clé restreinte est injectée dans Cursor Settings (*OpenAI* → *API key*), la **retirer** et relancer `codex login` (flux ChatGPT Pro) dans le terminal.

