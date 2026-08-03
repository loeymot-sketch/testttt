# OpenCLI — pas d’entrée `mcp.json`

**OpenCLI** ([jackwener/opencli](https://github.com/jackwener/opencli)) n’expose **pas** de serveur MCP standard pour `~/.cursor/mcp.json`. L’intégration Cursor se fait par :

- **Skill projet** : `.cursor/skills/opencli-browser-automation/SKILL.md`
- **CLI + extension Chrome** : `npm install -g @jackwener/opencli` + extension *Browser Bridge* + `opencli doctor`
- **Skills upstream (optionnel)** : `npx skills add jackwener/opencli`

Rôle proche d’un **outil navigateur pour l’agent** (souvent comparé à Playwright) ; les **E2E Playwright** du dépôt restent gouvernés par `playwright.mdc` et le plan de cycle.
