---
name: external-tooling-audit
description: Audit third-party MCP servers, browser automation tools, Claude/Codex skills, agent repos, and viral AI tooling suggestions before installing them in FoodKing. Use when a user asks to evaluate, install, compare, or persist MCPs, skills, browser agents, automation stacks, local LLM tools, or external AI agents.
---

# External Tooling Audit

Use this before installing or adding any third-party MCP, skill, browser agent, local model stack, or AI automation repo.

## Workflow

1. Read the FoodKing authority chain first: `AGENTS.md`, `.cursor/ACTIVE_CYCLE.md`, `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`, `docs/orchestration/MEMORY_MATRIX.md`, `.cursor/rules/skills-scoping.mdc`.
2. Inventory the local state without exposing secrets: list MCP server names only, tool versions, existing skills, Docker/Ollama/Node availability, and current repo config.
3. Verify current facts from primary sources: official docs, GitHub repo, package registry, or vendor docs. Do not trust screenshots or social posts as source of truth.
4. Classify each candidate as `ADOPT_NOW`, `KEEP`, `POC_ONLY`, `WATCHLIST`, or `REJECT`.
5. For any install, define the exact write scope before touching files. Product files are out of scope unless a normal FoodKing cycle authorizes them.
6. Persist the audit under `reports/audit/` when the user asks to keep the decision.

## Security Gates

Reject or escalate tools that require:

- anti-bot bypass, captcha bypass, stealth/fingerprint evasion, cookie import, session hijacking, or residential proxy abuse;
- unreviewed `curl | sh`, unpinned remote code execution, or bulk skill marketplace installs;
- secrets committed into repo files;
- broad filesystem, browser profile, email, Drive, GitHub, production DB, or payment access without a scoped task;
- a new memory store outside the four stores in `MEMORY_MATRIX.md`.

Allowed browser automation scope:

- FoodKing localhost and owned staging;
- explicit-permission client systems;
- official APIs and documented automation flows;
- human-supervised research that does not bypass access controls or site restrictions.

## FoodKing Defaults

- Playwright MCP / Playwright CLI are the default for FoodKing E2E QA.
- Browser Use is acceptable for local/authorized inspection when available as a session plugin.
- New MCPs should start as `.cursor/mcp/*.example` plus an audit report, not as enabled production config.
- Community skills may be copied only after reading the source and shrinking them to a local, scoped FoodKing skill.
- Local LLM tools can be used for private scratch work, not as a replacement for `codex-extension` or required Claude/Codex audits.

## Output

Return:

- `verdict`;
- `install_decision`;
- `safe_use_cases`;
- `rejected_use_cases`;
- `local_changes`;
- `sources_checked`;
- `next_safe_poc_step`.

