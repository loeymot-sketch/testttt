---
name: opencli-browser-automation
description: >-
  OpenCLI (@jackwener/opencli) — pilotage du navigateur via Chrome + extension
  (opencli browser *), rôle proche d’un outil d’automatisation type Playwright
  pour l’agent, mais session réelle / connectée. Utiliser pour exploration
  ad-hoc, pages déjà loggées, extraction; pas le remplacement des E2E Playwright
  du dépôt sans plan explicite.
---

# OpenCLI — automateur navigateur pour l’agent (trend, alternative « live »)

## Rôle (vs Playwright du projet)

| | **Playwright (MCP / suite)** | **OpenCLI** |
|---|-----------------------------|------------|
| Usage FoodKing | E2E quand le **plan** déclare `playwright-mcp` / critical-flow | Explorations, repro, sites déjà ouverts, flows **SSO** dans ton Chrome |
| Moteur | Instance Playwright (souvent Chromium dédié) | **Chrome** réel + extension *Browser Bridge* |
| Fichiers | `tests/**`, E2E selon règles | Aucun fichier de test requis; commandes `opencli` |

- **Ne pas** conclure qu’OpenCLI « remplace » les garde-fous E2E du plan — c’est un **autre outil** pour l’agent.

## Prérequis (ordre)

1. **Node** : `node -v` OK.
2. **Install globale** : `npm install -g @jackwener/opencli`
3. **Extension** : [OpenCLI – Chrome Web Store](https://chromewebstore.google.com/detail/opencli/ildkmabpimmkaediidaifkhjpohdnifk) (ou *Load unpacked* depuis [Releases](https://github.com/jackwener/opencli/releases))
4. **Santé** : `opencli doctor` (doit être vert avant toute commande)
5. **Skills officiels (recommandé)** : depuis la racine du dépôt ou n’importe où avec `npx` :

   ```bash
   npx skills add jackwener/opencli
   # ou ciblé : npx skills add jackwener/opencli --skill opencli-browser
   ```

   Cette commande alimente souvent `~/.agents/skills` ; **copie canon** pour commiter ici : synchroniser le skill utile vers `.cursor/skills/` (voir règles *skills-scoping* du dépôt).

## Commandes utiles (rappel)

- `opencli list` — capacités enregistrées
- `opencli doctor` — connectivité Chrome + extension
- `opencli browser state` / `opencli browser click` / `type` / `opencli browser bind --domain example.com` — session agent (détails : voir source upstream)

## Documentation longue (upstream)

Contenu intégral (sélecteurs, enveloppes JSON, `bound:*`, `network`, etc.) :

- [skills/opencli-browser/SKILL.md](https://github.com/jackwener/opencli/blob/main/skills/opencli-browser/SKILL.md)
- Dépôt : [github.com/jackwener/opencli](https://github.com/jackwener/opencli)

## Invariants FoodKing

- **Prix** : l’automatisation ne contredit pas l’invariant *pricing backend SSOT* — OpenCLI sert l’**observabilité** du navigateur, pas de recalculer des totaux côté client.
- **Secrets** : ne pas enregistrer de cookies / tokens d’environnements de prod dans le dépôt.
